<?php
/**
 * REST API for Gallus QR codes — full CRUD under gallus-qr/v1:
 *
 *   GET    /codes            list (pagination + search, X-WP-Total headers)
 *   POST   /codes            create { title, payload_type, payload, destination,
 *                                     trackable, slug, design, …lifecycle }
 *   GET    /codes/{id}       one code
 *   PATCH  /codes/{id}       update title/destination/lifecycle/destination modes
 *   DELETE /codes/{id}       delete a code and its scans
 *   GET    /slug-check       is a custom slug usable?
 *
 * URL codes may be trackable (they encode the short /qr/{slug} redirect);
 * every other payload type is stored as a non-tracked library entry whose
 * `destination` is the exact string the QR encodes — rebuilt server-side from
 * the structured fields, never trusted from the client.
 *
 * All datetimes arrive in site-local time ('YYYY-MM-DDTHH:MM' from
 * datetime-local inputs) and are stored in UTC.
 *
 * Locked to users holding the plugin capability.
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
				array(
					'methods'             => WP_REST_Server::READABLE, // GET
					'callback'            => array( $this, 'get_codes' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 1,
							'maximum' => 100,
						),
						'search'   => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE, // POST
					'callback'            => array( $this, 'create_code' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array_merge(
						array(
							'title'        => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_text_field',
							),
							'payload_type' => array(
								'type'     => 'string',
								'required' => false,
								'default'  => 'url',
								'enum'     => Gallus_QR_Payloads::TYPES,
							),
							'payload'      => array(
								'type'     => 'object',
								'required' => false,
							),
							'destination'  => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'esc_url_raw',
							),
							'trackable'    => array(
								'type'     => 'boolean',
								'required' => false,
								'default'  => true,
							),
							'slug'         => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => array( $this, 'sanitize_slug' ),
							),
							'design'       => array(
								'type'     => 'object',
								'required' => false,
							),
						),
						$this->lifecycle_args()
					),
				),
			)
		);

		register_rest_route(
			'gallus-qr/v1',
			'/codes/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_code' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'PATCH, PUT',
					'callback'            => array( $this, 'update_code' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array_merge(
						array(
							'title'       => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'sanitize_text_field',
							),
							'destination' => array(
								'type'              => 'string',
								'required'          => false,
								'sanitize_callback' => 'esc_url_raw',
							),
						),
						$this->lifecycle_args()
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_code' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		// Design presets.
		register_rest_route(
			'gallus-qr/v1',
			'/presets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_presets' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_preset' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'name'   => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'design' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			'gallus-qr/v1',
			'/presets/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_preset' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		// Live "is this slug free?" check for the generator UI.
		register_rest_route(
			'gallus-qr/v1',
			'/slug-check',
			array(
				'methods'             => WP_REST_Server::READABLE, // GET
				'callback'            => array( $this, 'check_slug' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'slug' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( $this, 'sanitize_slug' ),
					),
				),
			)
		);
	}

	/** Shared arg schema for the lifecycle / destination-mode fields. */
	private function lifecycle_args() {
		return array(
			'status'        => array(
				'type'     => 'string',
				'required' => false,
				'enum'     => array( 'active', 'paused' ),
			),
			'expires_at'    => array(
				'type'     => array( 'string', 'null' ),
				'required' => false,
			),
			'max_scans'     => array(
				'type'     => 'integer',
				'required' => false,
				'minimum'  => 0,
			),
			'fallback_url'  => array(
				'type'     => 'string',
				'required' => false,
			),
			'dest_mode'     => array(
				'type'     => 'string',
				'required' => false,
				'enum'     => array( 'single', 'schedule', 'ab' ),
			),
			'destination_b' => array(
				'type'     => 'string',
				'required' => false,
			),
			'switch_at'     => array(
				'type'     => array( 'string', 'null' ),
				'required' => false,
			),
			'ab_split'      => array(
				'type'     => 'integer',
				'required' => false,
				'minimum'  => 0,
				'maximum'  => 100,
			),
		);
	}

	/**
	 * Permission check — anyone holding the plugin capability (granted to
	 * administrators by default; the settings screen can extend it).
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( Gallus_QR_Settings::capability() );
	}

	/**
	 * Lowercase + trim a requested slug (format is validated separately).
	 *
	 * @param string $value
	 * @return string
	 */
	public function sanitize_slug( $value ) {
		return strtolower( trim( (string) $value ) );
	}

	// --- Handlers ---------------------------------------------------------------

	/**
	 * Paginated code list, newest first.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_codes( WP_REST_Request $request ) {
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );
		$search   = (string) $request->get_param( 'search' );

		$result = $this->db->get_codes_page( $page, $per_page, $search );

		$items = array();
		foreach ( $result['items'] as $code ) {
			$items[] = $this->format_code( $code );
		}

		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / max( 1, $per_page ) ) );
		return $response;
	}

	/**
	 * One code by ID.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_code( WP_REST_Request $request ) {
		$code = $this->db->get_code_by_id( (int) $request['id'] );
		if ( ! $code ) {
			return $this->not_found();
		}
		return new WP_REST_Response( $this->format_code( $code ) );
	}

	/**
	 * Save a new code and return its full representation (incl. slug + short URL).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_code( WP_REST_Request $request ) {
		$title     = (string) $request->get_param( 'title' );
		$type      = (string) $request->get_param( 'payload_type' );
		$trackable = (bool) $request->get_param( 'trackable' );

		$payload = $request->get_param( 'payload' );
		$payload = is_array( $payload ) ? $payload : array();

		if ( ! Gallus_QR_Payloads::is_valid_type( $type ) ) {
			return new WP_Error(
				'gallus_qr_bad_type',
				__( 'Unknown payload type.', 'gallus-qr' ),
				array( 'status' => 400 )
			);
		}

		// Tracking needs an HTTP redirect — only URL codes can do that.
		if ( $trackable && ! Gallus_QR_Payloads::is_trackable_type( $type ) ) {
			return new WP_Error(
				'gallus_qr_untrackable_type',
				__( 'Only URL codes can be trackable.', 'gallus-qr' ),
				array( 'status' => 400 )
			);
		}

		if ( 'url' === $type ) {
			$destination = $request->get_param( 'destination' );

			if ( empty( $destination ) || ! wp_http_validate_url( $destination ) ) {
				return new WP_Error(
					'gallus_qr_bad_url',
					__( 'A valid destination URL is required.', 'gallus-qr' ),
					array( 'status' => 400 )
				);
			}

			$utm         = isset( $payload['utm'] ) && is_array( $payload['utm'] ) ? $payload['utm'] : array();
			$destination = Gallus_QR_Payloads::apply_utm( $destination, $utm );
		} else {
			// Rebuild the encoded string from the structured fields server-side.
			$destination = Gallus_QR_Payloads::build( $type, $payload );
			if ( is_wp_error( $destination ) ) {
				return $destination;
			}
		}

		// Optional custom slug — validate format, reserve list, and availability.
		$custom_slug = (string) $request->get_param( 'slug' );
		if ( '' !== $custom_slug ) {
			$format = Gallus_QR_Database::validate_slug_format( $custom_slug );
			if ( is_wp_error( $format ) ) {
				return $format;
			}
			if ( ! $this->db->is_slug_available( $custom_slug ) ) {
				return new WP_Error(
					'gallus_qr_slug_taken',
					__( 'That slug is already taken.', 'gallus-qr' ),
					array( 'status' => 409 )
				);
			}
		}

		// Lifecycle / destination-mode extras (validated before anything is saved).
		$lifecycle = $this->lifecycle_fields( $request );
		if ( is_wp_error( $lifecycle ) ) {
			return $lifecycle;
		}

		$design = $this->sanitize_design( $request->get_param( 'design' ) );
		$slug   = $this->db->insert_code(
			$title,
			$destination,
			$trackable,
			$design,
			$type,
			$this->sanitize_payload( $payload ),
			$custom_slug
		);

		if ( ! $slug ) {
			return new WP_Error(
				'gallus_qr_save_failed',
				__( 'Could not save the code. Please try again.', 'gallus-qr' ),
				array( 'status' => 500 )
			);
		}

		$code = $this->db->get_code_by_slug( $slug );

		if ( ! empty( $lifecycle ) && $code ) {
			$this->db->update_code_fields( (int) $code->id, $lifecycle );
			$code = $this->db->get_code_by_id( (int) $code->id );
		}

		return new WP_REST_Response( $this->format_code( $code ), 201 );
	}

	/**
	 * Update a code's label, destination, lifecycle or destination mode.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_code( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$code = $this->db->get_code_by_id( $id );
		if ( ! $code ) {
			return $this->not_found();
		}

		$fields = $this->lifecycle_fields( $request );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		if ( $request->has_param( 'title' ) ) {
			$fields['title'] = (string) $request->get_param( 'title' );
		}

		if ( $request->has_param( 'destination' ) ) {
			$type = ! empty( $code->payload_type ) ? $code->payload_type : 'url';
			if ( 'url' !== $type ) {
				return new WP_Error(
					'gallus_qr_fixed_payload',
					__( 'Only URL codes can be re-pointed — other types encode their content directly.', 'gallus-qr' ),
					array( 'status' => 400 )
				);
			}
			$destination = (string) $request->get_param( 'destination' );
			if ( empty( $destination ) || ! wp_http_validate_url( $destination ) ) {
				return new WP_Error(
					'gallus_qr_bad_url',
					__( 'A valid destination URL is required.', 'gallus-qr' ),
					array( 'status' => 400 )
				);
			}
			$fields['destination'] = $destination;
		}

		if ( empty( $fields ) ) {
			return new WP_Error(
				'gallus_qr_no_fields',
				__( 'Nothing to update.', 'gallus-qr' ),
				array( 'status' => 400 )
			);
		}

		$this->db->update_code_fields( $id, $fields );

		return new WP_REST_Response( $this->format_code( $this->db->get_code_by_id( $id ) ) );
	}

	/**
	 * Delete a code and all its scan data.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_code( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$code = $this->db->get_code_by_id( $id );
		if ( ! $code ) {
			return $this->not_found();
		}

		$this->db->delete_code( $id );

		return new WP_REST_Response(
			array(
				'deleted'  => true,
				'previous' => $this->format_code( $code ),
			)
		);
	}

	/**
	 * Report whether a custom slug is usable: valid format, not reserved, and
	 * not already taken.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function check_slug( WP_REST_Request $request ) {
		$slug = $request->get_param( 'slug' );

		$format = Gallus_QR_Database::validate_slug_format( $slug );
		if ( is_wp_error( $format ) ) {
			return new WP_REST_Response(
				array(
					'slug'      => $slug,
					'available' => false,
					'message'   => $format->get_error_message(),
				)
			);
		}

		$available = $this->db->is_slug_available( $slug );

		return new WP_REST_Response(
			array(
				'slug'      => $slug,
				'available' => $available,
				'message'   => $available
					? __( 'Available.', 'gallus-qr' )
					: __( 'Already taken.', 'gallus-qr' ),
			)
		);
	}

	// --- Presets ----------------------------------------------------------------

	/**
	 * All design presets, newest first.
	 *
	 * @return WP_REST_Response
	 */
	public function get_presets() {
		$out = array();
		foreach ( $this->db->get_presets() as $preset ) {
			$design = json_decode( (string) $preset->design, true );
			$out[]  = array(
				'id'     => (int) $preset->id,
				'name'   => $preset->name,
				'design' => is_array( $design ) ? $design : array(),
			);
		}
		return new WP_REST_Response( $out );
	}

	/**
	 * Save a design preset.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_preset( WP_REST_Request $request ) {
		$name   = (string) $request->get_param( 'name' );
		$design = $this->sanitize_design( $request->get_param( 'design' ) );

		if ( '' === $name || '' === $design ) {
			return new WP_Error(
				'gallus_qr_bad_preset',
				__( 'A preset needs a name and a design.', 'gallus-qr' ),
				array( 'status' => 400 )
			);
		}

		$id = $this->db->insert_preset( $name, $design );
		if ( ! $id ) {
			return new WP_Error(
				'gallus_qr_save_failed',
				__( 'Could not save the preset.', 'gallus-qr' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'id'     => $id,
				'name'   => $name,
				'design' => json_decode( $design, true ),
			),
			201
		);
	}

	/**
	 * Delete a design preset.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function delete_preset( WP_REST_Request $request ) {
		$this->db->delete_preset( (int) $request['id'] );
		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	// --- Helpers ----------------------------------------------------------------

	/** @return WP_Error Standard 404 for a missing code. */
	private function not_found() {
		return new WP_Error(
			'gallus_qr_not_found',
			__( 'No such QR code.', 'gallus-qr' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Public representation of a code row.
	 *
	 * @param object $code
	 * @return array
	 */
	private function format_code( $code ) {
		$payload = json_decode( (string) $code->payload, true );
		$design  = json_decode( (string) $code->design, true );

		return array(
			'id'            => (int) $code->id,
			'slug'          => $code->slug,
			'url'           => home_url( '/qr/' . $code->slug ),
			'title'         => $code->title,
			'payload_type'  => ! empty( $code->payload_type ) ? $code->payload_type : 'url',
			'payload'       => is_array( $payload ) ? $payload : null,
			'destination'   => $code->destination,
			'trackable'     => ( (int) $code->trackable === 1 ),
			'design'        => is_array( $design ) ? $design : null,
			'status'        => ! empty( $code->status ) ? $code->status : 'active',
			'expires_at'    => ! empty( $code->expires_at ) ? $code->expires_at : null,
			'max_scans'     => isset( $code->max_scans ) ? (int) $code->max_scans : 0,
			'scan_count'    => isset( $code->scan_count ) ? (int) $code->scan_count : 0,
			'fallback_url'  => ! empty( $code->fallback_url ) ? $code->fallback_url : '',
			'dest_mode'     => ! empty( $code->dest_mode ) ? $code->dest_mode : 'single',
			'destination_b' => ! empty( $code->destination_b ) ? $code->destination_b : '',
			'switch_at'     => ! empty( $code->switch_at ) ? $code->switch_at : null,
			'ab_split'      => isset( $code->ab_split ) ? (int) $code->ab_split : 50,
			'created_at'    => $code->created_at,
			'total_scans'   => isset( $code->total_scans ) ? (int) $code->total_scans : null,
		);
	}

	/**
	 * Validate + normalise the lifecycle / destination-mode params that are
	 * present on a request into DB column values. Datetimes are converted from
	 * site-local to UTC. Returns WP_Error on any invalid value.
	 *
	 * @param WP_REST_Request $request
	 * @return array|WP_Error
	 */
	private function lifecycle_fields( WP_REST_Request $request ) {
		$out = array();

		if ( $request->has_param( 'status' ) ) {
			$out['status'] = (string) $request->get_param( 'status' );
		}

		if ( $request->has_param( 'expires_at' ) ) {
			$value = $request->get_param( 'expires_at' );
			if ( null === $value || '' === $value ) {
				$out['expires_at'] = null;
			} else {
				$utc = $this->utc_datetime( $value );
				if ( null === $utc ) {
					return new WP_Error(
						'gallus_qr_bad_datetime',
						__( 'Invalid expiry date/time.', 'gallus-qr' ),
						array( 'status' => 400 )
					);
				}
				$out['expires_at'] = $utc;
			}
		}

		if ( $request->has_param( 'max_scans' ) ) {
			$out['max_scans'] = absint( $request->get_param( 'max_scans' ) );
		}

		if ( $request->has_param( 'fallback_url' ) ) {
			$value = trim( (string) $request->get_param( 'fallback_url' ) );
			if ( '' === $value ) {
				$out['fallback_url'] = null;
			} elseif ( wp_http_validate_url( $value ) ) {
				$out['fallback_url'] = esc_url_raw( $value );
			} else {
				return new WP_Error(
					'gallus_qr_bad_url',
					__( 'The fallback must be a valid URL.', 'gallus-qr' ),
					array( 'status' => 400 )
				);
			}
		}

		if ( $request->has_param( 'dest_mode' ) ) {
			$out['dest_mode'] = (string) $request->get_param( 'dest_mode' );
		}

		if ( $request->has_param( 'destination_b' ) ) {
			$value = trim( (string) $request->get_param( 'destination_b' ) );
			if ( '' === $value ) {
				$out['destination_b'] = null;
			} elseif ( wp_http_validate_url( $value ) ) {
				$out['destination_b'] = esc_url_raw( $value );
			} else {
				return new WP_Error(
					'gallus_qr_bad_url',
					__( 'The second destination must be a valid URL.', 'gallus-qr' ),
					array( 'status' => 400 )
				);
			}
		}

		if ( $request->has_param( 'switch_at' ) ) {
			$value = $request->get_param( 'switch_at' );
			if ( null === $value || '' === $value ) {
				$out['switch_at'] = null;
			} else {
				$utc = $this->utc_datetime( $value );
				if ( null === $utc ) {
					return new WP_Error(
						'gallus_qr_bad_datetime',
						__( 'Invalid switch-over date/time.', 'gallus-qr' ),
						array( 'status' => 400 )
					);
				}
				$out['switch_at'] = $utc;
			}
		}

		if ( $request->has_param( 'ab_split' ) ) {
			$out['ab_split'] = max( 0, min( 100, (int) $request->get_param( 'ab_split' ) ) );
		}

		return $out;
	}

	/**
	 * Convert a site-local datetime ('YYYY-MM-DDTHH:MM' or with seconds/space)
	 * to a UTC MySQL datetime string. Null when unparsable.
	 *
	 * @param string $value
	 * @return string|null
	 */
	private function utc_datetime( $value ) {
		$value = str_replace( 'T', ' ', trim( (string) $value ) );

		try {
			$dt = new DateTimeImmutable( $value, wp_timezone() );
		} catch ( Exception $e ) {
			return null;
		}

		return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Reduce a structured payload to a flat JSON string of scalar values for
	 * storage (kept so codes can be re-edited later). One level deep is allowed
	 * for the URL type's `utm` sub-object.
	 *
	 * @param array $payload
	 * @return string '' when there's nothing to keep.
	 */
	private function sanitize_payload( array $payload ) {
		$clean = array();

		foreach ( $payload as $key => $value ) {
			$key = sanitize_key( $key );
			if ( is_string( $value ) ) {
				$clean[ $key ] = sanitize_textarea_field( $value );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_array( $value ) ) {
				$sub = array();
				foreach ( $value as $sub_key => $sub_value ) {
					if ( is_string( $sub_value ) ) {
						$sub[ sanitize_key( $sub_key ) ] = sanitize_text_field( $sub_value );
					}
				}
				if ( $sub ) {
					$clean[ $key ] = $sub;
				}
			}
		}

		return $clean ? wp_json_encode( $clean ) : '';
	}

	/**
	 * Whitelist + normalise the design payload into a compact JSON string
	 * (design schema v2 — mirrors designer.js normalize()). Only known keys
	 * survive; enums and colours are validated; the legacy logo must be an
	 * image data URL; media-library logos are an attachment ID + URL. Returns
	 * '' when there's nothing usable.
	 *
	 * @param mixed $raw Decoded object/array from the request.
	 * @return string
	 */
	private function sanitize_design( $raw ) {
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return '';
		}

		$enum = static function ( $value, array $allowed, $fallback ) {
			return in_array( $value, $allowed, true ) ? $value : $fallback;
		};
		$hex  = static function ( $value, $fallback ) {
			$clean = is_string( $value ) ? sanitize_hex_color( $value ) : '';
			return $clean ? $clean : $fallback;
		};

		$dot        = $enum( isset( $raw['dotStyle'] ) ? $raw['dotStyle'] : '', array( 'square', 'rounded', 'dots', 'classy', 'classy-rounded', 'extra-rounded' ), 'square' );
		$corner     = $enum( isset( $raw['cornerStyle'] ) ? $raw['cornerStyle'] : '', array( 'square', 'extra-rounded', 'dot' ), 'square' );
		$corner_dot = $enum( isset( $raw['cornerDot'] ) ? $raw['cornerDot'] : '', array( 'auto', 'square', 'dot' ), 'auto' );
		$gradient   = $enum( isset( $raw['gradient'] ) ? $raw['gradient'] : '', array( 'none', 'linear', 'radial' ), 'none' );

		$size = isset( $raw['size'] ) ? max( 128, min( 1024, (int) $raw['size'] ) ) : 512;

		$logo = '';
		if ( ! empty( $raw['logo'] ) && is_string( $raw['logo'] )
			&& preg_match( '#^data:image/(png|svg\+xml|jpeg);base64,[A-Za-z0-9+/=]+$#', $raw['logo'] ) ) {
			$logo = $raw['logo'];
		}

		// Media-library logo: keep the ID and re-resolve the URL server-side so
		// a client can't point the design at an arbitrary address.
		$logo_id  = ! empty( $raw['logoId'] ) ? absint( $raw['logoId'] ) : 0;
		$logo_url = '';
		if ( $logo_id ) {
			$resolved = wp_get_attachment_url( $logo_id );
			if ( $resolved ) {
				$logo_url = $resolved;
			} else {
				$logo_id = 0;
			}
		}

		$frame = null;
		if ( isset( $raw['frame'] ) && is_array( $raw['frame'] ) ) {
			$frame_style = $enum( isset( $raw['frame']['style'] ) ? $raw['frame']['style'] : '', array( 'none', 'label-bottom', 'label-top' ), 'none' );
			$frame_text  = isset( $raw['frame']['text'] ) && is_string( $raw['frame']['text'] )
				? mb_substr( sanitize_text_field( $raw['frame']['text'] ), 0, 40 )
				: '';
			if ( 'none' !== $frame_style && '' !== $frame_text ) {
				$frame = array(
					'style'     => $frame_style,
					'text'      => $frame_text,
					'bandColor' => $hex( isset( $raw['frame']['bandColor'] ) ? $raw['frame']['bandColor'] : '', '#000000' ),
					'textColor' => $hex( isset( $raw['frame']['textColor'] ) ? $raw['frame']['textColor'] : '', '#ffffff' ),
				);
			}
		}

		$design = array(
			'dotStyle'      => $dot,
			'cornerStyle'   => $corner,
			'cornerDot'     => $corner_dot,
			'fg'            => $hex( isset( $raw['fg'] ) ? $raw['fg'] : '', '#000000' ),
			'fg2'           => isset( $raw['fg2'] ) ? ( sanitize_hex_color( (string) $raw['fg2'] ) ?: '' ) : '',
			'gradient'      => $gradient,
			'bg'            => $hex( isset( $raw['bg'] ) ? $raw['bg'] : '', '#ffffff' ),
			'bgTransparent' => ! empty( $raw['bgTransparent'] ),
			'size'          => $size,
			'logo'          => $logo,
			'logoId'        => $logo_id,
			'logoUrl'       => $logo_url,
			'frame'         => $frame,
		);

		return wp_json_encode( $design );
	}
}
