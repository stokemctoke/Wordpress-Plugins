<?php
/**
 * What happens to codes and presets when their owner is deleted.
 *
 * Leaving rows pinned to a freed user ID is the bug these cover: WordPress
 * reissues IDs, and can_access_row() compares nothing but the integer, so the
 * next person to be given that number inherits a stranger's codes — including
 * the ability to repoint a QR that is already printed.
 */

class Test_Gallus_QR_User_Lifecycle extends WP_UnitTestCase {

	/** @var Gallus_QR_Database */
	private $db;

	public function set_up() {
		parent::set_up();
		$this->db = new Gallus_QR_Database();
		$this->db->create_tables();
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}

	private function code_for( $user_id, $slug ) {
		wp_set_current_user( $user_id );
		return $this->db->insert_code( 'Printed label', 'https://example.com', true, '', 'url', '', $slug );
	}

	private function owner_of( $slug ) {
		$code = $this->db->get_code_by_slug( $slug );
		return $code ? (int) $code->user_id : -1;
	}

	public function test_codes_are_reassigned_to_the_chosen_successor() {
		$leaving   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$successor = self::factory()->user->create( array( 'role' => 'editor' ) );

		$slug = $this->code_for( $leaving, 'reassign-' . uniqid() );
		$this->db->insert_preset( 'Their design', '{"fg":"#000000"}', $leaving );

		wp_delete_user( $leaving, $successor );

		$this->assertSame( $successor, $this->owner_of( $slug ) );
		$this->assertNotEmpty( $this->db->get_presets( $successor ) );
	}

	public function test_codes_survive_deletion_with_no_successor_chosen() {
		// A trackable code may already be printed on a product, so the default
		// must not be to drop the row and break it in the field.
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$leaving = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$slug = $this->code_for( $leaving, 'orphan-' . uniqid() );

		wp_delete_user( $leaving );

		$owner = $this->owner_of( $slug );

		$this->assertNotSame( -1, $owner, 'the code must still exist' );
		$this->assertNotSame( $leaving, $owner, 'it must not stay pinned to the freed ID' );
		$this->assertTrue( (bool) get_userdata( $owner ), 'the new owner must be a real user' );
	}

	public function test_a_recycled_user_id_does_not_inherit_the_departed_users_codes() {
		// The whole point: simulate WordPress reissuing the ID.
		self::factory()->user->create( array( 'role' => 'administrator' ) );
		$leaving = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$slug = $this->code_for( $leaving, 'recycled-' . uniqid() );

		wp_delete_user( $leaving );

		// Whoever next holds that integer must not be the owner.
		$this->assertNotSame( $leaving, $this->owner_of( $slug ) );

		$code = $this->db->get_code_by_slug( $slug );
		wp_set_current_user( $leaving );
		$this->assertFalse(
			Gallus_QR_Settings::can_access_row( $code ),
			'a user holding the recycled ID must not reach the row'
		);
	}

	public function test_filter_can_opt_into_deleting_instead() {
		self::factory()->user->create( array( 'role' => 'administrator' ) );
		$leaving = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$slug = $this->code_for( $leaving, 'purge-' . uniqid() );

		add_filter( 'gallus_qr_inherit_owner', '__return_zero' );
		wp_delete_user( $leaving );
		remove_filter( 'gallus_qr_inherit_owner', '__return_zero' );

		$this->assertNull( $this->db->get_code_by_slug( $slug ) );
	}

	public function test_reassign_owner_is_a_noop_for_nonsense_arguments() {
		$this->assertSame( 0, $this->db->reassign_owner( 0, 5 ) );
		$this->assertSame( 0, $this->db->reassign_owner( 5, 0 ) );
		$this->assertSame( 0, $this->db->reassign_owner( 5, 5 ) );
	}
}
