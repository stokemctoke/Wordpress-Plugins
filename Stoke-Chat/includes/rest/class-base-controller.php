<?php
namespace StokeChat\Rest;

use StokeChat\Plugin;
use WP_Error;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Shared REST helpers: auth, room visibility (with 404-hiding for private rooms),
 * user info formatting, rate-limit responses.
 */
class Base_Controller {

	const REST_NS = 'stoke-chat/v1';

	/** @var Plugin */
	protected $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * @return true|WP_Error
	 */
	public function require_login() {
		if ( is_user_logged_in() ) {
			return true;
		}
		return new WP_Error(
			'stokechat_unauthorized',
			__( 'You must be logged in.', 'stoke-chat' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * 404 for missing rooms AND private rooms the user cannot see (no existence leak).
	 */
	protected function room_not_found() {
		return new WP_Error( 'stokechat_room_not_found', __( 'Room not found.', 'stoke-chat' ), array( 'status' => 404 ) );
	}

	protected function forbidden( $message ) {
		return new WP_Error( 'stokechat_forbidden', $message, array( 'status' => 403 ) );
	}

	/**
	 * Fetch a room if the current user may know it exists (member, or public).
	 *
	 * @return array|WP_Error [ $room, $membership_row|null ]
	 */
	protected function get_visible_room( $room_id ) {
		$room = $this->plugin->rooms->get( $room_id );
		if ( ! $room ) {
			return $this->room_not_found();
		}
		$member = $this->plugin->members->get( $room->room_id, get_current_user_id() );
		if ( ! $member && (int) $room->is_private && ! current_user_can( 'manage_options' ) ) {
			return $this->room_not_found();
		}
		return array( $room, $member );
	}

	/**
	 * Like get_visible_room() but requires membership (public rooms must be joined first).
	 *
	 * @return array|WP_Error [ $room, $membership_row ]
	 */
	protected function get_member_room( $room_id ) {
		$result = $this->get_visible_room( $room_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		list( $room, $member ) = $result;
		if ( ! $member ) {
			return new WP_Error(
				'stokechat_not_a_member',
				__( 'Join this room first.', 'stoke-chat' ),
				array( 'status' => 403 )
			);
		}
		return array( $room, $member );
	}

	/**
	 * @param int[] $user_ids
	 * @return array Map of user_id => public display info.
	 */
	protected function user_map( array $user_ids ) {
		$user_ids = array_values( array_unique( array_map( 'intval', $user_ids ) ) );
		$map      = array();
		if ( $user_ids ) {
			foreach ( get_users( array( 'include' => $user_ids ) ) as $user ) {
				$map[ (int) $user->ID ] = array(
					'user_id'      => (int) $user->ID,
					'username'     => $user->user_login,
					'display_name' => $user->display_name,
					'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 48 ) ),
				);
			}
		}
		return $map;
	}

	/**
	 * Display info for one user; deleted accounts render as "Former member".
	 */
	protected function user_info( $user_id, array $map ) {
		if ( isset( $map[ (int) $user_id ] ) ) {
			return $map[ (int) $user_id ];
		}
		return array(
			'user_id'      => (int) $user_id,
			'username'     => '',
			'display_name' => __( 'Former member', 'stoke-chat' ),
			'avatar_url'   => '',
		);
	}

	/**
	 * 429 response with Retry-After.
	 */
	protected function rate_limited( $retry_after ) {
		$response = new WP_REST_Response(
			array(
				'code'    => 'stokechat_rate_limited',
				'message' => __( 'You are sending too fast. Please slow down.', 'stoke-chat' ),
				'data'    => array( 'status' => 429 ),
			),
			429
		);
		$response->header( 'Retry-After', (int) $retry_after );
		return $response;
	}
}
