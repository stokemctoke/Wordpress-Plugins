<?php
/**
 * Embed rules for [gallus_qr] and the block.
 *
 * URL codes only ever expose a link, so they embed anywhere. Every other type
 * encodes its payload verbatim in the page markup — a WiFi code carries the
 * network password — so those must only render on a post by the code's owner.
 */

class Test_Gallus_QR_Embed_Rules extends WP_UnitTestCase {

	/** @var Gallus_QR_Database */
	private $db;

	/** @var Gallus_QR_Shortcode */
	private $shortcode;

	public function set_up() {
		parent::set_up();
		$this->db        = new Gallus_QR_Database();
		$this->shortcode = new Gallus_QR_Shortcode( $this->db );
		$this->db->create_tables();
	}

	public function tear_down() {
		unset( $GLOBALS['post'] );
		parent::tear_down();
	}

	/** Put the loop on a post authored by $author_id. */
	private function on_post_by( $author_id ) {
		$post_id         = self::factory()->post->create( array( 'post_author' => $author_id ) );
		$GLOBALS['post'] = get_post( $post_id );
		return $post_id;
	}

	/** Save a code of $type owned by $owner_id and return its slug. */
	private function code_owned_by( $owner_id, $type = 'url', $destination = 'https://example.com' ) {
		wp_set_current_user( $owner_id );
		return $this->db->insert_code( 'Test', $destination, 'url' === $type, '', $type, '' );
	}

	public function test_wifi_code_does_not_render_on_another_authors_post() {
		$owner    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$intruder = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$slug = $this->code_owned_by( $owner, 'wifi', 'WIFI:T:WPA;S:HomeNet;P:hunter2;;' );
		$this->on_post_by( $intruder );

		$html = $this->shortcode->render_shortcode( array( 'slug' => $slug ) );

		$this->assertSame( '', $html );
		$this->assertStringNotContainsString( 'hunter2', $html );
	}

	public function test_wifi_code_renders_on_its_owners_own_post() {
		$owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$slug = $this->code_owned_by( $owner, 'wifi', 'WIFI:T:WPA;S:HomeNet;P:hunter2;;' );
		$this->on_post_by( $owner );

		$html = $this->shortcode->render_shortcode( array( 'slug' => $slug ) );

		$this->assertStringContainsString( 'gallus-qr-embed', $html );
	}

	public function test_vcard_code_does_not_leak_to_another_author() {
		$owner    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$intruder = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$slug = $this->code_owned_by( $owner, 'vcard', "BEGIN:VCARD\r\nTEL;TYPE=CELL:+447700900123\r\nEND:VCARD" );
		$this->on_post_by( $intruder );

		$html = $this->shortcode->render_shortcode( array( 'slug' => $slug ) );

		$this->assertSame( '', $html );
		$this->assertStringNotContainsString( '447700900123', $html );
	}

	public function test_url_codes_embed_regardless_of_author() {
		$owner    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$somebody = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$slug = $this->code_owned_by( $owner, 'url', 'https://example.com/page' );
		$this->on_post_by( $somebody );

		$html = $this->shortcode->render_shortcode( array( 'slug' => $slug ) );

		$this->assertStringContainsString( 'gallus-qr-embed', $html );
	}

	public function test_unknown_slug_renders_nothing() {
		$this->on_post_by( self::factory()->user->create() );

		$this->assertSame( '', $this->shortcode->render_shortcode( array( 'slug' => 'no-such-slug' ) ) );
	}

	public function test_filter_can_override_the_embed_decision() {
		$owner    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$intruder = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$slug = $this->code_owned_by( $owner, 'wifi', 'WIFI:T:WPA;S:HomeNet;P:hunter2;;' );
		$this->on_post_by( $intruder );

		add_filter( 'gallus_qr_can_embed_code', '__return_true' );
		$html = $this->shortcode->render_shortcode( array( 'slug' => $slug ) );
		remove_filter( 'gallus_qr_can_embed_code', '__return_true' );

		$this->assertStringContainsString( 'gallus-qr-embed', $html );
	}
}
