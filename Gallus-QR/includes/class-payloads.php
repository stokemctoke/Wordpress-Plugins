<?php
/**
 * Payload builders: turn structured fields into the exact string a QR encodes
 * (WiFi network, vCard, mailto:, SMS, phone, calendar event, plain text).
 *
 * The server always rebuilds the encoded string from the submitted fields —
 * it never trusts a client-built string. assets/js/payloads.js mirrors these
 * builders for the live preview; if you change an escape rule here, change it
 * there too.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gallus_QR_Payloads {

	/** Every payload type the plugin understands. */
	const TYPES = array( 'url', 'text', 'wifi', 'vcard', 'email', 'sms', 'tel', 'event' );

	/**
	 * Only URL codes can be trackable — tracking works by routing the scan
	 * through an HTTP redirect, which is meaningless for WiFi/vCard/etc.
	 *
	 * @param string $type
	 * @return bool
	 */
	public static function is_trackable_type( $type ) {
		return 'url' === $type;
	}

	/**
	 * @param string $type
	 * @return bool
	 */
	public static function is_valid_type( $type ) {
		return in_array( $type, self::TYPES, true );
	}

	/**
	 * Build the encoded string for a payload type from its structured fields.
	 *
	 * @param string $type   One of self::TYPES (not 'url' — URLs are validated
	 *                       and stored directly, see the REST layer).
	 * @param array  $fields Raw field values (already unslashed).
	 * @return string|WP_Error The exact string to encode, or an error.
	 */
	public static function build( $type, array $fields ) {
		switch ( $type ) {
			case 'text':
				return self::build_text( $fields );
			case 'wifi':
				return self::build_wifi( $fields );
			case 'vcard':
				return self::build_vcard( $fields );
			case 'email':
				return self::build_email( $fields );
			case 'sms':
				return self::build_sms( $fields );
			case 'tel':
				return self::build_tel( $fields );
			case 'event':
				return self::build_event( $fields );
		}

		return new WP_Error(
			'gallus_qr_bad_type',
			__( 'Unknown payload type.', 'gallus-qr' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Append UTM campaign parameters to a URL. Empty values are skipped.
	 *
	 * @param string $url
	 * @param array  $utm Keys: source, medium, campaign.
	 * @return string
	 */
	public static function apply_utm( $url, array $utm ) {
		$map = array(
			'source'   => 'utm_source',
			'medium'   => 'utm_medium',
			'campaign' => 'utm_campaign',
		);

		foreach ( $map as $key => $param ) {
			if ( ! empty( $utm[ $key ] ) && is_string( $utm[ $key ] ) ) {
				$url = add_query_arg( $param, rawurlencode( sanitize_text_field( $utm[ $key ] ) ), $url );
			}
		}

		return $url;
	}

	// --- Field helpers ---------------------------------------------------------

	/** @return string Trimmed single-line text field. */
	private static function line( array $fields, $key ) {
		return isset( $fields[ $key ] ) && is_string( $fields[ $key ] )
			? sanitize_text_field( $fields[ $key ] )
			: '';
	}

	/** @return string Multi-line text field (newlines preserved). */
	private static function multiline( array $fields, $key ) {
		return isset( $fields[ $key ] ) && is_string( $fields[ $key ] )
			? sanitize_textarea_field( $fields[ $key ] )
			: '';
	}

	/** @return string A phone number reduced to digits, +, spaces stripped. */
	private static function phone( array $fields, $key ) {
		$raw = self::line( $fields, $key );
		return preg_replace( '/[^0-9+]/', '', $raw );
	}

	// --- Escaping (mirrored in payloads.js) --------------------------------------

	/**
	 * WiFi payload escaping: backslash-escape \ ; , : " per the de-facto
	 * WIFI: string rules.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function escape_wifi( $value ) {
		return preg_replace( '/([\\\\;,:"])/', '\\\\$1', $value );
	}

	/**
	 * vCard/iCal text escaping per RFC 6350/5545: backslash, semicolon, comma,
	 * newline.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function escape_vtext( $value ) {
		$value = str_replace( '\\', '\\\\', $value );
		$value = str_replace( array( ';', ',' ), array( '\\;', '\\,' ), $value );
		$value = preg_replace( '/\r?\n/', '\\n', $value );
		return $value;
	}

	// --- Builders ----------------------------------------------------------------

	private static function build_text( array $fields ) {
		$text = self::multiline( $fields, 'text' );
		if ( '' === $text ) {
			return new WP_Error( 'gallus_qr_empty', __( 'Enter some text to encode.', 'gallus-qr' ), array( 'status' => 400 ) );
		}
		return $text;
	}

	private static function build_wifi( array $fields ) {
		$ssid = isset( $fields['ssid'] ) && is_string( $fields['ssid'] ) ? trim( $fields['ssid'] ) : '';
		$pass = isset( $fields['password'] ) && is_string( $fields['password'] ) ? $fields['password'] : '';
		$enc  = self::line( $fields, 'encryption' );
		$enc  = in_array( $enc, array( 'WPA', 'WEP', 'nopass' ), true ) ? $enc : 'WPA';

		if ( '' === $ssid ) {
			return new WP_Error( 'gallus_qr_empty', __( 'A network name (SSID) is required.', 'gallus-qr' ), array( 'status' => 400 ) );
		}
		if ( 'nopass' !== $enc && '' === $pass ) {
			return new WP_Error( 'gallus_qr_empty', __( 'A password is required unless the network is open.', 'gallus-qr' ), array( 'status' => 400 ) );
		}

		$out = 'WIFI:T:' . $enc . ';S:' . self::escape_wifi( $ssid ) . ';';
		if ( 'nopass' !== $enc ) {
			$out .= 'P:' . self::escape_wifi( $pass ) . ';';
		}
		if ( ! empty( $fields['hidden'] ) ) {
			$out .= 'H:true;';
		}
		return $out . ';';
	}

	private static function build_vcard( array $fields ) {
		$first = self::line( $fields, 'first' );
		$last  = self::line( $fields, 'last' );

		if ( '' === $first && '' === $last ) {
			return new WP_Error( 'gallus_qr_empty', __( 'A contact needs at least a name.', 'gallus-qr' ), array( 'status' => 400 ) );
		}

		$lines   = array( 'BEGIN:VCARD', 'VERSION:3.0' );
		$lines[] = 'N:' . self::escape_vtext( $last ) . ';' . self::escape_vtext( $first ) . ';;;';
		$lines[] = 'FN:' . self::escape_vtext( trim( $first . ' ' . $last ) );

		$org = self::line( $fields, 'org' );
		if ( '' !== $org ) {
			$lines[] = 'ORG:' . self::escape_vtext( $org );
		}
		$job = self::line( $fields, 'job' );
		if ( '' !== $job ) {
			$lines[] = 'TITLE:' . self::escape_vtext( $job );
		}
		$phone = self::phone( $fields, 'phone' );
		if ( '' !== $phone ) {
			$lines[] = 'TEL;TYPE=CELL:' . $phone;
		}
		$email = sanitize_email( self::line( $fields, 'email' ) );
		if ( '' !== $email ) {
			$lines[] = 'EMAIL:' . $email;
		}
		$url = self::line( $fields, 'url' );
		if ( '' !== $url && wp_http_validate_url( $url ) ) {
			$lines[] = 'URL:' . $url;
		}

		$street  = self::line( $fields, 'street' );
		$city    = self::line( $fields, 'city' );
		$zip     = self::line( $fields, 'zip' );
		$country = self::line( $fields, 'country' );
		if ( '' !== $street || '' !== $city || '' !== $zip || '' !== $country ) {
			$lines[] = 'ADR;TYPE=WORK:;;' . self::escape_vtext( $street ) . ';' . self::escape_vtext( $city )
				. ';;' . self::escape_vtext( $zip ) . ';' . self::escape_vtext( $country );
		}

		$lines[] = 'END:VCARD';
		return implode( "\r\n", $lines );
	}

	private static function build_email( array $fields ) {
		$to = sanitize_email( self::line( $fields, 'to' ) );
		if ( '' === $to || ! is_email( $to ) ) {
			return new WP_Error( 'gallus_qr_empty', __( 'A valid recipient email address is required.', 'gallus-qr' ), array( 'status' => 400 ) );
		}

		$out   = 'mailto:' . $to;
		$query = array();

		$subject = self::line( $fields, 'subject' );
		if ( '' !== $subject ) {
			$query[] = 'subject=' . rawurlencode( $subject );
		}
		$body = self::multiline( $fields, 'body' );
		if ( '' !== $body ) {
			$query[] = 'body=' . rawurlencode( $body );
		}

		return $query ? $out . '?' . implode( '&', $query ) : $out;
	}

	private static function build_sms( array $fields ) {
		$number = self::phone( $fields, 'number' );
		if ( '' === $number ) {
			return new WP_Error( 'gallus_qr_empty', __( 'A phone number is required.', 'gallus-qr' ), array( 'status' => 400 ) );
		}
		$message = self::multiline( $fields, 'message' );
		return 'SMSTO:' . $number . ':' . $message;
	}

	private static function build_tel( array $fields ) {
		$number = self::phone( $fields, 'number' );
		if ( '' === $number ) {
			return new WP_Error( 'gallus_qr_empty', __( 'A phone number is required.', 'gallus-qr' ), array( 'status' => 400 ) );
		}
		return 'tel:' . $number;
	}

	private static function build_event( array $fields ) {
		$summary = self::line( $fields, 'summary' );
		$start   = self::ical_datetime( self::line( $fields, 'start' ) );

		if ( '' === $summary ) {
			return new WP_Error( 'gallus_qr_empty', __( 'An event title is required.', 'gallus-qr' ), array( 'status' => 400 ) );
		}
		if ( '' === $start ) {
			return new WP_Error( 'gallus_qr_empty', __( 'A valid start date/time is required.', 'gallus-qr' ), array( 'status' => 400 ) );
		}

		$lines   = array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'BEGIN:VEVENT' );
		$lines[] = 'SUMMARY:' . self::escape_vtext( $summary );
		$lines[] = 'DTSTART:' . $start;

		$end = self::ical_datetime( self::line( $fields, 'end' ) );
		if ( '' !== $end ) {
			$lines[] = 'DTEND:' . $end;
		}
		$location = self::line( $fields, 'location' );
		if ( '' !== $location ) {
			$lines[] = 'LOCATION:' . self::escape_vtext( $location );
		}
		$description = self::multiline( $fields, 'description' );
		if ( '' !== $description ) {
			$lines[] = 'DESCRIPTION:' . self::escape_vtext( $description );
		}

		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", $lines );
	}

	/**
	 * Convert a datetime-local value ('2026-07-16T12:30', site timezone) into
	 * iCal UTC basic format ('20260716T113000Z'). Returns '' when unparsable.
	 *
	 * @param string $value
	 * @return string
	 */
	private static function ical_datetime( $value ) {
		if ( '' === $value ) {
			return '';
		}

		try {
			$dt = new DateTimeImmutable( $value, wp_timezone() );
		} catch ( Exception $e ) {
			return '';
		}

		return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
	}
}
