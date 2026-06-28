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
					'design'      => array(
						'type'     => 'object',
						'required' => false,
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

		$design = $this->sanitize_design( $request->get_param( 'design' ) );
		$slug   = $this->db->insert_code( $title, $destination, true, $design );

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

	/**
	 * Whitelist + normalise the design payload into a compact JSON string. Only
	 * known keys survive; colours are validated; the logo must be an image data
	 * URL. Returns '' when there's nothing usable.
	 *
	 * @param mixed $raw Decoded object/array from the request.
	 * @return string
	 */
	private function sanitize_design( $raw ) {
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return '';
		}

		$dot    = isset( $raw['dotStyle'] ) && in_array( $raw['dotStyle'], array( 'square', 'rounded' ), true )
			? $raw['dotStyle'] : 'square';
		$corner = isset( $raw['cornerStyle'] ) && in_array( $raw['cornerStyle'], array( 'square', 'extra-rounded' ), true )
			? $raw['cornerStyle'] : 'square';
		$fg     = isset( $raw['fg'] ) ? sanitize_hex_color( $raw['fg'] ) : '';
		$bg     = isset( $raw['bg'] ) ? sanitize_hex_color( $raw['bg'] ) : '';
		$size   = isset( $raw['size'] ) ? max( 128, min( 1024, (int) $raw['size'] ) ) : 512;

		$logo = '';
		if ( ! empty( $raw['logo'] ) && is_string( $raw['logo'] )
			&& preg_match( '#^data:image/(png|svg\+xml|jpeg);base64,[A-Za-z0-9+/=]+$#', $raw['logo'] ) ) {
			$logo = $raw['logo'];
		}

		$design = array(
			'dotStyle'    => $dot,
			'cornerStyle' => $corner,
			'fg'          => $fg ? $fg : '#000000',
			'bg'          => $bg ? $bg : '#ffffff',
			'size'        => $size,
			'logo'        => $logo,
		);

		return wp_json_encode( $design );
	}
}
