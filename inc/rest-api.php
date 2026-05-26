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
		return rest_ensure_response( array_map( [ __CLASS__, 'prepare_highlight' ], $rows ) );
	}

	public static function create_highlight( WP_REST_Request $req ) {
		$user_id = get_current_user_id();
		$post_id = (int) $req->get_param( 'post_id' );

		if ( ! self::post_is_readable( $post_id ) ) {
			return new WP_Error( 'czh_not_found', 'Post not found', [ 'status' => 404 ] );
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
			$data['status'] = $body['status'];
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
		$table       = CZH_DB::table_name();
		$items_table = $wpdb->prefix . 'cz_volume_items';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$items_table}'" ) !== $items_table ) {
			return rest_ensure_response( [] );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT vi.volume_id, COUNT(h.id) AS cnt
				 FROM {$table} h
				 JOIN {$items_table} vi ON vi.post_id = h.post_id
				 WHERE h.user_id = %d
				 GROUP BY vi.volume_id
				 ORDER BY cnt DESC",
				$user_id
			)
		);

		$out = [];
		foreach ( $rows as $row ) {
			$vid    = (int) $row->volume_id;
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
				'count'       => (int) $row->cnt,
			];
		}

		// Prepend standalone card if any highlights exist outside volumes
		$standalone_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(h.id) FROM {$table} h
				 WHERE h.user_id = %d
				 AND h.post_id NOT IN ( SELECT post_id FROM {$items_table} )",
				$user_id
			)
		);

		if ( $standalone_count > 0 ) {
			array_unshift( $out, [
				'volume_id'   => 0,
				'title'       => null,
				'permalink'   => null,
				'author_name' => null,
				'count'       => $standalone_count,
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

	// ---- Helpers ----

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
