<?php
namespace StokeChat;

defined( 'ABSPATH' ) || exit;

/**
 * Room membership + per-room roles (creator / moderator / member).
 */
class Members {

	const ROLE_CREATOR   = 'creator';
	const ROLE_MODERATOR = 'moderator';
	const ROLE_MEMBER    = 'member';

	/**
	 * @return object|null Membership row for a user in a room.
	 */
	public function get( $room_id, $user_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::members_table() . ' WHERE room_id = %d AND user_id = %d',
				$room_id,
				$user_id
			)
		);
	}

	public function is_member( $room_id, $user_id ) {
		return null !== $this->get( $room_id, $user_id );
	}

	/**
	 * @return string|null Room role, or null when not a member.
	 */
	public function get_role( $room_id, $user_id ) {
		$row = $this->get( $room_id, $user_id );
		return $row ? $row->room_role : null;
	}

	public function can_moderate( $room_id, $user_id ) {
		return in_array( $this->get_role( $room_id, $user_id ), array( self::ROLE_CREATOR, self::ROLE_MODERATOR ), true );
	}

	/**
	 * Add a member; no-op if already a member.
	 *
	 * @return object|null The membership row.
	 */
	public function add( $room_id, $user_id, $room_role = self::ROLE_MEMBER ) {
		global $wpdb;

		$existing = $this->get( $room_id, $user_id );
		if ( $existing ) {
			return $existing;
		}

		$wpdb->insert(
			Schema::members_table(),
			array(
				'room_id'   => (int) $room_id,
				'user_id'   => (int) $user_id,
				'room_role' => $room_role,
				'joined_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		return $this->get( $room_id, $user_id );
	}

	public function remove( $room_id, $user_id ) {
		global $wpdb;
		return (bool) $wpdb->delete(
			Schema::members_table(),
			array(
				'room_id' => (int) $room_id,
				'user_id' => (int) $user_id,
			),
			array( '%d', '%d' )
		);
	}

	public function set_role( $room_id, $user_id, $room_role ) {
		global $wpdb;
		return (bool) $wpdb->update(
			Schema::members_table(),
			array( 'room_role' => $room_role ),
			array(
				'room_id' => (int) $room_id,
				'user_id' => (int) $user_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * @return object[] All membership rows for a room, creator first.
	 */
	public function get_all( $room_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::members_table() . "
				 WHERE room_id = %d
				 ORDER BY FIELD(room_role, 'creator', 'moderator', 'member'), joined_at ASC",
				$room_id
			)
		);
	}

	/**
	 * @return int[] Member user IDs for a room.
	 */
	public function user_ids( $room_id ) {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT user_id FROM ' . Schema::members_table() . ' WHERE room_id = %d',
					$room_id
				)
			)
		);
	}

	public function count( $room_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::members_table() . ' WHERE room_id = %d',
				$room_id
			)
		);
	}

	/**
	 * Advance last_read_message_id (never moves backwards).
	 */
	public function update_last_read( $room_id, $user_id, $message_id ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Schema::members_table() . '
				 SET last_read_message_id = %d
				 WHERE room_id = %d AND user_id = %d AND last_read_message_id < %d',
				$message_id,
				$room_id,
				$user_id,
				$message_id
			)
		);
	}

	public function remove_all_for_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( Schema::members_table(), array( 'user_id' => (int) $user_id ), array( '%d' ) );
	}
}
