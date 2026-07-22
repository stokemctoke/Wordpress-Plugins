<?php
namespace StokeChat;

defined( 'ABSPATH' ) || exit;

/**
 * Message persistence and queries.
 */
class Messages {

	/**
	 * @return object|null The created message row.
	 */
	public function create( $room_id, $user_id, $content ) {
		global $wpdb;

		$wpdb->insert(
			Schema::messages_table(),
			array(
				'room_id'    => (int) $room_id,
				'user_id'    => (int) $user_id,
				'content'    => $content,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		$message = $this->get( $wpdb->insert_id );

		if ( $message ) {
			/**
			 * Fires after a chat message is stored. Used by the mailer.
			 *
			 * @param object $message Raw message row.
			 */
			do_action( 'stokechat_message_created', $message );
		}

		return $message;
	}

	public function get( $message_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::messages_table() . ' WHERE message_id = %d',
				$message_id
			)
		);
	}

	/**
	 * Poll query: messages newer than $after_id, oldest first.
	 *
	 * @return object[]
	 */
	public function get_after( $room_id, $after_id, $limit ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::messages_table() . '
				 WHERE room_id = %d AND message_id > %d
				 ORDER BY message_id ASC
				 LIMIT %d',
				$room_id,
				$after_id,
				$limit
			)
		);
	}

	/**
	 * Latest page of history (used for initial load), returned oldest first.
	 *
	 * @return object[]
	 */
	public function get_latest( $room_id, $limit ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::messages_table() . '
				 WHERE room_id = %d
				 ORDER BY message_id DESC
				 LIMIT %d',
				$room_id,
				$limit
			)
		);
		return array_reverse( $rows );
	}

	public function delete( $message_id ) {
		global $wpdb;
		return (bool) $wpdb->delete( Schema::messages_table(), array( 'message_id' => (int) $message_id ), array( '%d' ) );
	}
}
