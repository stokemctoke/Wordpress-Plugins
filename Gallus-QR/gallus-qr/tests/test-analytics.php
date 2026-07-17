<?php
/**
 * UA parsing, bot detection and country-header tests.
 */

class Test_Gallus_QR_Analytics extends WP_UnitTestCase {

	public function test_iphone_ua() {
		$parsed = Gallus_QR_Analytics::parse_user_agent(
			'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1'
		);
		$this->assertSame( 'Mobile', $parsed['device'] );
		$this->assertSame( 'iOS', $parsed['os'] );
		$this->assertSame( 'Safari', $parsed['browser'] );
	}

	public function test_android_chrome_ua() {
		$parsed = Gallus_QR_Analytics::parse_user_agent(
			'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36'
		);
		$this->assertSame( 'Mobile', $parsed['device'] );
		$this->assertSame( 'Android', $parsed['os'] );
		$this->assertSame( 'Chrome', $parsed['browser'] );
	}

	public function test_ipad_is_tablet() {
		$parsed = Gallus_QR_Analytics::parse_user_agent(
			'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1'
		);
		$this->assertSame( 'Tablet', $parsed['device'] );
	}

	public function test_windows_edge_ua() {
		$parsed = Gallus_QR_Analytics::parse_user_agent(
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0'
		);
		$this->assertSame( 'Desktop', $parsed['device'] );
		$this->assertSame( 'Windows', $parsed['os'] );
		$this->assertSame( 'Edge', $parsed['browser'] );
	}

	public function test_bots_are_detected() {
		$bots = array(
			'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
			'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
			'curl/8.5.0',
			'python-requests/2.31',
			'', // empty UA — cameras always send one
		);
		foreach ( $bots as $ua ) {
			$this->assertTrue( Gallus_QR_Analytics::is_bot( $ua ), "'$ua' should be a bot" );
		}
	}

	public function test_real_phones_are_not_bots() {
		$this->assertFalse(
			Gallus_QR_Analytics::is_bot(
				'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 Version/17.4 Mobile/15E148 Safari/604.1'
			)
		);
	}

	public function test_country_from_cloudflare_header() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'GB';
		$this->assertSame( 'GB', Gallus_QR_Analytics::detect_country() );
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
	}

	public function test_country_rejects_invalid_values() {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'XX'; // Cloudflare's "unknown"
		$this->assertSame( '', Gallus_QR_Analytics::detect_country() );

		$_SERVER['HTTP_CF_IPCOUNTRY'] = '<script>';
		$this->assertSame( '', Gallus_QR_Analytics::detect_country() );

		unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
		$this->assertSame( '', Gallus_QR_Analytics::detect_country() );
	}
}
