<?php
namespace StokeChat\Rest;

use StokeChat\Capabilities;
use StokeChat\Members;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * /rooms — list, create, get, delete, join, leave.
 */
class Rooms_Controller extends Base_Controller {

	public function register_routes() {
		register_rest_route(
			self::REST_NS,
			'/rooms',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_rooms' ),
					'permission_callback' => array( $this, 'require_login' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_room' ),
					'permission_callback' => array( $this, 'can_create_room' ),
					'args'                => array(
						'name'       => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => function ( $value ) {
								$len = mb_strlen( trim( (string) $value ) );
								return $len >= 1 && $len <= 190;
							},
						),
						'is_private' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NS,
			'/rooms/(?P<room_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_room' ),
					'permission_callback' => array( $this, 'require_login' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_room' ),
					'permission_callback' => array( $this, 'require_login' ),
				),
			)
		);

		register_rest_route(
			self::REST_NS,
			'/rooms/(?P<room_id>\d+)/join',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'join_room' ),
				'permission_callback' => array( $this, 'require_login' ),
			)
		);

		register_rest_route(
			self::REST_NS,
			'/rooms/(?P<room_id>\d+)/leave',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'leave_room' ),
				'permission_callback' => array( $this, 'require_login' ),
			)
		);
	}

	public function can_create_room() {
		$login = $this->require_login();
		if ( true !== $login ) {
			return $login;
		}
		if ( ! current_user_can( Capabilities::CREATE_ROOMS ) ) {
			return $this->forbidden( __( 'You are not allowed to create rooms.', 'stoke-chat' ) );
		}
		return true;
	}

	public function list_rooms() {
		$user_id = get_current_user_id();
		$this->plugin->presence->touch( $user_id );

		$rows  = $this->plugin->rooms->get_visible_for_user( $user_id );
		$rooms = array_map( array( $this, 'format_room_row' ), $rows );

		$user = wp_get_current_user();

		return rest_ensure_response(
			array(
				'rooms' => $rooms,
				'me'    => array(
					'id'           => (int) $user->ID,
					'username'     => $user->user_login,
					'display_name' => $user->display_name,
				),
			)
		);
	}

	public function create_room( $request ) {
		$user_id = get_current_user_id();

		$retry = $this->plugin->rate_limiter->hit( 'room', $user_id, 5, HOUR_IN_SECONDS );
		if ( $retry > 0 ) {
			return $this->rate_limited( $retry );
		}

		$room = $this->plugin->rooms->create(
			trim( $request['name'] ),
			(bool) $request['is_private'],
			$user_id
		);

		if ( ! $room ) {
			return new WP_Error( 'stokechat_create_failed', __( 'Could not create the room.', 'stoke-chat' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'room_id'         => (int) $room->room_id,
				'name'            => $room->name,
				'creator_id'      => (int) $room->creator_id,
				'is_private'      => (bool) $room->is_private,
				'created_at'      => $room->created_at,
				'is_member'       => true,
				'room_role'       => Members::ROLE_CREATOR,
				'member_count'    => 1,
				'unread_count'    => 0,
				'last_message_id' => 0,
			),
			201
		);
	}

	public function get_room( $request ) {
		$result = $this->get_visible_room( (int) $request['room_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		list( $room, $member ) = $result;

		return rest_ensure_response(
			array(
				'room_id'      => (int) $room->room_id,
				'name'         => $room->name,
				'creator_id'   => (int) $room->creator_id,
				'is_private'   => (bool) $room->is_private,
				'created_at'   => $room->created_at,
				'is_member'    => (bool) $member,
				'room_role'    => $member ? $member->room_role : null,
				'member_count' => $this->plugin->members->count( $room->room_id ),
			)
		);
	}

	public function delete_room( $request ) {
		$result = $this->get_visible_room( (int) $request['room_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		list( $room, $member ) = $result;

		$is_creator = $member && Members::ROLE_CREATOR === $member->room_role;
		if ( ! $is_creator && ! current_user_can( 'manage_options' ) ) {
			return $this->forbidden( __( 'Only the room creator can delete this room.', 'stoke-chat' ) );
		}

		$this->plugin->rooms->delete( $room->room_id );

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	public function join_room( $request ) {
		$room = $this->plugin->rooms->get( (int) $request['room_id'] );
		if ( ! $room || (int) $room->is_private ) {
			// Private rooms are invite-only; hide their existence.
			return $room ? $this->room_not_found() : $this->room_not_found();
		}

		$member = $this->plugin->members->add( $room->room_id, get_current_user_id() );

		return rest_ensure_response(
			array(
				'joined'    => true,
				'room_id'   => (int) $room->room_id,
				'room_role' => $member ? $member->room_role : Members::ROLE_MEMBER,
			)
		);
	}

	public function leave_room( $request ) {
		$result = $this->get_member_room( (int) $request['room_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		list( $room, $member ) = $result;

		if ( Members::ROLE_CREATOR === $member->room_role ) {
			return $this->forbidden( __( 'The creator cannot leave their own room. Delete it instead.', 'stoke-chat' ) );
		}

		$this->plugin->members->remove( $room->room_id, get_current_user_id() );

		return rest_ensure_response( array( 'left' => true ) );
	}

	/**
	 * Shape a row from Rooms::get_visible_for_user() for the API.
	 */
	public function format_room_row( $row ) {
		return array(
			'room_id'         => (int) $row->room_id,
			'name'            => $row->name,
			'creator_id'      => (int) $row->creator_id,
			'is_private'      => (bool) $row->is_private,
			'created_at'      => $row->created_at,
			'is_member'       => null !== $row->room_role,
			'room_role'       => $row->room_role,
			'member_count'    => (int) $row->member_count,
			'unread_count'    => (int) $row->unread_count,
			'last_message_id' => (int) $row->last_message_id,
		);
	}
}
