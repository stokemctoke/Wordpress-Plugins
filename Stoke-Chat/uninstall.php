<?php
/**
 * Stoke Chat uninstall: drop all tables, options, user meta, caps, cron, transients.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'stokechat_messages', 'stokechat_members', 'stokechat_rooms' ) as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

delete_option( 'stokechat_settings' );
delete_option( 'stokechat_db_version' );

delete_metadata( 'user', 0, 'stokechat_last_active', '', true );
delete_metadata( 'user', 0, 'stokechat_email_optout', '', true );

if ( function_exists( 'wp_roles' ) ) {
	foreach ( wp_roles()->role_objects as $role ) {
		$role->remove_cap( 'stokechat_create_rooms' );
	}
}

wp_unschedule_hook( 'stokechat_send_alert' );

$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_stokechat\_%'
	    OR option_name LIKE '\_transient\_timeout\_stokechat\_%'"
);
