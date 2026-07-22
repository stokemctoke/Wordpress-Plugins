<?php
namespace StokeChat\Rest;

use StokeChat\Members;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * /rooms/{id}/members — list, invite, change role, kick/leave.
 */
class Members_Controller extends Base_Controller {

	public function register_routes() {
		register_rest_route(
			self::REST_NS,
			'/rooms/(?P<room_id>\d+)/members',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_members' ),
					'permission_callback' => array( $this, 'require_login' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'invite_member' ),
					'permission_callback' => array( $this, 'require_login' ),
					'args'                => array(
						'user_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NS,
			'/rooms/(?P<room_id>\d+)/members/(?P<user_id>\d+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'set_member_role' ),
					'permission_callback' => array( $this, 'require_login' ),
					'args'                => array(
						'room_role' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array( Members::ROLE_MODERATOR, Members::ROLE_MEMBER ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_member' ),
					'permission_callback' => array( $this, 'require_login' ),
				),
			)
		);
	}

	public function list_members( $request ) {
		$result = $this->get_visible_room( (int) $request['room_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		list( $room, ) = $result;

		$rows = $this->plugin->members->get_all( $room->room_id );
		$map  = $this->user_map( wp_list_pluck( $rows, 'user_id' ) );

		$members = array();
		foreach ( $rows as $row ) {
			$info                = $this->user_info( $row->user_id, $map );
			$info['room_role']   = $row->room_role;
			$info['joined_at']   = $row->joined_at;
			$members[]           = $info;
		}

		return rest_ensure_response( array( 'members' => $members ) );
	}

	public function invite_member( $request ) {
		$result = $this->get_member_room( (int) $request['room_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		list( $room, $actor ) = $result;

		if ( ! in_array( $actor->room_role, array( Members::ROLE_CREATOR, Members::ROLE_MODERATOR ), true ) ) {
			return $this->forbidden( __( 'Only the creator or moderators can invite members.', 'stoke-chat' ) );
		}

		$target_id = (int) $request['user_id'];
		if ( ! get_userdata( $target_id ) ) {
			return new WP_Error( 'stokechat_user_not_found', __( 'User not found.', 'stoke-chat' ), array( 'status' => 404 ) );
		}

		if ( $this->plugin->members->is_member( $room->room_id, $target_id ) ) {
			return new WP_Error( 'stokechat_already_member', __( 'That user is already a member.', 'stoke-chat' ), array( 'status' => 400 ) );
		}

		$member = $this->plugin->members->add( $room->room_id, $target_id );
		$map    = $this->user_map( array( $target_id ) );
		$info   = $this->user_info( $target_id, $map );

		$info['room_role'] = $member->room_role;
		$info['joined_at'] = $member->joined_at;

		return new WP_REST_Response( $info, 201 );
	}

	public function set_member_role( $request ) {
		$result = $this->get_member_room( (int) $request['room_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		list( $room, $actor ) = $result;

		if ( Members::ROLE_CREATOR !== $actor->room_role ) {
			return $this->forbidden( __( 'Only the room creator can change member roles.', 'stoke-chat' ) );
		}

		$target_id = (int) $request['user_id'];
		$target    = $this->plugin->members->get( $room->room_id, $target_id );
		if ( ! $target ) {
			return new WP_Error( 'stokechat_member_not_found', __( 'That user is not a member of this room.', 'stoke-chat' ), array( 'status' => 404 ) );
		}
		if ( Members::ROLE_CREATOR === $target->room_role ) {
			return $this->forbidden( __( 'The creator role cannot be changed.', 'stoke-chat' ) );
		}

		$this->plugin->members->set_role( $room->room_id, $target_id, $request['room_role'] );

		return rest_ensure_response(
			array(
				'user_id'   => $target_id,
				'room_role' => $request['room_role'],
			)
		);
	}

	public function remove_member( $request ) {
		$result = $this->get_member_room( (int) $request['room_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		list( $room, $actor ) = $result;

		$target_id = (int) $request['user_id'];
		$target    = $this->plugin->members->get( $room->room_id, $target_id );
		if ( ! $target ) {
			return new WP_Error( 'stokechat_member_not_found', __( 'That user is not a member of this room.', 'stoke-chat' ), array( 'status' => 404 ) );
		}

		if ( Members::ROLE_CREATOR === $target->room_role ) {
			return $this->forbidden( __( 'The room creator cannot be removed.', 'stoke-chat' ) );
		}

		$is_self = $target_id === get_current_user_id();
		$can_kick = in_array( $actor->room_role, array( Members::ROLE_CREATOR, Members::ROLE_MODERATOR ), true );

		if ( ! $is_self && ! $can_kick ) {
			return $this->forbidden( __( 'You cannot remove that member.', 'stoke-chat' ) );
		}

		$this->plugin->members->remove( $room->room_id, $target_id );

		return rest_ensure_response( array( 'removed' => true ) );
	}
}
