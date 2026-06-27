<?php
/**
 * REST endpoint that saves a new trackable code. The generator's JS POSTs here;
 * we store the code and hand back the slug + short URL to encode.
 *
 *   POST /wp-json/gallus-qr/v1/codes   { title, destination }
 *   →    { slug, url }
 *
 * Locked to users who can manage_options (just you, for now).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gallus_QR_REST {

	/** @var Gallus_QR_Database */
	private $db;

	public function __construct( Gallus_QR_Database $db ) {
		$this->db = $db;
	}

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'gallus-qr/v1',
			'/codes',
			array(
				'methods'             => WP_REST_Server::CREATABLE, // POST
				'callback'            => array( $this, 'create_code' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'title'       => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'destination' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);
	}

	/**
	 * Permission check — admins only.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Save the code and return its slug + the short URL to encode.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_code( WP_REST_Request $request ) {
		$destination = $request->get_param( 'destination' );
		$title       = (string) $request->get_param( 'title' );

		if ( empty( $destination ) || ! wp_http_validate_url( $destination ) ) {
			return new WP_Error(
				'gallus_qr_bad_url',
				__( 'A valid destination URL is required.', 'gallus-qr' ),
				array( 'status' => 400 )
			);
		}

		$slug = $this->db->insert_code( $title, $destination, true );

		if ( ! $slug ) {
			return new WP_Error(
				'gallus_qr_save_failed',
				__( 'Could not save the code. Please try again.', 'gallus-qr' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'slug' => $slug,
				'url'  => home_url( '/qr/' . $slug ),
			),
			201
		);
	}
}
