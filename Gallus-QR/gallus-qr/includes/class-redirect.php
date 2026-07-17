<?php
/**
 * Redirect handler: teaches WordPress that /qr/{slug} is ours, logs each hit,
 * then forwards the visitor to the real destination. This is what makes a
 * "dynamic" code countable — every scan passes through here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gallus_QR_Redirect {

	/** @var Gallus_QR_Database */
	private $db;

	public function __construct( Gallus_QR_Database $db ) {
		$this->db = $db;
	}

	/**
	 * Hook into WordPress. Called from gallus-qr.php.
	 */
	public function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ) );
	}

	/**
	 * Map /qr/{slug} onto a private query var. Static so the activation hook can
	 * register the rule before flushing the rewrite cache.
	 */
	public static function register_rewrite() {
		add_rewrite_rule(
			'^qr/([^/]+)/?$',
			'index.php?gallus_qr_slug=$matches[1]',
			'top'
		);
	}

	/**
	 * Whitelist our query var so WordPress will populate it.
	 *
	 * @param array $vars
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[] = 'gallus_qr_slug';
		return $vars;
	}

	/**
	 * If this request is a /qr/{slug} hit, run the lifecycle gate, resolve the
	 * destination (single / scheduled / A-B), log the scan, and redirect.
	 * Otherwise do nothing and let WordPress render the page as normal.
	 */
	public function maybe_redirect() {
		$slug = get_query_var( 'gallus_qr_slug' );
		if ( '' === $slug || null === $slug ) {
			return;
		}

		$code = $this->db->get_code_by_slug( $slug );

		// Unknown slug → a normal 404.
		if ( ! $code ) {
			status_header( 404 );
			nocache_headers();
			wp_die(
				esc_html__( 'QR code not found.', 'gallus-qr' ),
				esc_html__( 'Not found', 'gallus-qr' ),
				array( 'response' => 404 )
			);
		}

		// Non-URL library codes (WiFi, vCard, …) encode their payload directly;
		// their short link is not a destination anyone should land on.
		if ( ! empty( $code->payload_type ) && 'url' !== $code->payload_type ) {
			$this->fail_over( $code );
		}

		// Lifecycle gate: paused or expired codes fail over. Datetimes are UTC
		// strings in identical format, so plain string comparison is correct.
		$now = current_time( 'mysql', true );

		if ( isset( $code->status ) && 'paused' === $code->status ) {
			$this->fail_over( $code );
		}
		if ( ! empty( $code->expires_at ) && $code->expires_at <= $now ) {
			$this->fail_over( $code );
		}

		// Count the scan (privacy: store only a salted hash of the IP). The
		// counter update is atomic and doubles as the scan-cap gate. Bots are
		// redirected but never counted (and never consume the cap).
		$ua      = $this->user_agent();
		$is_bot  = Gallus_QR_Settings::get( 'bot_filter' ) && Gallus_QR_Analytics::is_bot( $ua );
		$variant = $this->pick_variant( $code );

		if ( (int) $code->trackable === 1 && ! $is_bot ) {
			if ( ! $this->db->try_count_scan( (int) $code->id ) ) {
				$this->fail_over( $code ); // cap reached
			}

			$this->db->insert_scan(
				(int) $code->id,
				$this->client_ip_hash(),
				$ua,
				$variant,
				Gallus_QR_Analytics::detect_country()
			);
		}

		// 302 (temporary) so scans always reach us and the destination can change later.
		nocache_headers();
		wp_redirect( $this->resolve_destination( $code, $now, $variant ), 302 );
		exit;
	}

	/**
	 * Which A/B variant this scan gets ('' when the code isn't in A/B mode).
	 *
	 * @param object $code
	 * @return string ''|'A'|'B'
	 */
	private function pick_variant( $code ) {
		if ( empty( $code->dest_mode ) || 'ab' !== $code->dest_mode || empty( $code->destination_b ) ) {
			return '';
		}
		$split = max( 0, min( 100, (int) $code->ab_split ) );
		return random_int( 1, 100 ) <= $split ? 'B' : 'A';
	}

	/**
	 * The URL this scan should land on, honouring the destination mode.
	 *
	 * @param object $code
	 * @param string $now     UTC MySQL datetime.
	 * @param string $variant ''|'A'|'B' (already picked for A/B codes).
	 * @return string
	 */
	private function resolve_destination( $code, $now, $variant ) {
		$mode = ! empty( $code->dest_mode ) ? $code->dest_mode : 'single';

		if ( 'schedule' === $mode && ! empty( $code->destination_b ) && ! empty( $code->switch_at )
			&& $code->switch_at <= $now ) {
			return $code->destination_b;
		}

		if ( 'ab' === $mode && 'B' === $variant && ! empty( $code->destination_b ) ) {
			return $code->destination_b;
		}

		return $code->destination;
	}

	/**
	 * A scan that can't proceed (paused / expired / capped / non-URL payload):
	 * send it to the code's fallback URL, else the global default, else a
	 * polite 410 page. Never cached — a code can be un-paused a minute later.
	 *
	 * @param object $code
	 */
	private function fail_over( $code ) {
		nocache_headers();
		header( 'Cache-Control: no-store' );

		$fallback = ! empty( $code->fallback_url ) ? $code->fallback_url : (string) Gallus_QR_Settings::get( 'default_fallback_url' );

		if ( $fallback && wp_http_validate_url( $fallback ) ) {
			wp_redirect( $fallback, 302 );
			exit;
		}

		status_header( 410 );
		wp_die(
			esc_html__( 'This QR code is no longer active.', 'gallus-qr' ),
			esc_html__( 'QR code inactive', 'gallus-qr' ),
			array( 'response' => 410 )
		);
	}

	/**
	 * Salted SHA-256 of the client IP — enough for unique-ish counts, never the
	 * raw address. Honours a reverse proxy (CloudPanel/nginx sits in front).
	 *
	 * @return string
	 */
	private function client_ip_hash() {
		$ip = '';
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip    = trim( $parts[0] );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return hash( 'sha256', $ip . wp_salt( 'auth' ) );
	}

	/**
	 * @return string Trimmed, length-capped user-agent string.
	 */
	private function user_agent() {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		return substr( $ua, 0, 255 );
	}
}
