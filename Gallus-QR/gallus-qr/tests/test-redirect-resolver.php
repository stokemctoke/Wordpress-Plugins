<?php
/**
 * Destination-mode resolution and A/B variant picking (tested through
 * reflection — the methods are internal to the redirect handler).
 */

class Test_Gallus_QR_Redirect_Resolver extends WP_UnitTestCase {

	/** @var Gallus_QR_Redirect */
	private $redirect;

	public function set_up() {
		parent::set_up();
		$this->redirect = new Gallus_QR_Redirect( new Gallus_QR_Database() );
	}

	private function invoke( $method, ...$args ) {
		$ref = new ReflectionMethod( Gallus_QR_Redirect::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( $this->redirect, ...$args );
	}

	private function code( array $overrides = array() ) {
		return (object) array_merge(
			array(
				'destination'   => 'https://example.com/a',
				'destination_b' => 'https://example.com/b',
				'dest_mode'     => 'single',
				'switch_at'     => null,
				'ab_split'      => 50,
			),
			$overrides
		);
	}

	public function test_single_mode_always_a() {
		$code = $this->code();
		$this->assertSame( 'https://example.com/a', $this->invoke( 'resolve_destination', $code, '2026-07-16 12:00:00', '' ) );
	}

	public function test_schedule_before_switch_uses_a() {
		$code = $this->code(
			array(
				'dest_mode' => 'schedule',
				'switch_at' => '2026-08-01 00:00:00',
			)
		);
		$this->assertSame( 'https://example.com/a', $this->invoke( 'resolve_destination', $code, '2026-07-16 12:00:00', '' ) );
	}

	public function test_schedule_after_switch_uses_b() {
		$code = $this->code(
			array(
				'dest_mode' => 'schedule',
				'switch_at' => '2026-08-01 00:00:00',
			)
		);
		$this->assertSame( 'https://example.com/b', $this->invoke( 'resolve_destination', $code, '2026-08-01 00:00:00', '' ) );
	}

	public function test_schedule_without_b_falls_back_to_a() {
		$code = $this->code(
			array(
				'dest_mode'     => 'schedule',
				'destination_b' => null,
				'switch_at'     => '2026-01-01 00:00:00',
			)
		);
		$this->assertSame( 'https://example.com/a', $this->invoke( 'resolve_destination', $code, '2026-07-16 12:00:00', '' ) );
	}

	public function test_ab_variant_b_gets_destination_b() {
		$code = $this->code( array( 'dest_mode' => 'ab' ) );
		$this->assertSame( 'https://example.com/b', $this->invoke( 'resolve_destination', $code, '2026-07-16 12:00:00', 'B' ) );
		$this->assertSame( 'https://example.com/a', $this->invoke( 'resolve_destination', $code, '2026-07-16 12:00:00', 'A' ) );
	}

	public function test_variant_only_picked_in_ab_mode() {
		$this->assertSame( '', $this->invoke( 'pick_variant', $this->code() ) );
		$this->assertSame( '', $this->invoke( 'pick_variant', $this->code( array( 'dest_mode' => 'schedule' ) ) ) );
	}

	public function test_ab_split_extremes() {
		$all_b = $this->code(
			array(
				'dest_mode' => 'ab',
				'ab_split'  => 100,
			)
		);
		$all_a = $this->code(
			array(
				'dest_mode' => 'ab',
				'ab_split'  => 0,
			)
		);
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertSame( 'B', $this->invoke( 'pick_variant', $all_b ) );
			$this->assertSame( 'A', $this->invoke( 'pick_variant', $all_a ) );
		}
	}

	public function test_scan_cap_is_atomic_and_exact() {
		$db = new Gallus_QR_Database();
		$db->create_tables();
		$slug = $db->insert_code( 'Capped', 'https://example.com', true );
		$code = $db->get_code_by_slug( $slug );
		$db->update_code_fields( (int) $code->id, array( 'max_scans' => 3 ) );

		$allowed = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			if ( $db->try_count_scan( (int) $code->id ) ) {
				$allowed++;
			}
		}
		$this->assertSame( 3, $allowed, 'exactly max_scans scans may pass the gate' );
	}
}
