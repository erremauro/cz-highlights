<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}czh_highlights" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}czh_post_notes" );

delete_option( 'czh_db_version' );
delete_option( 'czh_notes_page_id' );
