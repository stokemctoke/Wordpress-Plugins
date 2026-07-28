<?php
/**
 * Where the current request actually came from.
 *
 * Every "who is this visitor" decision in the plugin routes through here so
 * there is exactly one trust boundary to reason about: analytics country
 * detection and scan de-duplication must not disagree about whether a proxy
 * header can be believed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gallus_QR_Request {

	/**
	 * The immediate peer — the address the web server actually accepted the
	 * connection from. Never client-controlled.
	 *
	 * @return string Normalised address, '' when unusable.
	 */
	public static function peer_ip() {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}
		return self::normalize_ip( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) );
	}

	/**
	 * Did this request reach us through infrastructure we control? Only then may
	 * any X-Forwarded-* / CDN header be believed.
	 *
	 * @return bool
	 */
	public static function behind_trusted_proxy() {
		$peer = self::peer_ip();
		return '' !== $peer && self::is_trusted_proxy( $peer );
	}

	/**
	 * The client's IP address.
	 *
	 * The forwarded chain is walked RIGHT TO LEFT, discarding hops that are our
	 * own infrastructure, and the first address that isn't is the client. This
	 * ordering is the whole point: proxies *append* to X-Forwarded-For (nginx's
	 * `$proxy_add_x_forwarded_for` is literally "$http_x_forwarded_for,
	 * $remote_addr"), so anything a client sent itself ends up on the LEFT.
	 * Reading left-to-right therefore returns whatever the visitor typed, which
	 * lets them forge a fresh identity per request.
	 *
	 * @return string '' when no usable address could be determined.
	 */
	public static function client_ip() {
		$peer = self::peer_ip();

		if ( '' === $peer ) {
			return '';
		}

		if ( empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) || ! self::is_trusted_proxy( $peer ) ) {
			return $peer;
		}

		// The peer is the right-most hop: the one we can actually verify.
		$hops   = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
		$hops[] = $peer;
		$best   = $peer;

		for ( $i = count( $hops ) - 1; $i >= 0; $i-- ) {
			$hop = self::normalize_ip( trim( $hops[ $i ] ) );

			// A malformed entry means everything to its left was written by
			// something we can't account for — stop rather than believe it.
			if ( '' === $hop ) {
				break;
			}

			if ( ! self::is_trusted_proxy( $hop ) ) {
				return $hop;
			}

			$best = $hop;
		}

		return $best;
	}

	/**
	 * Salted SHA-256 of the client IP — enough for unique-ish counts, never the
	 * raw address.
	 *
	 * @return string
	 */
	public static function client_ip_hash() {
		return hash( 'sha256', self::client_ip() . wp_salt( 'auth' ) );
	}

	/**
	 * Is this address one of our own reverse proxies?
	 *
	 * Matching is against an explicit CIDR list rather than filter_var()'s
	 * FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE, whose IPv6 semantics do not mean
	 * what they appear to: those flags classify the entire IPv4-mapped
	 * ::ffff:0:0/96 block as "reserved", so on a dual-stack listener (where
	 * REMOTE_ADDR arrives as ::ffff:203.0.113.9 for ordinary IPv4 visitors)
	 * every host on the internet would read as a trusted private peer.
	 *
	 * @param string $ip Normalised address.
	 * @return bool
	 */
	public static function is_trusted_proxy( $ip ) {
		$ip = self::normalize_ip( $ip );

		if ( '' === $ip ) {
			return false;
		}

		/**
		 * Filter the CIDR ranges whose forwarded headers are believed.
		 *
		 * Defaults cover loopback and the private ranges a front-end server
		 * (CloudPanel/nginx, a container network) reaches us from. A site behind
		 * a CDN whose egress is PUBLIC — Cloudflare, CloudFront, Fastly — must
		 * add those ranges here, otherwise every visitor collapses into a single
		 * apparent address and per-visitor analytics become meaningless.
		 *
		 * @param string[] $cidrs
		 */
		$cidrs = apply_filters(
			'gallus_qr_trusted_proxies',
			array(
				'127.0.0.0/8',
				'10.0.0.0/8',
				'172.16.0.0/12',
				'192.168.0.0/16',
				'::1/128',
				'fc00::/7',
			)
		);

		$trusted = false;
		foreach ( (array) $cidrs as $cidr ) {
			if ( self::ip_in_cidr( $ip, $cidr ) ) {
				$trusted = true;
				break;
			}
		}

		/**
		 * Filter the final trust decision for this peer.
		 *
		 * @param bool   $trusted Whether the address matched a trusted range.
		 * @param string $ip      The address under test.
		 */
		return (bool) apply_filters( 'gallus_qr_is_trusted_proxy', $trusted, $ip );
	}

	/**
	 * Canonical form of an address, with IPv4-mapped IPv6 unwrapped to plain
	 * IPv4 so ::ffff:10.0.0.1, ::ffff:a00:1 and 10.0.0.1 all compare equal.
	 *
	 * @param string $ip
	 * @return string '' when the input is not an IP address.
	 */
	public static function normalize_ip( $ip ) {
		$ip = trim( (string) $ip );

		if ( '' === $ip ) {
			return '';
		}

		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- false is the documented failure signal.

		if ( false === $packed ) {
			return '';
		}

		// ::ffff:0:0/96 — an IPv4 address wearing an IPv6 coat.
		if ( 16 === strlen( $packed ) && 0 === strncmp( $packed, str_repeat( "\x00", 10 ) . "\xff\xff", 12 ) ) {
			$packed = substr( $packed, 12 );
		}

		$out = inet_ntop( $packed );

		return false === $out ? '' : $out;
	}

	/**
	 * Does an address fall inside a CIDR range? Handles v4 and v6; a bare
	 * address with no prefix is treated as a single host.
	 *
	 * @param string $ip   Normalised address.
	 * @param string $cidr e.g. '10.0.0.0/8', 'fc00::/7', '127.0.0.1'.
	 * @return bool
	 */
	private static function ip_in_cidr( $ip, $cidr ) {
		$cidr = trim( (string) $cidr );

		if ( false === strpos( $cidr, '/' ) ) {
			return '' !== $ip && $ip === self::normalize_ip( $cidr );
		}

		list( $subnet, $bits ) = explode( '/', $cidr, 2 );

		$ip_packed     = @inet_pton( $ip );     // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$subnet_packed = @inet_pton( trim( $subnet ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$bits          = (int) $bits;

		// Comparing a v4 address against a v6 range (or vice versa) is never a
		// match — strlen distinguishes the two families.
		if ( false === $ip_packed || false === $subnet_packed
			|| strlen( $ip_packed ) !== strlen( $subnet_packed ) ) {
			return false;
		}

		$max_bits = strlen( $ip_packed ) * 8;
		if ( $bits < 0 || $bits > $max_bits ) {
			return false;
		}

		$whole_bytes = intdiv( $bits, 8 );
		$spare_bits  = $bits % 8;

		if ( $whole_bytes > 0
			&& 0 !== strncmp( $ip_packed, $subnet_packed, $whole_bytes ) ) {
			return false;
		}

		if ( 0 === $spare_bits ) {
			return true;
		}

		$mask = 0xff << ( 8 - $spare_bits ) & 0xff;

		return ( ord( $ip_packed[ $whole_bytes ] ) & $mask )
			=== ( ord( $subnet_packed[ $whole_bytes ] ) & $mask );
	}
}
