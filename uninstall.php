<?php
/**
 * Remove all plugin data when the plugin is deleted from the Plugins screen.
 *
 * @package RivalPriceWatch
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$rpw_table = $wpdb->prefix . 'rpw_prices';
$wpdb->query( "DROP TABLE IF EXISTS {$rpw_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

foreach ( array( 'rpw_settings', 'rpw_db_version', 'rpw_queue', 'rpw_pending_alerts', 'rpw_last_run_started', 'rpw_last_run_finished' ) as $rpw_option ) {
	delete_option( $rpw_option );
}
delete_transient( 'rpw_lock' );

delete_post_meta_by_key( '_rpw_links' );
delete_post_meta_by_key( '_rpw_alerted' );

wp_clear_scheduled_hook( 'rpw_run_checks' );
wp_clear_scheduled_hook( 'rpw_continue_checks' );
