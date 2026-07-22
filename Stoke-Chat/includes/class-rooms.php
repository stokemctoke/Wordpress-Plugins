<?php
namespace StokeChat;

defined( 'ABSPATH' ) || exit;

/**
 * Room CRUD. Deleting a room cascades to messages and memberships.
 */
class Rooms {

	/** @var Members */
	private $members;

	public function __construct( Members $members ) {
		$this->members = $members;
	}

	/**
	 * Create a room; the creator becomes its first member with the creator role.
	 *
	 * @return object|null The room row.
	 */
	public function create( $name, $is_private, $creator_id ) {
		global $wpdb;

		$wpdb->insert(
			Schema::rooms_table(),
			array(
				'name'       => $name,
				'creator_id' => (int) $creator_id,
				'is_private' => $is_private ? 1 : 0,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%d', '%s' )
		);

		$room_id = (int) $wpdb->insert_id;
		$this->members->add( $room_id, $creator_id, Members::ROLE_CREATOR );

		return $this->get( $room_id );
	}

	public function get( $room_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::rooms_table() . ' WHERE room_id = %d',
				$room_id
			)
		);
	}

	/**
	 * Delete a room and everything attached to it.
	 */
	public function delete( $room_id ) {
		global $wpdb;

		$room_id = (int) $room_id;

		$wpdb->delete( Schema::messages_table(), array( 'room_id' => $room_id ), array( '%d' ) );
		$wpdb->delete( Schema::members_table(), array( 'room_id' => $room_id ), array( '%d' ) );
		$wpdb->delete( Schema::rooms_table(), array( 'room_id' => $room_id ), array( '%d' ) );

		do_action( 'stokechat_room_deleted', $room_id );
	}

	/**
	 * Reassign a room's creator (used when the creator's WP account is deleted).
	 */
	public function set_creator( $room_id, $user_id ) {
		global $wpdb;
		$wpdb->update(
			Schema::rooms_table(),
			array( 'creator_id' => (int) $user_id ),
			array( 'room_id' => (int) $room_id ),
			array( '%d' ),
			array( '%d' )
		);
		$this->members->set_role( $room_id, $user_id, Members::ROLE_CREATOR );
	}

	/**
	 * @return int[] Room IDs created by a user.
	 */
	public function ids_created_by( $user_id ) {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT room_id FROM ' . Schema::rooms_table() . ' WHERE creator_id = %d',
					$user_id
				)
			)
		);
	}

	/**
	 * Rooms visible to a user: all public rooms plus their private memberships,
	 * with membership info, member_count, last_message_id, and unread_count.
	 *
	 * @return object[]
	 */
	public function get_visible_for_user( $user_id ) {
		global $wpdb;

		$rooms    = Schema::rooms_table();
		$members  = Schema::members_table();
		$messages = Schema::messages_table();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.room_id, r.name, r.creator_id, r.is_private, r.created_at,
					m.room_role, m.last_read_message_id,
					( SELECT COUNT(*) FROM {$members} mc WHERE mc.room_id = r.room_id ) AS member_count,
					( SELECT MAX(msg.message_id) FROM {$messages} msg WHERE msg.room_id = r.room_id ) AS last_message_id,
					( CASE WHEN m.member_id IS NULL THEN 0 ELSE
						( SELECT COUNT(*) FROM {$messages} mu
						  WHERE mu.room_id = r.room_id AND mu.message_id > m.last_read_message_id )
					  END ) AS unread_count
				 FROM {$rooms} r
				 LEFT JOIN {$members} m ON m.room_id = r.room_id AND m.user_id = %d
				 WHERE r.is_private = 0 OR m.member_id IS NOT NULL
				 ORDER BY r.name ASC",
				$user_id
			)
		);
	}
}
