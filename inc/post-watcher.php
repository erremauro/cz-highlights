<?php
/**
 * Watches post saves and re-matches highlights against the updated content.
 * Updates highlight status: active → displaced → orphaned.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CZH_Post_Watcher {

	public static function register() {
		// post_updated fires after a post is saved with (post_ID, $post_after, $post_before)
		add_action( 'post_updated', [ __CLASS__, 'on_post_updated' ], 10, 3 );
	}

	/**
	 * Called whenever a post is saved. Re-matches all highlights for this post.
	 */
	public static function on_post_updated( $post_id, WP_Post $post_after, WP_Post $post_before ) {
		// Only watch regular posts
		if ( 'post' !== $post_after->post_type ) {
			return;
		}

		// Only when content actually changed
		if ( $post_after->post_content === $post_before->post_content ) {
			return;
		}

		// Extract clean text from the updated post
		$text = self::extract_text( $post_after );
		if ( '' === $text ) {
			return;
		}

		global $wpdb;
		$table = CZH_DB::table_name();

		// Fetch all highlights for this post (all users)
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, selected_text, prefix_text, suffix_text, status FROM {$table} WHERE post_id = %d AND status != 'orphaned'",
				(int) $post_id
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$new_status = self::compute_status( $row, $text );

			if ( $new_status !== $row['status'] ) {
				$wpdb->update(
					$table,
					[
						'status'     => $new_status,
						'updated_at' => current_time( 'mysql', true ),
					],
					[ 'id' => (int) $row['id'] ],
					[ '%s', '%s' ],
					[ '%d' ]
				);
			}
		}
	}

	/**
	 * Determine the new status for a highlight given the current post text.
	 *
	 * Both the stored fingerprint and the extracted text are normalised
	 * (whitespace collapsed to single spaces) before matching, so the
	 * comparison is whitespace-insensitive — this handles data stored before
	 * the sanitize_text_field → sanitize_textarea_field fix.
	 *
	 * @param array  $row  DB row with selected_text, prefix_text, suffix_text
	 * @param string $text Full plain text of the post (already WS-normalised)
	 * @return string 'active' | 'displaced' | 'orphaned'
	 */
	private static function compute_status( array $row, string $text ) : string {
		$selected = self::norm_ws( (string) $row['selected_text'] );
		$prefix   = self::norm_ws( (string) $row['prefix_text'] );
		$suffix   = self::norm_ws( (string) $row['suffix_text'] );

		if ( '' === $selected ) {
			return 'orphaned';
		}

		// Full-context match → active
		if ( '' !== $prefix || '' !== $suffix ) {
			$needle = trim( $prefix . ' ' . $selected . ' ' . $suffix );
			if ( str_contains( $text, $needle ) ) {
				return 'active';
			}
		}

		// Text-only match → displaced
		if ( str_contains( $text, $selected ) ) {
			return 'displaced';
		}

		return 'orphaned';
	}

	private static function norm_ws( string $s ) : string {
		return trim( preg_replace( '/\s+/u', ' ', $s ) );
	}

	/**
	 * Locate a highlight's character offset within the post's plain text, using
	 * the same prefix/selected/suffix matching as compute_status(). Used to sort
	 * highlights by their position in the text instead of by creation date.
	 *
	 * @return int|null Offset, or null if the highlight can't be located.
	 */
	public static function locate_offset( array $row, string $text ) : ?int {
		$selected = self::norm_ws( (string) ( $row['selected_text'] ?? '' ) );
		$prefix   = self::norm_ws( (string) ( $row['prefix_text'] ?? '' ) );
		$suffix   = self::norm_ws( (string) ( $row['suffix_text'] ?? '' ) );

		if ( '' === $selected ) {
			return null;
		}

		if ( '' !== $prefix || '' !== $suffix ) {
			$needle = trim( $prefix . ' ' . $selected . ' ' . $suffix );
			$pos    = strpos( $text, $needle );
			if ( false !== $pos ) {
				return $pos + strlen( $prefix ) + ( '' !== $prefix ? 1 : 0 );
			}
		}

		$pos = strpos( $text, $selected );
		return false !== $pos ? $pos : null;
	}

	/**
	 * Extract plain text from a WP_Post, approximating what the browser sees.
	 *
	 * For multi-page posts (<!--nextpage-->) we join all pages so fingerprints
	 * captured anywhere in the article can be matched.
	 */
	public static function extract_text( WP_Post $post ) : string {
		$content = $post->post_content;

		// Join paginated content
		$content = str_replace( '<!--nextpage-->', ' ', $content );

		// Run standard WordPress content filters (applies autop, shortcodes, blocks, etc.)
		// We run this without setting up the global $post to avoid side effects.
		$html = apply_filters( 'the_content', $content );

		// Strip all HTML tags
		$text = wp_strip_all_tags( $html );

		// Decode HTML entities to match what the browser's textContent returns
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Normalize whitespace: collapse runs of whitespace to single spaces,
		// trim leading/trailing. The browser's TreeWalker concatenates text nodes
		// without inserting extra spaces, so this is intentionally light-touch.
		$text = preg_replace( '/\s+/', ' ', $text );

		return trim( $text );
	}
}

CZH_Post_Watcher::register();
