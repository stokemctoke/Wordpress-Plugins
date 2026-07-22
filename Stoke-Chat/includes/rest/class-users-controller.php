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
				'permission_callback' => array( $this, 'require_login' ),
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
		$query = new WP_User_Query(
			array(
				'search'         => '*' . trim( $request['search'] ) . '*',
				'search_columns' => array( 'user_login', 'user_nicename', 'display_name' ),
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
}
