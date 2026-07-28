<?php
namespace StokeChat\Rest;

use WP_REST_Server;
use WP_User_Query;

defined( 'ABSPATH' ) || exit;

/**
 * /users — minimal user search for invites and mention autocomplete.
 * Exposes only id, username, display name, and avatar.
 */
class Users_Controller extends Base_Controller {

	public function register_routes() {
		register_rest_route(
			self::REST_NS,
			'/users',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_users' ),
				'permission_callback' => array( $this, 'require_can_invite' ),
				'args'                => array(
					'search'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $value ) {
							return mb_strlen( trim( (string) $value ) ) >= 2;
						},
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 10,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public function search_users( $request ) {
		// This endpoint hands out user_login values, which @mentions resolve
		// against, so it is a login-name oracle. Two limits keep it usable for
		// picking someone to invite without letting it be walked exhaustively:
		// only people who can actually create a room reach it (above), and each
		// of them gets a bounded number of lookups per minute.
		if ( ! $this->within_search_budget() ) {
			return new \WP_Error(
				'stokechat_search_throttled',
				__( 'Too many lookups — please wait a moment.', 'stoke-chat' ),
				array( 'status' => 429 )
			);
		}

		$query = new WP_User_Query(
			array(
				'search' => '*' . trim( $request['search'] ) . '*',
				// user_login is deliberately absent: searching it turns a
				// two-character prefix into a login-enumeration primitive.
				// People are found by the names they show under.
				'search_columns' => array( 'user_nicename', 'display_name' ),
				'number'         => min( 10, max( 1, (int) $request['per_page'] ) ),
				'orderby'        => 'display_name',
				'fields'         => 'all',
			)
		);

		$users = array();
		foreach ( $query->get_results() as $user ) {
			$users[] = array(
				'user_id'      => (int) $user->ID,
				'username'     => $user->user_login,
				'display_name' => $user->display_name,
				'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 48 ) ),
			);
		}

		return rest_ensure_response( array( 'users' => $users ) );
	}

	/**
	 * Only users who can create a room have any reason to search for someone to
	 * invite, so the login-name oracle stops at that boundary.
	 *
	 * @return bool|\WP_Error
	 */
	public function require_can_invite() {
		$logged_in = $this->require_login();

		if ( is_wp_error( $logged_in ) || ! $logged_in ) {
			return $logged_in;
		}

		if ( ! current_user_can( 'stokechat_create_rooms' ) ) {
			return new \WP_Error(
				'stokechat_forbidden',
				__( 'You cannot invite people to rooms.', 'stoke-chat' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * At most N lookups per user per minute.
	 *
	 * @return bool
	 */
	private function within_search_budget() {
		/**
		 * Filter how many user lookups one account may make per minute.
		 *
		 * @param int $limit
		 */
		$limit = (int) apply_filters( 'stokechat_user_search_per_minute', 20 );

		if ( $limit < 1 ) {
			return true;
		}

		$key   = 'stokechat_usearch_' . get_current_user_id();
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return true;
	}
}
