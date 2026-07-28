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

		// The cap is a lifecycle gate like paused/expired, so it is checked for
		// EVERY visitor. Leaving it inside the trackable/bot branch below meant
		// `curl -A bot` (or any empty user-agent, which is_bot() also matches)
		// walked straight past an exhausted code to its live destination.
		if ( $this->is_capped( $code ) ) {
			$this->fail_over( $code );
		}

		// Count the scan (privacy: store only a salted hash of the IP). Bots are
		// redirected but never counted, and repeat hits from the same visitor
		// inside the dedupe window are redirected without counting — otherwise a
		// scripted loop could burn through max_scans and permanently kill a code
		// that's already been printed.
		$ua      = $this->user_agent();
		$is_bot  = Gallus_QR_Settings::get( 'bot_filter' ) && Gallus_QR_Analytics::is_bot( $ua );
		$variant = $this->pick_variant( $code );
		$ip_hash = $this->client_ip_hash();

		if ( (int) $code->trackable === 1 && ! $is_bot
			&& $this->claim_scan( (int) $code->id, $ip_hash )
			&& $this->may_spend_cap( $code ) ) {
			$counted = $this->db->try_count_scan( (int) $code->id );

			// null = the query itself failed. A deadlock or lock-wait timeout is
			// not the same as "this code is used up", and must never show a
			// customer scanning a printed product the 410 page.
			if ( false === $counted ) {
				$this->fail_over( $code );
			}

			if ( true === $counted ) {
				$this->db->insert_scan(
					(int) $code->id,
					$ip_hash,
					$ua,
					$variant,
					Gallus_QR_Analytics::detect_country()
				);
			}
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
	 * Is this code already at its scan limit? Read-only companion to
	 * try_count_scan(), for hits we deliberately don't count.
	 *
	 * @param object $code
	 * @return bool
	 */
	private function is_capped( $code ) {
		$max = isset( $code->max_scans ) ? (int) $code->max_scans : 0;
		return $max > 0 && (int) $code->scan_count >= $max;
	}

	/**
	 * Claim this hit as a countable scan, or report that we've already counted
	 * this visitor for this code very recently.
	 *
	 * Without this, anyone who knows a slug could hold down a request loop and
	 * either inflate the stats or — far worse — burn through a code's max_scans
	 * so a QR that's already printed (or etched into a PCB) stops working for
	 * everyone. One scan per visitor per code per window is plenty for
	 * analytics and removes that lever entirely.
	 *
	 * @param int    $code_id
	 * @param string $ip_hash
	 * @return bool True when the caller should count this hit.
	 */
	private function claim_scan( $code_id, $ip_hash ) {
		/**
		 * Filter the per-visitor scan dedupe window, in seconds.
		 * Return 0 to count every single hit (the pre-2.1.0 behaviour).
		 *
		 * @param int $seconds
		 * @param int $code_id
		 */
		$window = (int) apply_filters( 'gallus_qr_scan_dedupe_window', MINUTE_IN_SECONDS, $code_id );

		if ( $window < 1 ) {
			return true;
		}

		// Atomic: a get-then-set on a transient lets N concurrent requests all
		// observe "absent" and all count, which is an N-fold amplification for
		// anyone burning a cap. It also survives an object-cache eviction, which
		// would silently switch de-duplication off altogether.
		return $this->db->claim_once( 'seen:' . (int) $code_id . ':' . substr( $ip_hash, 0, 32 ), $window );
	}

	/**
	 * Is this code allowed to spend more of its scan cap right now?
	 *
	 * Per-visitor de-duplication alone does not protect a cap: an attacker with
	 * many source addresses (a routed IPv6 /64 hands out a fresh one per
	 * request, and a proxy pool does the same) simply gets a fresh bucket each
	 * time. A capped code is a physical object — printed on packaging, etched
	 * into silkscreen — so exhausting it is unrecoverable in the field.
	 *
	 * Rate-limiting cap consumption per CODE, independent of who is asking,
	 * turns "kill it in seconds" into something slow enough to notice and act
	 * on. Uncapped codes are unaffected: there is nothing to protect.
	 *
	 * @param object $code
	 * @return bool True when this hit may consume one unit of the cap.
	 */
	private function may_spend_cap( $code ) {
		$max = isset( $code->max_scans ) ? (int) $code->max_scans : 0;

		if ( $max < 1 ) {
			return true;
		}

		/**
		 * Filter how fast a capped code's remaining scans may be consumed:
		 * at most one per this many seconds. 0 disables the limit.
		 *
		 * @param int    $seconds
		 * @param object $code
		 */
		$interval = (int) apply_filters( 'gallus_qr_cap_spend_interval', 2, $code );

		if ( $interval < 1 ) {
			return true;
		}

		return $this->db->claim_once( 'cap:' . (int) $code->id, $interval );
	}

	/**
	 * Salted SHA-256 of the client IP — enough for unique-ish counts, never the
	 * raw address. Resolution and proxy trust live in Gallus_QR_Request so the
	 * redirect handler and the analytics country lookup cannot disagree.
	 *
	 * @return string
	 */
	private function client_ip_hash() {
		return Gallus_QR_Request::client_ip_hash();
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
