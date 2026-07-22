<?php
namespace StokeChat;

defined( 'ABSPATH' ) || exit;

/**
 * Custom tables: install via dbDelta, versioned upgrades.
 */
class Schema {

	const DB_VERSION_OPTION = 'stokechat_db_version';

	public static function rooms_table() {
		global $wpdb;
		return $wpdb->prefix . 'stokechat_rooms';
	}

	public static function messages_table() {
		global $wpdb;
		return $wpdb->prefix . 'stokechat_messages';
	}

	public static function members_table() {
		global $wpdb;
		return $wpdb->prefix . 'stokechat_members';
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$rooms           = self::rooms_table();
		$messages        = self::messages_table();
		$members         = self::members_table();

		dbDelta(
			"CREATE TABLE {$rooms} (
  room_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  creator_id bigint(20) unsigned NOT NULL,
  is_private tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (room_id),
  KEY creator_id (creator_id),
  KEY is_private (is_private)
) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$messages} (
  message_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  room_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  content text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (message_id),
  KEY room_message (room_id,message_id),
  KEY user_id (user_id)
) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$members} (
  member_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  room_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  room_role varchar(20) NOT NULL DEFAULT 'member',
  last_read_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
  joined_at datetime NOT NULL,
  PRIMARY KEY  (member_id),
  UNIQUE KEY room_user (room_id,user_id),
  KEY user_id (user_id)
) {$charset_collate};"
		);

		update_option( self::DB_VERSION_OPTION, STOKECHAT_DB_VERSION );
	}

	/**
	 * Cheap option compare on every load; reruns dbDelta when behind.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== STOKECHAT_DB_VERSION ) {
			self::install();
		}
	}
}
