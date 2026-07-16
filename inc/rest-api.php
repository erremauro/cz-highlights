<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CZH_REST {

	const NAMESPACE = 'czh/v1';

	public static function register_routes() {
		// GET  /highlights?post_id=X       → list highlights for post
		// POST /highlights                 → create
		register_rest_route( self::NAMESPACE, '/highlights', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_highlights' ],
				'permission_callback' => [ __CLASS__, 'require_login' ],
				'args'                => [
					'post_id' => [
						'required'          => true,
						'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
				],
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'create_highlight' ],
				'permission_callback' => [ __CLASS__, 'require_login' ],
				'args'                => [
					'post_id'       => [ 'required' => true, 'sanitize_callback' => 'absint' ],
					'selected_text' => [ 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ],
					'prefix_text'   => [ 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ],
					'suffix_text'   => [ 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ],
					'note'          => [ 'default' => null ],
					'color'         => [ 'default' => 'yellow', 'sanitize_callback' => 'sanitize_text_field' ],
					'page_num'      => [ 'default' => 1, 'sanitize_callback' => 'absint' ],
				],
			],
		] );

		// PATCH  /highlights/{id}   → update note/color
		// DELETE /highlights/{id}   → delete
		register_rest_route( self::NAMESPACE, '/highlights/(?P<id>\d+)', [
			[
				'methods'             => 'PATCH',
				'callback'            => [ __CLASS__, 'update_highlight' ],
				'permission_callback' => [ __CLASS__, 'require_login' ],
			],
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ __CLASS__, 'delete_highlight' ],
				'permission_callback' => [ __CLASS__, 'require_login' ],
			],
		] );

		// GET /highlights/summary → volumes with highlight counts for the current user
		register_rest_route( self::NAMESPACE, '/highlights/summary', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_summary' ],
			'permission_callback' => [ __CLASS__, 'require_login' ],
		] );

		// GET /highlights/standalone → highlights on posts not belonging to any volume
		register_rest_route( self::NAMESPACE, '/highlights/standalone', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_standalone_highlights' ],
			'permission_callback' => [ __CLASS__, 'require_login' ],
		] );

		// GET /highlights/volume?volume_id=X
		register_rest_route( self::NAMESPACE, '/highlights/volume', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_volume_highlights' ],
			'permission_callback' => [ __CLASS__, 'require_login' ],
			'args'                => [
				'volume_id' => [
					'required'          => true,
					'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		// GET  /post-note?post_id=X   → get article note for current user
		// POST /post-note              → upsert (body: {post_id, note})
		register_rest_route( self::NAMESPACE, '/post-note', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_post_note' ],
				'permission_callback' => [ __CLASS__, 'require_login' ],
				'args'                => [
					'post_id' => [
						'required'          => true,
						'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
						'sanitize_callback' => 'absint',
					],
				],
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'upsert_post_note' ],
				'permission_callback' => [ __CLASS__, 'require_login' ],
				'args'                => [
					'post_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
					'note'    => [ 'required' => true ],
				],
			],
		] );

		// DELETE /post-note/{post_id}
		register_rest_route( self::NAMESPACE, '/post-note/(?P<post_id>\d+)', [
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => [ __CLASS__, 'delete_post_note' ],
			'permission_callback' => [ __CLASS__, 'require_login' ],
		] );

		// GET /post-notes/volume?volume_id=X → all article notes for a volume's posts
		register_rest_route( self::NAMESPACE, '/post-notes/volume', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_post_notes_for_volume' ],
			'permission_callback' => [ __CLASS__, 'require_login' ],
			'args'                => [
				'volume_id' => [
					'required'          => true,
					'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		// GET /post-notes/standalone → article notes for posts not in any volume
		register_rest_route( self::NAMESPACE, '/post-notes/standalone', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_post_notes_standalone' ],
			'permission_callback' => [ __CLASS__, 'require_login' ],
		] );

		// GET /post-notes?post_ids=1,2,3  → bulk get (kept for legacy use)
		register_rest_route( self::NAMESPACE, '/post-notes', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_post_notes_bulk' ],
			'permission_callback' => [ __CLASS__, 'require_login' ],
		] );
	}

	public static function require_login() {
		return is_user_logged_in();
	}

	// ---- Handlers ----

	public static function get_highlights( WP_REST_Request $req ) {
		$user_id = get_current_user_id();
		$post_id = (int) $req->get_param( 'post_id' );

		if ( ! self::post_is_readable( $post_id ) ) {
			return new WP_Error( 'czh_not_found', 'Post not found', [ 'status' => 404 ] );
		}

		$rows = CZH_DB::get_by_post( $user_id, $post_id );
		$rows = self::sort_by_position( $rows );
		return rest_ensure_response( array_map( [ __CLASS__, 'prepare_highlight' ], $rows ) );
	}

	public static function create_highlight( WP_REST_Request $req ) {
		$user_id = get_current_user_id();
		$post_id = (int) $req->get_param( 'post_id' );

		if ( ! self::post_is_readable( $post_id ) ) {
			return new WP_Error( 'czh_not_found', 'Post not found', [ 'status' => 404 ] );
		}

		$count = CZH_DB::count_by_user_post( $user_id, $post_id );
		if ( $count >= 200 ) {
			return new WP_Error( 'czh_limit_exceeded', 'Highlight limit reached for this post (max 200)', [ 'status' => 429 ] );
		}

		$selected = (string) $req->get_param( 'selected_text' );
		if ( mb_strlen( $selected ) < 1 || mb_strlen( $selected ) > 5000 ) {
			return new WP_Error( 'czh_invalid_text', 'selected_text must be 1–5000 chars', [ 'status' => 400 ] );
		}

		$color   = (string) $req->get_param( 'color' );
		$allowed = [ 'yellow', 'green', 'blue', 'pink', 'orange' ];
		if ( ! in_array( $color, $allowed, true ) ) {
			$color = 'yellow';
		}

		$row = CZH_DB::create( $user_id, $post_id, [
			'selected_text' => $selected,
			'prefix_text'   => substr( sanitize_textarea_field( (string) $req->get_param( 'prefix_text' ) ), 0, 200 ),
			'suffix_text'   => substr( sanitize_textarea_field( (string) $req->get_param( 'suffix_text' ) ), 0, 200 ),
			'note'          => $req->get_param( 'note' ),
			'color'         => $color,
			'page_num'      => max( 1, (int) $req->get_param( 'page_num' ) ),
		] );

		if ( ! $row ) {
			return new WP_Error( 'czh_db_error', 'Could not save highlight', [ 'status' => 500 ] );
		}

		return rest_ensure_response( self::prepare_highlight( $row ) );
	}

	public static function update_highlight( WP_REST_Request $req ) {
		$user_id = get_current_user_id();
		$id      = (int) $req->get_param( 'id' );

		$existing = CZH_DB::get_by_id( $id );
		if ( ! $existing || (int) $existing['user_id'] !== $user_id ) {
			return new WP_Error( 'czh_not_found', 'Highlight not found', [ 'status' => 404 ] );
		}

		$body = $req->get_json_params();
		$data = [];

		if ( isset( $body['note'] ) ) {
			$data['note'] = $body['note'];
		}
		if ( isset( $body['color'] ) ) {
			$allowed = [ 'yellow', 'green', 'blue', 'pink', 'orange' ];
			$data['color'] = in_array( $body['color'], $allowed, true ) ? $body['color'] : $existing['color'];
		}
		if ( isset( $body['status'] ) ) {
			$allowed_statuses = [ 'active', 'displaced', 'orphaned' ];
			if ( in_array( $body['status'], $allowed_statuses, true ) ) {
				$data['status'] = $body['status'];
			}
		}

		$row = CZH_DB::update( $id, $user_id, $data );
		return rest_ensure_response( self::prepare_highlight( $row ) );
	}

	public static function delete_highlight( WP_REST_Request $req ) {
		$user_id = get_current_user_id();
		$id      = (int) $req->get_param( 'id' );

		$existing = CZH_DB::get_by_id( $id );
		if ( ! $existing || (int) $existing['user_id'] !== $user_id ) {
			return new WP_Error( 'czh_not_found', 'Highlight not found', [ 'status' => 404 ] );
		}

		CZH_DB::delete( $id, $user_id );
		return rest_ensure_response( [ 'deleted' => true, 'id' => $id ] );
	}

	public static function get_summary( WP_REST_Request $req ) {
		global $wpdb;

		$user_id     = get_current_user_id();
		$hl_table    = CZH_DB::table_name();
		$pn_table    = CZH_PostNotes_DB::table_name();
		$items_table = $wpdb->prefix . 'cz_volume_items';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$items_table}'" ) !== $items_table ) {
			return rest_ensure_response( [] );
		}

		// Highlights per volume
		$hl_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT vi.volume_id, COUNT(h.id) AS cnt
				 FROM {$hl_table} h
				 JOIN {$items_table} vi ON vi.post_id = h.post_id
				 WHERE h.user_id = %d
				 GROUP BY vi.volume_id",
				$user_id
			)
		);

		// Article notes per volume
		$pn_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT vi.volume_id, COUNT(pn.id) AS cnt
				 FROM {$pn_table} pn
				 JOIN {$items_table} vi ON vi.post_id = pn.post_id
				 WHERE pn.user_id = %d
				 GROUP BY vi.volume_id",
				$user_id
			)
		);

		// Merge counts by volume_id
		$volumes = [];
		foreach ( $hl_rows as $row ) {
			$vid = (int) $row->volume_id;
			$volumes[ $vid ] = ( $volumes[ $vid ] ?? 0 ) + (int) $row->cnt;
		}
		foreach ( $pn_rows as $row ) {
			$vid = (int) $row->volume_id;
			$volumes[ $vid ] = ( $volumes[ $vid ] ?? 0 ) + (int) $row->cnt;
		}

		$out = [];
		foreach ( $volumes as $vid => $count ) {
			$volume = get_post( $vid );
			if ( ! $volume || 'volume' !== $volume->post_type ) {
				continue;
			}
			if ( 'publish' !== $volume->post_status && ! current_user_can( 'edit_post', $vid ) ) {
				continue;
			}
			$author_id   = (int) $volume->post_author;
			$author_name = $author_id ? get_the_author_meta( 'display_name', $author_id ) : '';

			$out[] = [
				'volume_id'   => $vid,
				'title'       => get_the_title( $volume ),
				'permalink'   => get_permalink( $volume ),
				'author_name' => $author_name,
				'count'       => $count,
			];
		}

		// Sort by count descending
		usort( $out, fn( $a, $b ) => $b['count'] - $a['count'] );

		// Standalone: highlights + article notes on posts outside any volume
		$hl_standalone = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(h.id) FROM {$hl_table} h
				 WHERE h.user_id = %d AND h.post_id NOT IN (SELECT post_id FROM {$items_table})",
				$user_id
			)
		);
		$pn_standalone = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(pn.id) FROM {$pn_table} pn
				 WHERE pn.user_id = %d AND pn.post_id NOT IN (SELECT post_id FROM {$items_table})",
				$user_id
			)
		);

		if ( $hl_standalone + $pn_standalone > 0 ) {
			array_unshift( $out, [
				'volume_id'   => 0,
				'title'       => null,
				'permalink'   => null,
				'author_name' => null,
				'count'       => $hl_standalone + $pn_standalone,
				'standalone'  => true,
			] );
		}

		return rest_ensure_response( $out );
	}

	public static function get_standalone_highlights( WP_REST_Request $req ) {
		global $wpdb;

		$user_id     = get_current_user_id();
		$table       = CZH_DB::table_name();
		$items_table = $wpdb->prefix . 'cz_volume_items';

		$items_table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$items_table}'" ) === $items_table;

		if ( $items_table_exists ) {
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table}
					 WHERE user_id = %d
					 AND post_id NOT IN ( SELECT post_id FROM {$items_table} )
					 ORDER BY post_id ASC, created_at ASC",
					$user_id
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE user_id = %d ORDER BY post_id ASC, created_at ASC",
					$user_id
				),
				ARRAY_A
			);
		}

		$rows = self::sort_by_position( $rows );

		$out             = [];
		$post_meta_cache = [];

		foreach ( $rows as $row ) {
			$pid = (int) $row['post_id'];

			if ( ! isset( $post_meta_cache[ $pid ] ) ) {
				$p = get_post( $pid );
				$post_meta_cache[ $pid ] = $p ? [
					'title'     => get_the_title( $p ),
					'permalink' => get_permalink( $p ),
				] : null;
			}

			$prepared         = self::prepare_highlight( $row );
			$prepared['post'] = $post_meta_cache[ $pid ];
			$out[]            = $prepared;
		}

		return rest_ensure_response( $out );
	}

	public static function get_volume_highlights( WP_REST_Request $req ) {
		global $wpdb;

		$user_id   = get_current_user_id();
		$volume_id = (int) $req->get_param( 'volume_id' );

		// Verify volume exists and is accessible to the current user.
		// Admins/editors can see their highlights on draft volumes too.
		$volume = get_post( $volume_id );
		if ( ! $volume || 'volume' !== $volume->post_type ) {
			return new WP_Error( 'czh_not_found', 'Volume not found', [ 'status' => 404 ] );
		}
		if ( 'publish' !== $volume->post_status && ! current_user_can( 'edit_post', $volume_id ) ) {
			return new WP_Error( 'czh_not_found', 'Volume not found', [ 'status' => 404 ] );
		}

		// Get all post IDs belonging to this volume via cz-volume table
		$items_table = $wpdb->prefix . 'cz_volume_items';
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$items_table} WHERE volume_id = %d",
				$volume_id
			)
		);

		if ( empty( $post_ids ) ) {
			return rest_ensure_response( [] );
		}

		$post_ids = array_map( 'intval', $post_ids );
		$rows     = CZH_DB::get_by_posts( $user_id, $post_ids );
		$rows     = self::sort_by_position( $rows );

		// Group by post_id and attach post title/permalink for context
		$out = [];
		$post_meta_cache = [];

		foreach ( $rows as $row ) {
			$pid = (int) $row['post_id'];

			if ( ! isset( $post_meta_cache[ $pid ] ) ) {
				$p = get_post( $pid );
				$post_meta_cache[ $pid ] = $p ? [
					'title'     => get_the_title( $p ),
					'permalink' => get_permalink( $p ),
				] : null;
			}

			$prepared            = self::prepare_highlight( $row );
			$prepared['post']    = $post_meta_cache[ $pid ];
			$out[]               = $prepared;
		}

		return rest_ensure_response( $out );
	}

	// ---- Post-note handlers ----

	public static function get_post_note( WP_REST_Request $req ) {
		$user_id = get_current_user_id();
		$post_id = (int) $req->get_param( 'post_id' );
		if ( ! self::post_is_readable( $post_id ) ) {
			return new WP_Error( 'czh_not_found', 'Post not found', [ 'status' => 404 ] );
		}
		$row = CZH_PostNotes_DB::get( $user_id, $post_id );
		return rest_ensure_response( CZH_PostNotes_DB::prepare_row( $row ) );
	}

	public static function upsert_post_note( WP_REST_Request $req ) {
		$user_id = get_current_user_id();
		$post_id = (int) $req->get_param( 'post_id' );
		if ( ! self::post_is_readable( $post_id ) ) {
			return new WP_Error( 'czh_not_found', 'Post not found', [ 'status' => 404 ] );
		}
		$note = trim( (string) $req->get_param( 'note' ) );
		if ( mb_strlen( $note ) === 0 ) {
			return new WP_Error( 'czh_invalid', 'Note cannot be empty', [ 'status' => 400 ] );
		}
		$row = CZH_PostNotes_DB::upsert( $user_id, $post_id, $note );
		return rest_ensure_response( CZH_PostNotes_DB::prepare_row( $row ) );
	}

	public static function delete_post_note( WP_REST_Request $req ) {
		$user_id = get_current_user_id();
		$post_id = (int) $req->get_param( 'post_id' );
		CZH_PostNotes_DB::delete( $user_id, $post_id );
		return rest_ensure_response( [ 'deleted' => true, 'post_id' => $post_id ] );
	}

	public static function get_post_notes_for_volume( WP_REST_Request $req ) {
		global $wpdb;

		$user_id   = get_current_user_id();
		$volume_id = (int) $req->get_param( 'volume_id' );

		$volume = get_post( $volume_id );
		if ( ! $volume || 'volume' !== $volume->post_type ) {
			return new WP_Error( 'czh_not_found', 'Volume not found', [ 'status' => 404 ] );
		}
		if ( 'publish' !== $volume->post_status && ! current_user_can( 'edit_post', $volume_id ) ) {
			return new WP_Error( 'czh_not_found', 'Volume not found', [ 'status' => 404 ] );
		}

		$items_table = $wpdb->prefix . 'cz_volume_items';
		$post_ids    = $wpdb->get_col(
			$wpdb->prepare( "SELECT post_id FROM {$items_table} WHERE volume_id = %d", $volume_id )
		);

		if ( empty( $post_ids ) ) {
			return rest_ensure_response( [] );
		}

		$post_ids = array_map( 'intval', $post_ids );
		$rows     = CZH_PostNotes_DB::get_by_posts( $user_id, $post_ids );

		$out = [];
		foreach ( $rows as $row ) {
			$prepared = CZH_PostNotes_DB::prepare_row( $row );
			$p        = get_post( $prepared['post_id'] );
			$prepared['post_title']     = $p ? get_the_title( $p ) : '';
			$prepared['post_permalink'] = $p ? get_permalink( $p ) : '';
			$out[] = $prepared;
		}

		return rest_ensure_response( $out );
	}

	public static function get_post_notes_standalone( WP_REST_Request $req ) {
		global $wpdb;

		$user_id     = get_current_user_id();
		$pn_table    = CZH_PostNotes_DB::table_name();
		$items_table = $wpdb->prefix . 'cz_volume_items';

		$items_table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$items_table}'" ) === $items_table;

		if ( $items_table_exists ) {
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$pn_table} WHERE user_id = %d AND post_id NOT IN (SELECT post_id FROM {$items_table}) ORDER BY post_id ASC",
					$user_id
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$pn_table} WHERE user_id = %d ORDER BY post_id ASC",
					$user_id
				),
				ARRAY_A
			);
		}

		$out = [];
		foreach ( $rows as $row ) {
			$prepared = CZH_PostNotes_DB::prepare_row( $row );
			$p        = get_post( $prepared['post_id'] );
			$prepared['post_title']     = $p ? get_the_title( $p ) : '';
			$prepared['post_permalink'] = $p ? get_permalink( $p ) : '';
			$out[] = $prepared;
		}

		return rest_ensure_response( $out );
	}

	public static function get_post_notes_bulk( WP_REST_Request $req ) {
		$user_id      = get_current_user_id();
		$post_ids_raw = $req->get_param( 'post_ids' );
		if ( ! $post_ids_raw ) {
			return rest_ensure_response( [] );
		}
		$post_ids = array_values( array_filter( array_map( 'absint', explode( ',', $post_ids_raw ) ) ) );
		if ( empty( $post_ids ) ) {
			return rest_ensure_response( [] );
		}
		$rows = CZH_PostNotes_DB::get_by_posts( $user_id, $post_ids );
		return rest_ensure_response( array_values( array_map( [ 'CZH_PostNotes_DB', 'prepare_row' ], $rows ) ) );
	}

	// ---- Helpers ----

	/**
	 * Reorders highlight rows by their position in the post text (instead of
	 * creation date). Rows are grouped by post_id (grouping order preserved),
	 * the post's plain text is extracted once per group, and each row's
	 * character offset is located within it. Rows whose text can no longer be
	 * found (e.g. orphaned) keep their original relative order at the end of
	 * their group.
	 */
	private static function sort_by_position( array $rows ) : array {
		if ( count( $rows ) < 2 ) {
			return $rows;
		}

		$groups      = [];
		$group_order = [];
		foreach ( $rows as $row ) {
			$pid = (int) $row['post_id'];
			if ( ! isset( $groups[ $pid ] ) ) {
				$groups[ $pid ] = [];
				$group_order[]  = $pid;
			}
			$groups[ $pid ][] = $row;
		}

		$out = [];
		foreach ( $group_order as $pid ) {
			$group = $groups[ $pid ];

			if ( count( $group ) > 1 ) {
				$post = get_post( $pid );
				$text = $post ? CZH_Post_Watcher::extract_text( $post ) : '';

				foreach ( $group as $i => &$row ) {
					$row['_czh_pos']   = '' !== $text ? CZH_Post_Watcher::locate_offset( $row, $text ) : null;
					$row['_czh_index'] = $i;
				}
				unset( $row );

				usort( $group, function ( $a, $b ) {
					$pa = $a['_czh_pos'];
					$pb = $b['_czh_pos'];

					if ( null === $pa && null === $pb ) {
						return $a['_czh_index'] <=> $b['_czh_index'];
					}
					if ( null === $pa ) {
						return 1;
					}
					if ( null === $pb ) {
						return -1;
					}
					if ( $pa === $pb ) {
						return $a['_czh_index'] <=> $b['_czh_index'];
					}
					return $pa <=> $pb;
				} );

				foreach ( $group as &$row ) {
					unset( $row['_czh_pos'], $row['_czh_index'] );
				}
				unset( $row );
			}

			foreach ( $group as $row ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	private static function post_is_readable( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, [ 'post', 'volume' ], true ) ) {
			return false;
		}
		if ( 'publish' === $post->post_status ) {
			return true;
		}
		return current_user_can( 'edit_post', $post_id );
	}

	private static function prepare_highlight( $row ) {
		if ( ! $row ) {
			return null;
		}
		return [
			'id'            => (int) $row['id'],
			'user_id'       => (int) $row['user_id'],
			'post_id'       => (int) $row['post_id'],
			'page_num'      => isset( $row['page_num'] ) ? max( 1, (int) $row['page_num'] ) : 1,
			'selected_text' => (string) $row['selected_text'],
			'prefix_text'   => (string) $row['prefix_text'],
			'suffix_text'   => (string) $row['suffix_text'],
			'note'          => isset( $row['note'] ) ? (string) $row['note'] : null,
			'color'         => (string) $row['color'],
			'status'        => (string) $row['status'],
			'created_at'    => (string) $row['created_at'],
			'updated_at'    => (string) $row['updated_at'],
		];
	}
}
