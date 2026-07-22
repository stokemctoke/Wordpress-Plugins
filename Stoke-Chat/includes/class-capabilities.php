<?php
namespace StokeChat;

defined( 'ABSPATH' ) || exit;

/**
 * The stokechat_create_rooms capability: granted per-role, synced from settings.
 */
class Capabilities {

	const CREATE_ROOMS = 'stokechat_create_rooms';

	/**
	 * Activation default: every role that can read gets to create rooms.
	 */
	public static function grant_default() {
		foreach ( wp_roles()->role_objects as $role ) {
			if ( $role->has_cap( 'read' ) ) {
				$role->add_cap( self::CREATE_ROOMS );
			}
		}
	}

	/**
	 * Sync the capability to exactly the given role slugs.
	 *
	 * @param string[] $allowed_roles Role slugs allowed to create rooms.
	 */
	public static function sync( array $allowed_roles ) {
		foreach ( wp_roles()->role_objects as $slug => $role ) {
			if ( in_array( $slug, $allowed_roles, true ) ) {
				$role->add_cap( self::CREATE_ROOMS );
			} else {
				$role->remove_cap( self::CREATE_ROOMS );
			}
		}
	}

	/**
	 * Role slugs currently holding the capability (used to populate settings UI).
	 *
	 * @return string[]
	 */
	public static function roles_with_cap() {
		$slugs = array();
		foreach ( wp_roles()->role_objects as $slug => $role ) {
			if ( $role->has_cap( self::CREATE_ROOMS ) ) {
				$slugs[] = $slug;
			}
		}
		return $slugs;
	}
}
