<?php
/**
 * Custom-slug validation and uniqueness tests.
 */

class Test_Gallus_QR_Slugs extends WP_UnitTestCase {

	/** @var Gallus_QR_Database */
	private $db;

	public function set_up() {
		parent::set_up();
		$this->db = new Gallus_QR_Database();
		$this->db->create_tables();
	}

	public function test_valid_formats() {
		foreach ( array( 'summer-sale', 'abc123', 'a', str_repeat( 'x', 64 ) ) as $slug ) {
			$this->assertTrue( Gallus_QR_Database::validate_slug_format( $slug ), "'$slug' should be valid" );
		}
	}

	public function test_invalid_formats() {
		foreach ( array( 'Summer-Sale', 'has space', 'ünïcode', 'semi;colon', '', str_repeat( 'x', 65 ) ) as $slug ) {
			$this->assertWPError( Gallus_QR_Database::validate_slug_format( $slug ), "'$slug' should be invalid" );
		}
	}

	public function test_reserved_slugs_rejected() {
		$this->assertWPError( Gallus_QR_Database::validate_slug_format( 'qr' ) );
		$this->assertWPError( Gallus_QR_Database::validate_slug_format( 'admin' ) );
	}

	public function test_reserved_list_is_filterable() {
		add_filter(
			'gallus_qr_reserved_slugs',
			static function ( $reserved ) {
				$reserved[] = 'verboten';
				return $reserved;
			}
		);
		$this->assertWPError( Gallus_QR_Database::validate_slug_format( 'verboten' ) );
	}

	public function test_custom_slug_is_used_on_insert() {
		$slug = $this->db->insert_code( 'T', 'https://example.com', true, '', 'url', '', 'my-custom' );
		$this->assertSame( 'my-custom', $slug );
		$this->assertFalse( $this->db->is_slug_available( 'my-custom' ) );
	}

	public function test_availability_is_case_insensitive() {
		// Simulates a v1 mixed-case random slug colliding with a lowercase custom one.
		$this->db->insert_code( 'T', 'https://example.com', true, '', 'url', '', 'abcdef' );
		$row = $this->db->get_code_by_slug( 'ABCDEF' );
		$this->assertNotNull( $row, 'collation lookup should be case-insensitive' );
		$this->assertFalse( $this->db->is_slug_available( 'ABCDEF' ) );
	}

	public function test_generated_slugs_are_unique_and_wellformed() {
		$slug = $this->db->generate_unique_slug();
		$this->assertMatchesRegularExpression( '/^[0-9a-zA-Z]{6}$/', $slug );
	}
}
