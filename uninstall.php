<?php
/**
 * Remove all plugin data when the plugin is deleted from the Plugins screen.
 *
 * @package RivalPriceWatch
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'rpw_prices', 'rpw_jobs' ) as $rpw_name ) {
	$rpw_table = $wpdb->prefix . $rpw_name;
	$wpdb->query( "DROP TABLE IF EXISTS {$rpw_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$rpw_options = array(
	'rpw_settings',
	'rpw_rates',
	'rpw_db_version',
	'rpw_queue',
	'rpw_pending_alerts',
	'rpw_alert_retries',
	'rpw_alert_error',
	'rpw_last_run_started',
	'rpw_last_run_finished',
	'rpw_lock',
	'rpw_mail_lock',
);
foreach ( $rpw_options as $rpw_option ) {
	delete_option( $rpw_option );
}
// The locks are written straight to the options table, so remove them the same way.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name IN ('rpw_lock','rpw_mail_lock')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_transient( 'rpw_lock' );
delete_transient( 'rpw_rates_error' );

foreach ( array( '_rpw_links', '_rpw_alerted', '_rpw_pending', '_rpw_market_prices' ) as $rpw_meta ) {
	delete_post_meta_by_key( $rpw_meta );
}

foreach ( array( 'rpw_run_checks', 'rpw_continue_checks', 'rpw_send_alerts' ) as $rpw_hook ) {
	wp_clear_scheduled_hook( $rpw_hook );
}
