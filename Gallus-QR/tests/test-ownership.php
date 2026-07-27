<?php
/**
 * Per-user code ownership: list queries and access checks.
 */

class Test_Gallus_QR_Ownership extends WP_UnitTestCase {

	/** @var Gallus_QR_Database */
	private $db;

	public function set_up() {
		parent::set_up();
		$this->db = new Gallus_QR_Database();
		$this->db->create_tables();
	}

	public function test_insert_stamps_current_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$slug = $this->db->insert_code( 'Mine', 'https://example.com', true );
		$code = $this->db->get_code_by_slug( $slug );

		$this->assertNotNull( $code );
		$this->assertSame( $user_id, (int) $code->user_id );
	}

	public function test_list_queries_respect_owner_filter() {
		$alice = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$bob   = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $alice );
		$this->db->insert_code( 'Alice', 'https://example.com/a', true );

		wp_set_current_user( $bob );
		$this->db->insert_code( 'Bob', 'https://example.com/b', true );

		$alice_codes = $this->db->get_codes_with_counts( $alice );
		$bob_codes   = $this->db->get_codes_with_counts( $bob );
		$all_codes   = $this->db->get_codes_with_counts( null );

		$this->assertCount( 1, $alice_codes );
		$this->assertSame( 'Alice', $alice_codes[0]->title );
		$this->assertCount( 1, $bob_codes );
		$this->assertSame( 'Bob', $bob_codes[0]->title );
		$this->assertCount( 2, $all_codes );
	}

	public function test_resolve_owner_filter_for_admin() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $admin );

		$this->assertNull( Gallus_QR_Settings::resolve_owner_filter( 'all' ) );
		$this->assertSame( $admin, Gallus_QR_Settings::resolve_owner_filter( 'me' ) );
		$this->assertSame( $other, Gallus_QR_Settings::resolve_owner_filter( (string) $other ) );
	}

	public function test_resolve_owner_filter_forces_self_for_subscribers() {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );

		$this->assertSame( $user, Gallus_QR_Settings::resolve_owner_filter( 'all' ) );
		$this->assertSame( $user, Gallus_QR_Settings::resolve_owner_filter( '1' ) );
	}

	public function test_can_access_code_is_owner_or_admin() {
		$owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $owner );
		$slug = $this->db->insert_code( 'Owned', 'https://example.com', true );
		$code = $this->db->get_code_by_slug( $slug );

		wp_set_current_user( $owner );
		$this->assertTrue( Gallus_QR_Settings::can_access_code( $code ) );

		wp_set_current_user( $other );
		$this->assertFalse( Gallus_QR_Settings::can_access_code( $code ) );

		wp_set_current_user( $admin );
		$this->assertTrue( Gallus_QR_Settings::can_access_code( $code ) );
	}
}
