<?php
/**
 * Uninstall handler. Runs standalone — the plugin is NOT loaded, so nothing
 * here may reference plugin classes. The scheduled event is always cleared;
 * data is only removed when the user opted in on the settings screen.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// The daily prune event must not outlive the plugin.
wp_clear_scheduled_hook( 'gallus_qr_daily_prune' );

$gallus_qr_settings = get_option( 'gallus_qr_settings', array() );

if ( empty( $gallus_qr_settings['delete_on_uninstall'] ) ) {
	return; // keep all codes, scans and settings for a future reinstall
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gallus_qr_scans" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gallus_qr_codes" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gallus_qr_presets" );
// phpcs:enable

delete_option( 'gallus_qr_settings' );
delete_option( 'gallus_qr_db_version' );

// Remove the plugin capability from every role.
foreach ( array_keys( wp_roles()->roles ) as $gallus_qr_role_key ) {
	$gallus_qr_role = get_role( $gallus_qr_role_key );
	if ( $gallus_qr_role && $gallus_qr_role->has_cap( 'manage_gallus_qr' ) ) {
		$gallus_qr_role->remove_cap( 'manage_gallus_qr' );
	}
}
