<?php
/**
 * Scan-counting integrity: client IP resolution (X-Forwarded-For is only
 * believed behind a trusted proxy) and the per-visitor dedupe window that
 * stops a request loop from burning through a code's scan limit.
 *
 * Tested through reflection — the methods are internal to the redirect handler.
 */

class Test_Gallus_QR_Scan_Integrity extends WP_UnitTestCase {

	/** @var Gallus_QR_Redirect */
	private $redirect;

	public function set_up() {
		parent::set_up();
		$this->redirect = new Gallus_QR_Redirect( new Gallus_QR_Database() );
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	public function tear_down() {
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
		parent::tear_down();
	}

	private function invoke( $method, ...$args ) {
		$ref = new ReflectionMethod( Gallus_QR_Redirect::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( $this->redirect, ...$args );
	}

	// --- Client IP ------------------------------------------------------------

	public function test_direct_public_request_uses_remote_addr() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->assertSame( '203.0.113.9', Gallus_QR_Request::client_ip() );
	}

	public function test_forwarded_header_is_ignored_from_an_untrusted_peer() {
		// The spoofing case: a public client inventing its own XFF.
		$_SERVER['REMOTE_ADDR']          = '203.0.113.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';

		$this->assertSame( '203.0.113.9', Gallus_QR_Request::client_ip() );
	}

	public function test_forwarded_header_is_used_behind_a_local_proxy() {
		// The CloudPanel/nginx case: request arrives from loopback.
		$_SERVER['REMOTE_ADDR']          = '127.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';

		$this->assertSame( '198.51.100.7', Gallus_QR_Request::client_ip() );
	}

	public function test_client_supplied_prefix_cannot_forge_the_address() {
		// nginx's $proxy_add_x_forwarded_for APPENDS the real peer, so anything
		// the visitor sent themselves ends up on the left. Reading left-to-right
		// would hand them a free identity on every request.
		$_SERVER['REMOTE_ADDR']          = '127.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 203.0.113.9';

		$this->assertSame( '203.0.113.9', Gallus_QR_Request::client_ip() );
	}

	public function test_internal_proxy_hops_are_walked_through() {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = ' 198.51.100.7 , 10.0.0.5 ';

		$this->assertSame( '198.51.100.7', Gallus_QR_Request::client_ip() );
	}

	public function test_ipv4_mapped_public_peer_is_not_trusted() {
		// A dual-stack listener reports ordinary IPv4 visitors in this form.
		// filter_var's NO_RES_RANGE flag calls the whole ::ffff:0:0/96 block
		// reserved, which would make every host on the internet a trusted proxy.
		$_SERVER['REMOTE_ADDR']          = '::ffff:203.0.113.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';

		$this->assertSame( '203.0.113.9', Gallus_QR_Request::client_ip() );
	}

	public function test_ipv4_mapped_private_peer_is_still_trusted() {
		$_SERVER['REMOTE_ADDR']          = '::ffff:10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';

		$this->assertSame( '198.51.100.7', Gallus_QR_Request::client_ip() );
	}

	public function test_garbage_forwarded_values_fall_back_to_the_peer() {
		$_SERVER['REMOTE_ADDR']          = '127.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip, <script>';

		$this->assertSame( '127.0.0.1', Gallus_QR_Request::client_ip() );
	}

	public function test_invalid_remote_addr_yields_empty_string() {
		$_SERVER['REMOTE_ADDR'] = 'definitely-not-an-ip';
		$this->assertSame( '', Gallus_QR_Request::client_ip() );
	}

	public function test_hash_is_stable_and_not_the_raw_address() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		$first  = $this->invoke( 'client_ip_hash' );
		$second = $this->invoke( 'client_ip_hash' );

		$this->assertSame( $first, $second );
		$this->assertSame( 64, strlen( $first ) );
		$this->assertStringNotContainsString( '203.0.113.9', $first );
	}

	// --- Scan dedupe ----------------------------------------------------------

	public function test_repeat_hits_from_one_visitor_are_not_counted_twice() {
		$hash = str_repeat( 'a', 64 );

		$this->assertTrue( $this->invoke( 'claim_scan', 1, $hash ) );
		$this->assertFalse( $this->invoke( 'claim_scan', 1, $hash ) );
		$this->assertFalse( $this->invoke( 'claim_scan', 1, $hash ) );
	}

	public function test_different_visitors_are_counted_separately() {
		$this->assertTrue( $this->invoke( 'claim_scan', 1, str_repeat( 'a', 64 ) ) );
		$this->assertTrue( $this->invoke( 'claim_scan', 1, str_repeat( 'b', 64 ) ) );
	}

	public function test_same_visitor_is_counted_once_per_code() {
		$hash = str_repeat( 'c', 64 );

		$this->assertTrue( $this->invoke( 'claim_scan', 1, $hash ) );
		$this->assertTrue( $this->invoke( 'claim_scan', 2, $hash ) );
	}

	public function test_dedupe_can_be_disabled_by_filter() {
		add_filter( 'gallus_qr_scan_dedupe_window', '__return_zero' );

		$hash = str_repeat( 'd', 64 );
		$this->assertTrue( $this->invoke( 'claim_scan', 3, $hash ) );
		$this->assertTrue( $this->invoke( 'claim_scan', 3, $hash ) );

		remove_filter( 'gallus_qr_scan_dedupe_window', '__return_zero' );
	}

	// --- Cap state ------------------------------------------------------------

	public function test_claim_is_atomic_across_repeated_attempts() {
		// The dedupe claim must be won exactly once, by whoever gets there
		// first — not "whoever last checked an absent transient".
		$db = new Gallus_QR_Database();

		$this->assertTrue( $db->claim_once( 'atomic-test', 60 ) );
		for ( $i = 0; $i < 25; $i++ ) {
			$this->assertFalse( $db->claim_once( 'atomic-test', 60 ), 'attempt ' . $i . ' should lose' );
		}
	}

	public function test_expired_claims_are_reclaimable_and_prunable() {
		$db = new Gallus_QR_Database();

		$this->assertTrue( $db->claim_once( 'expiry-test', 1 ) );
		$this->assertFalse( $db->claim_once( 'expiry-test', 1 ) );

		// Age the claim past its expiry rather than sleeping.
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$db->claims_table()} SET expires_at = %s WHERE claim_key = %s",
				'2000-01-01 00:00:00',
				hash( 'sha256', 'expiry-test' )
			)
		);

		$this->assertTrue( $db->claim_once( 'expiry-test', 60 ), 'an expired claim must be reclaimable' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$db->claims_table()} SET expires_at = %s WHERE claim_key = %s",
				'2000-01-01 00:00:00',
				hash( 'sha256', 'expiry-test' )
			)
		);
		$this->assertGreaterThan( 0, $db->prune_claims() );
	}

	// --- Cap enforcement ------------------------------------------------------

	public function test_capped_codes_spend_at_a_bounded_rate() {
		$code = (object) array(
			'id'         => 4242,
			'max_scans'  => 1000,
			'scan_count' => 0,
		);

		// Per-code, independent of who is asking: an attacker rotating source
		// addresses gets a fresh dedupe bucket but not a fresh cap allowance.
		$this->assertTrue( $this->invoke( 'may_spend_cap', $code ) );
		$this->assertFalse( $this->invoke( 'may_spend_cap', $code ) );
		$this->assertFalse( $this->invoke( 'may_spend_cap', $code ) );
	}

	public function test_uncapped_codes_are_never_rate_limited() {
		$code = (object) array(
			'id'         => 4243,
			'max_scans'  => 0,
			'scan_count' => 99999,
		);

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( $this->invoke( 'may_spend_cap', $code ) );
		}
	}

	public function test_cap_spend_limit_can_be_disabled_by_filter() {
		add_filter( 'gallus_qr_cap_spend_interval', '__return_zero' );

		$code = (object) array(
			'id'         => 4244,
			'max_scans'  => 10,
			'scan_count' => 0,
		);

		$this->assertTrue( $this->invoke( 'may_spend_cap', $code ) );
		$this->assertTrue( $this->invoke( 'may_spend_cap', $code ) );

		remove_filter( 'gallus_qr_cap_spend_interval', '__return_zero' );
	}

	public function test_try_count_scan_separates_cap_reached_from_failure() {
		$db   = new Gallus_QR_Database();
		$slug = 'cap-' . uniqid();

		$this->assertSame( $slug, $db->insert_code( 'capped', 'https://example.test', true, '', 'url', '', $slug ) );

		$code = $db->get_code_by_slug( $slug );
		$id   = (int) $code->id;

		global $wpdb;
		$wpdb->update( $db->codes_table(), array( 'max_scans' => 1 ), array( 'id' => $id ) );

		$this->assertTrue( $db->try_count_scan( $id ), 'first scan counts' );
		$this->assertFalse( $db->try_count_scan( $id ), 'cap reached is false' );
		$this->assertNotNull( $db->try_count_scan( $id ), 'a reached cap is not a database error' );
	}

	public function test_is_capped_only_when_a_limit_is_set_and_reached() {
		$uncapped = (object) array(
			'max_scans'  => 0,
			'scan_count' => 5000,
		);
		$under    = (object) array(
			'max_scans'  => 10,
			'scan_count' => 9,
		);
		$reached  = (object) array(
			'max_scans'  => 10,
			'scan_count' => 10,
		);

		$this->assertFalse( $this->invoke( 'is_capped', $uncapped ) );
		$this->assertFalse( $this->invoke( 'is_capped', $under ) );
		$this->assertTrue( $this->invoke( 'is_capped', $reached ) );
	}
}
