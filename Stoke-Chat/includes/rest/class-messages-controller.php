<?php
namespace StokeChat\Rest;

use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * /rooms/{id}/messages (poll + post) and /messages/{id} (delete).
 */
class Messages_Controller extends Base_Controller {

	public function register_routes() {
		register_rest_route(
			self::REST_NS,
			'/rooms/(?P<room_id>\d+)/messages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_messages' ),
					'permission_callback' => array( $this, 'require_login' ),
					'args'                => array(
						'after'     => array(
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
						'limit'     => array(
							'type'              => 'integer',
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
						'mark_read' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post_message' ),
					'permission_callback' => array( $this, 'require_login' ),
					'args'                => array(
						'content' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => function ( $value ) {
								return trim( wp_strip_all_tags( (string) $value ) );
							},
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NS,
			'/messages/(?P<message_id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_message' ),
				'permission_callback' => array( $this, 'require_login' ),
			)
		);
	}

	public function get_messages( $request ) {
		$result = $this->get_member_room( (int) $request['room_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		list( $room, ) = $result;

		$user_id = get_current_user_id();
		$this->plugin->presence->touch( $user_id );

		$per_page = (int) $this->plugin->settings->get( 'messages_per_page' );
		$limit    = (int) $request['limit'];
		$limit    = ( $limit >= 1 ) ? min( $limit, 100 ) : $per_page;
		$after    = (int) $request['after'];

		if ( $after > 0 ) {
			$rows = $this->plugin->messages->get_after( $room->room_id, $after, $limit );
		} else {
			$rows = $this->plugin->messages->get_latest( $room->room_id, $limit );
		}

		if ( $request['mark_read'] && $rows ) {
			$last = end( $rows );
			$this->plugin->members->update_last_read( $room->room_id, $user_id, (int) $last->message_id );
		}

		return rest_ensure_response( array( 'messages' => $this->format_messages( $rows ) ) );
	}

	public function post_message( $request ) {
		$result = $this->get_member_room( (int) $request['room_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		list( $room, ) = $result;

		$user_id = get_current_user_id();
		$content = (string) $request['content'];

		if ( '' === $content ) {
			return new WP_Error( 'stokechat_empty_message', __( 'Message is empty.', 'stoke-chat' ), array( 'status' => 400 ) );
		}

		$max = (int) $this->plugin->settings->get( 'message_max_length' );
		if ( mb_strlen( $content ) > $max ) {
			return new WP_Error(
				'stokechat_message_too_long',
				/* translators: %d: maximum characters. */
				sprintf( __( 'Message is too long (max %d characters).', 'stoke-chat' ), $max ),
				array( 'status' => 400 )
			);
		}

		$retry = $this->plugin->rate_limiter->hit( 'msg', $user_id, 10, 30 );
		if ( $retry > 0 ) {
			return $this->rate_limited( $retry );
		}

		$message = $this->plugin->messages->create( $room->room_id, $user_id, $content );
		if ( ! $message ) {
			return new WP_Error( 'stokechat_send_failed', __( 'Could not send the message.', 'stoke-chat' ), array( 'status' => 500 ) );
		}

		$this->plugin->members->update_last_read( $room->room_id, $user_id, (int) $message->message_id );

		$formatted = $this->format_messages( array( $message ) );

		return new WP_REST_Response( $formatted[0], 201 );
	}

	public function delete_message( $request ) {
		$message = $this->plugin->messages->get( (int) $request['message_id'] );
		if ( ! $message ) {
			return new WP_Error( 'stokechat_message_not_found', __( 'Message not found.', 'stoke-chat' ), array( 'status' => 404 ) );
		}

		$user_id = get_current_user_id();

		// Hide message existence from people outside the room.
		if ( ! $this->plugin->members->is_member( $message->room_id, $user_id ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'stokechat_message_not_found', __( 'Message not found.', 'stoke-chat' ), array( 'status' => 404 ) );
		}

		$is_author = (int) $message->user_id === $user_id;
		if ( ! $is_author && ! $this->plugin->members->can_moderate( $message->room_id, $user_id ) && ! current_user_can( 'manage_options' ) ) {
			return $this->forbidden( __( 'You cannot delete this message.', 'stoke-chat' ) );
		}

		$this->plugin->messages->delete( $message->message_id );

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * @param object[] $rows Raw message rows.
	 * @return array API-shaped messages with author display info.
	 */
	private function format_messages( array $rows ) {
		$map = $this->user_map( wp_list_pluck( $rows, 'user_id' ) );
		$out = array();

		foreach ( $rows as $row ) {
			$author = $this->user_info( $row->user_id, $map );
			$out[]  = array(
				'message_id'   => (int) $row->message_id,
				'room_id'      => (int) $row->room_id,
				'user_id'      => (int) $row->user_id,
				'username'     => $author['username'],
				'display_name' => $author['display_name'],
				'avatar_url'   => $author['avatar_url'],
				'content'      => $row->content,
				'created_at'   => $row->created_at,
			);
		}

		return $out;
	}
}
