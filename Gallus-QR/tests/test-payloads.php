<?php
/**
 * Payload builder tests: exact encoded strings and escaping rules. These
 * must stay in lockstep with assets/js/payloads.js.
 */

class Test_Gallus_QR_Payloads extends WP_UnitTestCase {

	public function test_wifi_basic() {
		$out = Gallus_QR_Payloads::build(
			'wifi',
			array(
				'ssid'       => 'MyNet',
				'password'   => 'hunter2',
				'encryption' => 'WPA',
			)
		);
		$this->assertSame( 'WIFI:T:WPA;S:MyNet;P:hunter2;;', $out );
	}

	public function test_wifi_escapes_special_chars() {
		$out = Gallus_QR_Payloads::build(
			'wifi',
			array(
				'ssid'       => 'Cafe;Net,2:"a"',
				'password'   => 'p\\ss;word',
				'encryption' => 'WPA',
			)
		);
		$this->assertSame( 'WIFI:T:WPA;S:Cafe\\;Net\\,2\\:\\"a\\";P:p\\\\ss\\;word;;', $out );
	}

	public function test_wifi_open_network_needs_no_password() {
		$out = Gallus_QR_Payloads::build(
			'wifi',
			array(
				'ssid'       => 'OpenNet',
				'encryption' => 'nopass',
			)
		);
		$this->assertSame( 'WIFI:T:nopass;S:OpenNet;;', $out );
	}

	public function test_wifi_hidden_flag() {
		$out = Gallus_QR_Payloads::build(
			'wifi',
			array(
				'ssid'       => 'Secret',
				'password'   => 'pw',
				'encryption' => 'WPA',
				'hidden'     => true,
			)
		);
		$this->assertSame( 'WIFI:T:WPA;S:Secret;P:pw;H:true;;', $out );
	}

	public function test_wifi_requires_ssid() {
		$this->assertWPError( Gallus_QR_Payloads::build( 'wifi', array( 'password' => 'x' ) ) );
	}

	public function test_vcard_contains_required_lines() {
		$out = Gallus_QR_Payloads::build(
			'vcard',
			array(
				'first' => 'Stoke',
				'last'  => 'McToke',
				'org'   => 'Gallus, Gadgets',
				'email' => 'stoke@example.com',
			)
		);
		$this->assertStringContainsString( "BEGIN:VCARD\r\nVERSION:3.0", $out );
		$this->assertStringContainsString( 'N:McToke;Stoke;;;', $out );
		$this->assertStringContainsString( 'FN:Stoke McToke', $out );
		$this->assertStringContainsString( 'ORG:Gallus\\, Gadgets', $out );
		$this->assertStringContainsString( 'EMAIL:stoke@example.com', $out );
		$this->assertStringEndsWith( 'END:VCARD', $out );
	}

	public function test_vcard_requires_a_name() {
		$this->assertWPError( Gallus_QR_Payloads::build( 'vcard', array( 'org' => 'ACME' ) ) );
	}

	public function test_email_with_subject_and_body() {
		$out = Gallus_QR_Payloads::build(
			'email',
			array(
				'to'      => 'a@b.com',
				'subject' => 'Hi there',
				'body'    => 'Line one',
			)
		);
		$this->assertSame( 'mailto:a@b.com?subject=Hi%20there&body=Line%20one', $out );
	}

	public function test_email_requires_valid_address() {
		$this->assertWPError( Gallus_QR_Payloads::build( 'email', array( 'to' => 'not-an-email' ) ) );
	}

	public function test_sms_strips_number_formatting() {
		$out = Gallus_QR_Payloads::build(
			'sms',
			array(
				'number'  => '+44 (0)7911 123-456',
				'message' => 'Hello',
			)
		);
		$this->assertSame( 'SMSTO:+4407911123456:Hello', $out );
	}

	public function test_tel() {
		$this->assertSame( 'tel:+441234567890', Gallus_QR_Payloads::build( 'tel', array( 'number' => '+44 1234 567890' ) ) );
	}

	public function test_event_requires_summary_and_start() {
		$this->assertWPError( Gallus_QR_Payloads::build( 'event', array( 'summary' => 'Party' ) ) );
		$this->assertWPError( Gallus_QR_Payloads::build( 'event', array( 'start' => '2026-07-16T12:00' ) ) );
	}

	public function test_event_builds_vcalendar() {
		update_option( 'timezone_string', 'UTC' );
		$out = Gallus_QR_Payloads::build(
			'event',
			array(
				'summary' => 'Launch',
				'start'   => '2026-07-16T12:00',
				'end'     => '2026-07-16T13:30',
			)
		);
		$this->assertStringContainsString( 'BEGIN:VEVENT', $out );
		$this->assertStringContainsString( 'SUMMARY:Launch', $out );
		$this->assertStringContainsString( 'DTSTART:20260716T120000Z', $out );
		$this->assertStringContainsString( 'DTEND:20260716T133000Z', $out );
	}

	public function test_event_converts_site_timezone_to_utc() {
		update_option( 'timezone_string', 'Europe/London' ); // BST in July = UTC+1
		$out = Gallus_QR_Payloads::build(
			'event',
			array(
				'summary' => 'Launch',
				'start'   => '2026-07-16T12:00',
			)
		);
		$this->assertStringContainsString( 'DTSTART:20260716T110000Z', $out );
		update_option( 'timezone_string', 'UTC' );
	}

	public function test_text_passthrough() {
		$this->assertSame( "line1\nline2", Gallus_QR_Payloads::build( 'text', array( 'text' => "line1\nline2" ) ) );
	}

	public function test_unknown_type_is_error() {
		$this->assertWPError( Gallus_QR_Payloads::build( 'carrier-pigeon', array() ) );
	}

	public function test_only_url_is_trackable() {
		$this->assertTrue( Gallus_QR_Payloads::is_trackable_type( 'url' ) );
		foreach ( array( 'text', 'wifi', 'vcard', 'email', 'sms', 'tel', 'event' ) as $type ) {
			$this->assertFalse( Gallus_QR_Payloads::is_trackable_type( $type ), "$type must not be trackable" );
		}
	}

	public function test_apply_utm_appends_only_nonempty_params() {
		$out = Gallus_QR_Payloads::apply_utm(
			'https://example.com/page',
			array(
				'source'   => 'flyer',
				'medium'   => '',
				'campaign' => 'summer 2026',
			)
		);
		$this->assertStringContainsString( 'utm_source=flyer', $out );
		$this->assertStringNotContainsString( 'utm_medium', $out );
		$this->assertStringContainsString( 'utm_campaign=summer%202026', $out );
	}
}
