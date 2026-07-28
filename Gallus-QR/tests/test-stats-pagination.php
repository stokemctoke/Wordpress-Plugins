<?php
/**
 * Stats screen query load.
 *
 * The screen issues four queries per rendered row, so the row count has to be
 * bounded — otherwise page cost grows with the number of codes on the site,
 * and an administrator's default view spans every user's codes.
 */

class Test_Gallus_QR_Stats_Pagination extends WP_UnitTestCase {

	/** @var Gallus_QR_Database */
	private $db;

	public function set_up() {
		parent::set_up();
		$this->db = new Gallus_QR_Database();
		$this->db->create_tables();
		unset( $_GET['gqr_paged'], $_GET['gqr_owner'] );
	}

	public function tear_down() {
		unset( $_GET['gqr_paged'], $_GET['gqr_owner'] );
		parent::tear_down();
	}

	private function make_codes( $owner_id, $count ) {
		wp_set_current_user( $owner_id );
		for ( $i = 0; $i < $count; $i++ ) {
			$this->db->insert_code( 'Code ' . $i, 'https://example.com/' . $i, true, '', 'url', '', 'page-' . $i . '-' . uniqid() );
		}
	}

	public function test_get_codes_owned_respects_limit_and_offset() {
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->make_codes( $owner, 7 );

		$first = $this->db->get_codes_owned( $owner, 3, 0 );
		$next  = $this->db->get_codes_owned( $owner, 3, 3 );

		$this->assertCount( 3, $first );
		$this->assertCount( 3, $next );

		$first_ids = wp_list_pluck( $first, 'id' );
		$next_ids  = wp_list_pluck( $next, 'id' );

		$this->assertEmpty( array_intersect( $first_ids, $next_ids ), 'pages must not overlap' );
	}

	public function test_get_codes_owned_is_scoped_to_the_owner() {
		$alice = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$bob   = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->make_codes( $alice, 3 );
		$this->make_codes( $bob, 2 );

		$this->assertCount( 3, $this->db->get_codes_owned( $alice, 50, 0 ) );
		$this->assertCount( 2, $this->db->get_codes_owned( $bob, 50, 0 ) );
		$this->assertCount( 5, $this->db->get_codes_owned( null, 50, 0 ) );
	}

	public function test_current_page_bounds_the_row_count() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->make_codes( $admin, 25 );

		$stats = new Gallus_QR_Admin_Stats( $this->db );
		$page  = $stats->current_page();

		$this->assertSame( 25, $page['total'] );
		$this->assertSame( 20, $page['per_page'] );
		$this->assertSame( 2, $page['pages'] );
		$this->assertCount( 20, $page['codes'], 'page one must not render all 25' );
	}

	public function test_current_page_honours_the_page_argument() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->make_codes( $admin, 25 );

		$_GET['gqr_paged'] = '2';

		$stats = new Gallus_QR_Admin_Stats( $this->db );
		$page  = $stats->current_page();

		$this->assertSame( 2, $page['paged'] );
		$this->assertCount( 5, $page['codes'] );
	}

	public function test_out_of_range_page_is_clamped() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->make_codes( $admin, 3 );

		$stats = new Gallus_QR_Admin_Stats( $this->db );

		$_GET['gqr_paged'] = '9999';
		$this->assertSame( 1, $stats->current_page()['paged'] );

		$_GET['gqr_paged'] = '0';
		$this->assertSame( 1, $stats->current_page()['paged'] );

		$_GET['gqr_paged'] = 'not-a-number';
		$this->assertSame( 1, $stats->current_page()['paged'] );
	}

	public function test_per_page_is_filterable_but_clamped() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->make_codes( $admin, 5 );

		$huge = static function () {
			return 100000;
		};
		add_filter( 'gallus_qr_stats_per_page', $huge );

		$stats = new Gallus_QR_Admin_Stats( $this->db );
		$page  = $stats->current_page();

		remove_filter( 'gallus_qr_stats_per_page', $huge );

		$this->assertSame( 200, $page['per_page'], 'an unbounded per_page must be clamped' );
	}
}
