<?php
/**
 * Design presets are owned rows, exactly like codes: you see and delete your
 * own, administrators reach everything, and nobody touches a stranger's.
 */

class Test_Gallus_QR_Preset_Ownership extends WP_UnitTestCase {

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

		$id     = $this->db->insert_preset( 'Mine', '{"fg":"#000000"}' );
		$preset = $this->db->get_preset_by_id( $id );

		$this->assertNotNull( $preset );
		$this->assertSame( $user_id, (int) $preset->user_id );
	}

	public function test_listing_is_scoped_to_the_owner() {
		$alice = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$bob   = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $alice );
		$this->db->insert_preset( 'Alice preset', '{"fg":"#111111"}' );

		wp_set_current_user( $bob );
		$this->db->insert_preset( 'Bob preset', '{"fg":"#222222"}' );

		$alice_presets = $this->db->get_presets( $alice );
		$bob_presets   = $this->db->get_presets( $bob );
		$all_presets   = $this->db->get_presets( null );

		$this->assertCount( 1, $alice_presets );
		$this->assertSame( 'Alice preset', $alice_presets[0]->name );
		$this->assertCount( 1, $bob_presets );
		$this->assertSame( 'Bob preset', $bob_presets[0]->name );
		$this->assertCount( 2, $all_presets );
	}

	public function test_can_access_row_is_owner_or_admin() {
		$owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $owner );
		$preset = $this->db->get_preset_by_id( $this->db->insert_preset( 'Owned', '{}' ) );

		wp_set_current_user( $owner );
		$this->assertTrue( Gallus_QR_Settings::can_access_row( $preset ) );

		wp_set_current_user( $other );
		$this->assertFalse( Gallus_QR_Settings::can_access_row( $preset ) );

		wp_set_current_user( $admin );
		$this->assertTrue( Gallus_QR_Settings::can_access_row( $preset ) );
	}

	public function test_subscribers_only_see_their_own_via_ownership_scope() {
		$alice = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$bob   = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $alice );
		$this->db->insert_preset( 'Alice preset', '{}' );
		wp_set_current_user( $bob );
		$this->db->insert_preset( 'Bob preset', '{}' );

		wp_set_current_user( $alice );
		$visible = $this->db->get_presets( Gallus_QR_Settings::ownership_scope() );

		$this->assertCount( 1, $visible );
		$this->assertSame( 'Alice preset', $visible[0]->name );
	}

	public function test_admin_ownership_scope_sees_every_preset() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $subscriber );
		$this->db->insert_preset( 'Theirs', '{}' );

		wp_set_current_user( $admin );
		$this->db->insert_preset( 'Mine', '{}' );

		$this->assertCount( 2, $this->db->get_presets( Gallus_QR_Settings::ownership_scope() ) );
	}

	public function test_missing_preset_is_not_accessible() {
		$this->assertNull( $this->db->get_preset_by_id( 999999 ) );
		$this->assertFalse( Gallus_QR_Settings::can_access_row( null ) );
	}
}
