<?php
/**
 * Usuwanie danych wtyczki.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cf7_ecr_days" );
delete_option( 'cf7_ecr_capacity' );
delete_option( 'cf7_ecr_db_version' );
