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
	 * If this request is a /qr/{slug} hit, log it and redirect. Otherwise do
	 * nothing and let WordPress render the page as normal.
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

		// Count the scan (privacy: store only a salted hash of the IP).
		if ( (int) $code->trackable === 1 ) {
			$this->db->insert_scan(
				(int) $code->id,
				$this->client_ip_hash(),
				$this->user_agent()
			);
		}

		// 302 (temporary) so scans always reach us and the destination can change later.
		nocache_headers();
		wp_redirect( $code->destination, 302 );
		exit;
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
