<?php
/**
 * Scan analytics helpers: user-agent parsing (device / OS / browser buckets),
 * CDN-header country detection, and bot filtering. All parsing happens at
 * insert time so stat queries stay simple GROUP BYs as the scans table grows.
 *
 * Privacy stance: no external lookups, ever. Country comes only from a header
 * the server/CDN already added (Cloudflare et al.); absent header = Unknown.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gallus_QR_Analytics {

	/**
	 * Bucket a user-agent into device / OS / browser. Deliberately a keyword
	 * matcher, not a full UA parser — QR scans come overwhelmingly from phone
	 * cameras, so a dozen keywords cover reality.
	 *
	 * @param string $ua
	 * @return array{device:string,os:string,browser:string}
	 */
	public static function parse_user_agent( $ua ) {
		$ua = strtolower( (string) $ua );

		// Device.
		if ( false !== strpos( $ua, 'ipad' ) || false !== strpos( $ua, 'tablet' ) ) {
			$device = 'Tablet';
		} elseif ( false !== strpos( $ua, 'mobi' )
			|| false !== strpos( $ua, 'android' )
			|| false !== strpos( $ua, 'iphone' ) ) {
			$device = 'Mobile';
		} else {
			$device = 'Desktop';
		}

		// OS — order matters (e.g. Android UAs contain "linux").
		if ( false !== strpos( $ua, 'iphone' ) || false !== strpos( $ua, 'ipad' ) || false !== strpos( $ua, 'ios' ) ) {
			$os = 'iOS';
		} elseif ( false !== strpos( $ua, 'android' ) ) {
			$os = 'Android';
		} elseif ( false !== strpos( $ua, 'windows' ) ) {
			$os = 'Windows';
		} elseif ( false !== strpos( $ua, 'mac os' ) || false !== strpos( $ua, 'macintosh' ) ) {
			$os = 'macOS';
		} elseif ( false !== strpos( $ua, 'linux' ) ) {
			$os = 'Linux';
		} else {
			$os = 'Other';
		}

		// Browser — order matters (Chrome UAs contain "safari", Edge contains "chrome").
		if ( false !== strpos( $ua, 'edg' ) ) {
			$browser = 'Edge';
		} elseif ( false !== strpos( $ua, 'samsungbrowser' ) ) {
			$browser = 'Samsung';
		} elseif ( false !== strpos( $ua, 'firefox' ) || false !== strpos( $ua, 'fxios' ) ) {
			$browser = 'Firefox';
		} elseif ( false !== strpos( $ua, 'chrome' ) || false !== strpos( $ua, 'crios' ) ) {
			$browser = 'Chrome';
		} elseif ( false !== strpos( $ua, 'safari' ) ) {
			$browser = 'Safari';
		} else {
			$browser = 'Other';
		}

		return array(
			'device'  => $device,
			'os'      => $os,
			'browser' => $browser,
		);
	}

	/**
	 * Two-letter country code from a CDN/server header, or '' when none is
	 * present (rendered as "Unknown"). No external calls.
	 *
	 * @return string
	 */
	public static function detect_country() {
		$headers = apply_filters(
			'gallus_qr_country_header',
			array( 'HTTP_CF_IPCOUNTRY', 'GEOIP_COUNTRY_CODE', 'HTTP_X_COUNTRY_CODE' )
		);

		foreach ( (array) $headers as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}
			$code = strtoupper( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
			if ( preg_match( '/^[A-Z]{2}$/', $code ) && 'XX' !== $code ) {
				return $code;
			}
		}

		return '';
	}

	/**
	 * Does this user-agent look like a crawler/preview bot?
	 *
	 * @param string $ua
	 * @return bool
	 */
	public static function is_bot( $ua ) {
		$ua = strtolower( (string) $ua );
		if ( '' === $ua ) {
			return true; // real phone cameras always send a UA
		}

		$needles = apply_filters(
			'gallus_qr_bot_keywords',
			array(
				'bot',
				'crawl',
				'spider',
				'slurp',
				'preview',
				'facebookexternalhit',
				'whatsapp',
				'telegrambot',
				'skypeuripreview',
				'headless',
				'python-requests',
				'curl/',
				'wget/',
				'monitor',
				'uptime',
			)
		);

		foreach ( (array) $needles as $needle ) {
			if ( false !== strpos( $ua, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
