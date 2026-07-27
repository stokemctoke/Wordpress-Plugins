<?php
/**
 * REST design-sanitizer tests (private method, tested through reflection):
 * strict whitelisting, enum validation, and v1 back-compat.
 */

class Test_Gallus_QR_Design_Sanitizer extends WP_UnitTestCase {

	private function sanitize( $raw ) {
		$rest = new Gallus_QR_REST( new Gallus_QR_Database() );
		$ref  = new ReflectionMethod( Gallus_QR_REST::class, 'sanitize_design' );
		$ref->setAccessible( true );
		$json = $ref->invoke( $rest, $raw );
		return '' === $json ? null : json_decode( $json, true );
	}

	public function test_empty_input_yields_empty_string() {
		$this->assertNull( $this->sanitize( array() ) );
		$this->assertNull( $this->sanitize( 'not-an-array' ) );
	}

	public function test_v1_design_shape_still_sanitizes() {
		$out = $this->sanitize(
			array(
				'dotStyle'    => 'rounded',
				'cornerStyle' => 'extra-rounded',
				'fg'          => '#112233',
				'bg'          => '#ffffff',
				'size'        => 512,
				'logo'        => '',
			)
		);
		$this->assertSame( 'rounded', $out['dotStyle'] );
		$this->assertSame( 'extra-rounded', $out['cornerStyle'] );
		$this->assertSame( '#112233', $out['fg'] );
	}

	public function test_unknown_enums_fall_back() {
		$out = $this->sanitize(
			array(
				'dotStyle'    => 'sparkles',
				'cornerStyle' => 'triangle',
				'gradient'    => 'rainbow',
			)
		);
		$this->assertSame( 'square', $out['dotStyle'] );
		$this->assertSame( 'square', $out['cornerStyle'] );
		$this->assertSame( 'none', $out['gradient'] );
	}

	public function test_bad_colors_fall_back() {
		$out = $this->sanitize(
			array(
				'fg' => 'javascript:alert(1)',
				'bg' => 'red',
			)
		);
		$this->assertSame( '#000000', $out['fg'] );
		$this->assertSame( '#ffffff', $out['bg'] );
	}

	public function test_size_is_clamped() {
		$this->assertSame( 1024, $this->sanitize( array( 'size' => 99999 ) )['size'] );
		$this->assertSame( 128, $this->sanitize( array( 'size' => 1 ) )['size'] );
	}

	public function test_logo_must_be_image_data_url() {
		$good = 'data:image/png;base64,iVBORw0KGgo=';
		$this->assertSame( $good, $this->sanitize( array( 'logo' => $good ) )['logo'] );
		$this->assertSame( '', $this->sanitize( array( 'logo' => 'data:text/html;base64,PHNjcmlwdD4=' ) )['logo'] );
		$this->assertSame( '', $this->sanitize( array( 'logo' => 'https://evil.example/x.png' ) )['logo'] );
	}

	public function test_logo_url_is_resolved_from_attachment_not_client() {
		$out = $this->sanitize(
			array(
				'logoId'  => 999999, // no such attachment
				'logoUrl' => 'https://evil.example/spoofed.png',
			)
		);
		$this->assertSame( 0, $out['logoId'] );
		$this->assertSame( '', $out['logoUrl'] );
	}

	public function test_frame_requires_style_and_text() {
		$this->assertNull( $this->sanitize( array( 'frame' => array( 'style' => 'label-bottom' ) ) )['frame'] );

		$out = $this->sanitize(
			array(
				'frame' => array(
					'style'     => 'label-bottom',
					'text'      => 'SCAN ME',
					'bandColor' => '#123456',
					'textColor' => 'nope',
				),
			)
		);
		$this->assertSame( 'label-bottom', $out['frame']['style'] );
		$this->assertSame( 'SCAN ME', $out['frame']['text'] );
		$this->assertSame( '#123456', $out['frame']['bandColor'] );
		$this->assertSame( '#ffffff', $out['frame']['textColor'] );
	}

	public function test_frame_text_is_capped_at_40_chars() {
		$out = $this->sanitize(
			array(
				'frame' => array(
					'style' => 'label-top',
					'text'  => str_repeat( 'A', 100 ),
				),
			)
		);
		$this->assertSame( 40, strlen( $out['frame']['text'] ) );
	}
}
