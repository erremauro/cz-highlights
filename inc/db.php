<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CZH_DB {

	const TABLE_HIGHLIGHTS = 'czh_highlights';

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_HIGHLIGHTS;
	}

	public static function create_table() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id      BIGINT UNSIGNED NOT NULL,
			post_id      BIGINT UNSIGNED NOT NULL,
			page_num     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			selected_text TEXT NOT NULL,
			prefix_text  VARCHAR(200) NOT NULL DEFAULT '',
			suffix_text  VARCHAR(200) NOT NULL DEFAULT '',
			note         TEXT,
			color        VARCHAR(30) NOT NULL DEFAULT 'yellow',
			status       VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_user_post (user_id, post_id),
			KEY idx_user (user_id),
			KEY idx_post (post_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Returns the number of active highlights for a user+post.
	 */
	public static function count_by_user_post( $user_id, $post_id ) {
		global $wpdb;
		$table = self::table_name();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND post_id = %d AND status != 'orphaned'",
				(int) $user_id,
				(int) $post_id
			)
		);
	}

	/**
	 * Returns highlights for a user+post, ordered by creation date.
	 */
	public static function get_by_post( $user_id, $post_id ) {
		global $wpdb;
		$table = self::table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND post_id = %d ORDER BY created_at ASC",
				(int) $user_id,
				(int) $post_id
			),
			ARRAY_A
		);
	}

	/**
	 * Returns all highlights for a user across a set of post IDs (for volume view).
	 *
	 * @param int   $user_id
	 * @param int[] $post_ids
	 */
	public static function get_by_posts( $user_id, array $post_ids ) {
		global $wpdb;

		if ( empty( $post_ids ) ) {
			return [];
		}

		$table       = self::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$values      = array_merge( [ (int) $user_id ], array_map( 'intval', $post_ids ) );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND post_id IN ({$placeholders}) ORDER BY post_id ASC, created_at ASC",
				...$values
			),
			ARRAY_A
		);
	}

	public static function create( $user_id, $post_id, $data ) {
		global $wpdb;
		$table = self::table_name();

		$wpdb->insert(
			$table,
			[
				'user_id'       => (int) $user_id,
				'post_id'       => (int) $post_id,
				'page_num'      => isset( $data['page_num'] ) ? max( 1, (int) $data['page_num'] ) : 1,
				'selected_text' => sanitize_textarea_field( $data['selected_text'] ),
				'prefix_text'   => sanitize_textarea_field( substr( $data['prefix_text'] ?? '', 0, 200 ) ),
				'suffix_text'   => sanitize_textarea_field( substr( $data['suffix_text'] ?? '', 0, 200 ) ),
				'note'          => isset( $data['note'] ) ? sanitize_textarea_field( $data['note'] ) : null,
				'color'         => sanitize_text_field( $data['color'] ?? 'yellow' ),
				'status'        => 'active',
				'created_at'    => current_time( 'mysql', true ),
				'updated_at'    => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( ! $wpdb->insert_id ) {
			return null;
		}

		return self::get_by_id( $wpdb->insert_id );
	}

	public static function update( $id, $user_id, $data ) {
		global $wpdb;
		$table = self::table_name();

		$fields = [];
		$formats = [];

		if ( isset( $data['note'] ) ) {
			$fields['note']   = sanitize_textarea_field( $data['note'] );
			$formats[]        = '%s';
		}
		if ( isset( $data['color'] ) ) {
			$fields['color']  = sanitize_text_field( $data['color'] );
			$formats[]        = '%s';
		}
		if ( isset( $data['status'] ) ) {
			$allowed = [ 'active', 'displaced', 'orphaned' ];
			if ( in_array( $data['status'], $allowed, true ) ) {
				$fields['status'] = $data['status'];
				$formats[]        = '%s';
			}
		}

		if ( empty( $fields ) ) {
			return self::get_by_id( $id );
		}

		$fields['updated_at'] = current_time( 'mysql', true );
		$formats[]             = '%s';

		$wpdb->update(
			$table,
			$fields,
			[ 'id' => (int) $id, 'user_id' => (int) $user_id ],
			$formats,
			[ '%d', '%d' ]
		);

		return self::get_by_id( $id );
	}

	public static function delete( $id, $user_id ) {
		global $wpdb;
		$table = self::table_name();

		return (bool) $wpdb->delete(
			$table,
			[ 'id' => (int) $id, 'user_id' => (int) $user_id ],
			[ '%d', '%d' ]
		);
	}

	public static function get_by_id( $id ) {
		global $wpdb;
		$table = self::table_name();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ),
			ARRAY_A
		);
	}
}

class CZH_PostNotes_DB {

	const TABLE = 'czh_post_notes';

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function create_table() {
		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$sql = "CREATE TABLE {$table} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id    BIGINT UNSIGNED NOT NULL,
			post_id    BIGINT UNSIGNED NOT NULL,
			note       TEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idx_user_post (user_id, post_id)
		) {$charset_collate};";
		dbDelta( $sql );
	}

	public static function get( $user_id, $post_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE user_id = %d AND post_id = %d',
				(int) $user_id,
				(int) $post_id
			),
			ARRAY_A
		);
	}

	public static function get_by_posts( $user_id, array $post_ids ) {
		global $wpdb;
		if ( empty( $post_ids ) ) {
			return [];
		}
		$table        = self::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$values       = array_merge( [ (int) $user_id ], array_map( 'intval', $post_ids ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND post_id IN ({$placeholders})",
				...$values
			),
			ARRAY_A
		);
	}

	public static function upsert( $user_id, $post_id, $note ) {
		global $wpdb;
		$table    = self::table_name();
		$existing = self::get( $user_id, $post_id );
		$now      = current_time( 'mysql', true );
		$note_val = sanitize_textarea_field( $note );

		if ( $existing ) {
			$wpdb->update(
				$table,
				[ 'note' => $note_val, 'updated_at' => $now ],
				[ 'user_id' => (int) $user_id, 'post_id' => (int) $post_id ],
				[ '%s', '%s' ],
				[ '%d', '%d' ]
			);
		} else {
			$wpdb->insert(
				$table,
				[
					'user_id'    => (int) $user_id,
					'post_id'    => (int) $post_id,
					'note'       => $note_val,
					'created_at' => $now,
					'updated_at' => $now,
				],
				[ '%d', '%d', '%s', '%s', '%s' ]
			);
		}
		return self::get( $user_id, $post_id );
	}

	public static function delete( $user_id, $post_id ) {
		global $wpdb;
		return (bool) $wpdb->delete(
			self::table_name(),
			[ 'user_id' => (int) $user_id, 'post_id' => (int) $post_id ],
			[ '%d', '%d' ]
		);
	}

	public static function prepare_row( $row ) {
		if ( ! $row ) {
			return null;
		}
		return [
			'id'         => (int) $row['id'],
			'user_id'    => (int) $row['user_id'],
			'post_id'    => (int) $row['post_id'],
			'note'       => (string) $row['note'],
			'created_at' => (string) $row['created_at'],
			'updated_at' => (string) $row['updated_at'],
		];
	}
}
