<?php
/**
 * The proxy trust boundary: which peers may be believed, how addresses are
 * normalised, and the country header that must not be spoofable.
 *
 * These cases exist because filter_var( …, FILTER_FLAG_NO_PRIV_RANGE |
 * FILTER_FLAG_NO_RES_RANGE ) does not mean what it looks like it means for
 * IPv6 — it reports the IPv4-mapped ::ffff:0:0/96 block as reserved, i.e.
 * "private", which on a dual-stack listener trusts the entire internet.
 */

class Test_Gallus_QR_Request_Trust extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		unset(
			$_SERVER['REMOTE_ADDR'],
			$_SERVER['HTTP_X_FORWARDED_FOR'],
			$_SERVER['HTTP_CF_IPCOUNTRY'],
			$_SERVER['GEOIP_COUNTRY_CODE']
		);
	}

	public function tear_down() {
		unset(
			$_SERVER['REMOTE_ADDR'],
			$_SERVER['HTTP_X_FORWARDED_FOR'],
			$_SERVER['HTTP_CF_IPCOUNTRY'],
			$_SERVER['GEOIP_COUNTRY_CODE']
		);
		parent::tear_down();
	}

	// --- Trust classification --------------------------------------------------

	public function test_private_and_loopback_peers_are_trusted() {
		foreach ( array( '127.0.0.1', '127.10.20.30', '10.0.0.5', '172.16.0.1', '172.31.255.254', '192.168.1.1', '::1', 'fc00::1', 'fd12:3456::1' ) as $ip ) {
			$this->assertTrue( Gallus_QR_Request::is_trusted_proxy( $ip ), $ip . ' should be trusted' );
		}
	}

	public function test_public_peers_are_not_trusted() {
		foreach ( array( '8.8.8.8', '203.0.113.9', '198.51.100.7', '172.32.0.1', '172.15.255.255', '2001:db8::1', '2606:4700:4700::1111' ) as $ip ) {
			$this->assertFalse( Gallus_QR_Request::is_trusted_proxy( $ip ), $ip . ' should NOT be trusted' );
		}
	}

	public function test_ipv4_mapped_addresses_inherit_their_ipv4_verdict() {
		// The regression this whole class exists for.
		$this->assertFalse( Gallus_QR_Request::is_trusted_proxy( '::ffff:8.8.8.8' ) );
		$this->assertFalse( Gallus_QR_Request::is_trusted_proxy( '::ffff:203.0.113.5' ) );
		$this->assertTrue( Gallus_QR_Request::is_trusted_proxy( '::ffff:10.0.0.1' ) );
		$this->assertTrue( Gallus_QR_Request::is_trusted_proxy( '::ffff:127.0.0.1' ) );
	}

	public function test_reserved_and_unspecified_addresses_are_not_trusted() {
		foreach ( array( '::', '64:ff9b::8.8.8.8', '240.0.0.1', '100.64.0.1', '169.254.1.1', 'fe80::1' ) as $ip ) {
			$this->assertFalse( Gallus_QR_Request::is_trusted_proxy( $ip ), $ip . ' should NOT be trusted' );
		}
	}

	public function test_malformed_addresses_are_never_trusted() {
		// Note 010.0.0.1 / 0x7f.1 / 127.1: inet_pton rejects octal, hex and
		// short-form notations outright, so they cannot smuggle a public address
		// past the check by looking private (or vice versa).
		foreach ( array( '', 'not-an-ip', '010.0.0.1', '0x7f.1', '127.1', '<script>', '127.0.0.1/8' ) as $ip ) {
			$this->assertFalse( Gallus_QR_Request::is_trusted_proxy( $ip ), var_export( $ip, true ) . ' should NOT be trusted' );
		}
	}

	public function test_surrounding_whitespace_is_tolerated() {
		// Forwarded chains are comma-SPACE separated, so trimming is required.
		$this->assertTrue( Gallus_QR_Request::is_trusted_proxy( ' 127.0.0.1 ' ) );
	}

	public function test_trusted_ranges_are_filterable_for_public_egress_cdns() {
		$cdn = static function ( $cidrs ) {
			$cidrs[] = '203.0.113.0/24';
			return $cidrs;
		};

		add_filter( 'gallus_qr_trusted_proxies', $cdn );

		$this->assertTrue( Gallus_QR_Request::is_trusted_proxy( '203.0.113.9' ) );
		$this->assertFalse( Gallus_QR_Request::is_trusted_proxy( '203.0.114.9' ) );

		remove_filter( 'gallus_qr_trusted_proxies', $cdn );
	}

	// --- Normalisation ---------------------------------------------------------

	public function test_normalize_unwraps_ipv4_mapped_forms() {
		$this->assertSame( '8.8.8.8', Gallus_QR_Request::normalize_ip( '::ffff:8.8.8.8' ) );
		$this->assertSame( '8.8.8.8', Gallus_QR_Request::normalize_ip( '::ffff:808:808' ) );
		$this->assertSame( '10.0.0.1', Gallus_QR_Request::normalize_ip( '  ::ffff:10.0.0.1  ' ) );
	}

	public function test_normalize_canonicalises_and_rejects_junk() {
		$this->assertSame( '2001:db8::1', Gallus_QR_Request::normalize_ip( '2001:0db8:0000::0001' ) );
		$this->assertSame( '203.0.113.9', Gallus_QR_Request::normalize_ip( '203.0.113.9' ) );
		$this->assertSame( '', Gallus_QR_Request::normalize_ip( 'not-an-ip' ) );
		$this->assertSame( '', Gallus_QR_Request::normalize_ip( '' ) );
	}

	// --- Country detection -----------------------------------------------------

	public function test_country_header_is_ignored_from_an_untrusted_peer() {
		$_SERVER['REMOTE_ADDR']      = '203.0.113.9';
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'JP';

		$this->assertSame( '', Gallus_QR_Analytics::detect_country() );
	}

	public function test_country_header_is_honoured_behind_a_trusted_proxy() {
		$_SERVER['REMOTE_ADDR']       = '127.0.0.1';
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'JP';

		$this->assertSame( 'JP', Gallus_QR_Analytics::detect_country() );
	}

	public function test_server_set_geoip_var_is_honoured_without_a_proxy() {
		// No HTTP_ prefix: set by mod_geoip on the server, not carried on the
		// wire, so a visitor cannot supply it.
		$_SERVER['REMOTE_ADDR']          = '203.0.113.9';
		$_SERVER['GEOIP_COUNTRY_CODE']   = 'DE';

		$this->assertSame( 'DE', Gallus_QR_Analytics::detect_country() );
	}

	public function test_country_falls_back_to_empty_when_absent() {
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$this->assertSame( '', Gallus_QR_Analytics::detect_country() );
	}
}
