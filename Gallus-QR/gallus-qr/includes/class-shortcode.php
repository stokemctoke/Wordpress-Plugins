<?php
/**
 * Front-end display of saved codes: the [gallus_qr slug=""] shortcode and the
 * "Gallus QR Code" block share render_code_html(), which outputs a placeholder
 * div that frontend.js hydrates through the shared designer — so a code on a
 * page is pixel-identical to its downloads.
 *
 * Rendering is client-side (the bundled qr-code-styling lib is the single
 * source of pixel truth); a <noscript> link to the short URL covers JS-less
 * visitors. Assets are registered here and only enqueued on pages that
 * actually use the shortcode or block.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gallus_QR_Shortcode {

	/** @var Gallus_QR_Database */
	private $db;

	public function __construct( Gallus_QR_Database $db ) {
		$this->db = $db;
	}

	/**
	 * Wire up WordPress hooks. Called from gallus-qr.php on plugins_loaded.
	 */
	public function init() {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_shortcode( 'gallus_qr', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Register (not enqueue) the front-end rendering stack so the block's
	 * viewScript handles resolve and the shortcode can enqueue on demand.
	 */
	public function register_assets() {
		wp_register_script(
			'qr-code-styling',
			GALLUS_QR_URL . 'assets/js/lib/qr-code-styling.js',
			array(),
			'1.6.0',
			true
		);

		wp_register_script(
			'gallus-qr-designer',
			GALLUS_QR_URL . 'assets/js/designer.js',
			array( 'qr-code-styling' ),
			GALLUS_QR_VERSION,
			true
		);

		wp_register_script(
			'gallus-qr-frontend',
			GALLUS_QR_URL . 'assets/js/frontend.js',
			array( 'gallus-qr-designer' ),
			GALLUS_QR_VERSION,
			true
		);

		wp_register_style(
			'gallus-qr-frontend',
			GALLUS_QR_URL . 'assets/css/frontend.css',
			array(),
			GALLUS_QR_VERSION
		);

		// Block editor UI (vanilla JS — no build step).
		wp_register_script(
			'gallus-qr-block-editor',
			GALLUS_QR_URL . 'block/editor.js',
			array(
				'wp-blocks',
				'wp-element',
				'wp-components',
				'wp-block-editor',
				'wp-api-fetch',
				'wp-i18n',
				'gallus-qr-designer',
			),
			GALLUS_QR_VERSION,
			true
		);

		wp_register_style(
			'gallus-qr-block-editor',
			GALLUS_QR_URL . 'block/editor.css',
			array(),
			GALLUS_QR_VERSION
		);

		// The block editor script uses wp.i18n.__() directly.
		wp_set_script_translations( 'gallus-qr-block-editor', 'gallus-qr', GALLUS_QR_PATH . 'languages' );
	}

	/**
	 * Register the block from block/block.json (script/style handles above).
	 */
	public function register_block() {
		register_block_type(
			GALLUS_QR_PATH . 'block',
			array(
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array $attributes
	 * @return string
	 */
	public function render_block( $attributes ) {
		$code_id = isset( $attributes['codeId'] ) ? absint( $attributes['codeId'] ) : 0;
		if ( ! $code_id ) {
			return '';
		}

		$code = $this->db->get_code_by_id( $code_id );
		if ( ! $code ) {
			return '';
		}

		$size = isset( $attributes['size'] ) ? (int) $attributes['size'] : 256;

		return $this->render_code_html( $code, $size );
	}

	/**
	 * [gallus_qr slug="xxxx" size="256"]
	 *
	 * @param array|string $atts
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'slug' => '',
				'size' => 256,
			),
			$atts,
			'gallus_qr'
		);

		if ( '' === $atts['slug'] ) {
			return '';
		}

		$code = $this->db->get_code_by_slug( sanitize_text_field( $atts['slug'] ) );
		if ( ! $code ) {
			return '';
		}

		return $this->render_code_html( $code, (int) $atts['size'] );
	}

	/**
	 * The shared placeholder markup for one saved code. frontend.js reads the
	 * data attributes and draws the QR through the shared designer.
	 *
	 * @param object $code Code row.
	 * @param int    $size Display size in px (128–1024).
	 * @return string
	 */
	public function render_code_html( $code, $size = 256 ) {
		wp_enqueue_script( 'gallus-qr-frontend' );
		wp_enqueue_style( 'gallus-qr-frontend' );

		$size = max( 128, min( 1024, $size ? $size : 256 ) );

		// Tracked codes encode their short link; library codes encode the payload.
		$short   = home_url( '/qr/' . $code->slug );
		$encodes = ( (int) $code->trackable === 1 ) ? $short : $code->destination;

		$design = json_decode( (string) $code->design, true );
		$design = is_array( $design ) ? $design : array();

		$html  = '<div class="gallus-qr-embed" data-encodes="' . esc_attr( $encodes ) . '"';
		$html .= ' data-design="' . esc_attr( wp_json_encode( $design ) ) . '"';
		$html .= ' data-size="' . esc_attr( (string) $size ) . '"';
		$html .= ' style="max-width:' . (int) $size . 'px">';

		if ( (int) $code->trackable === 1 ) {
			$html .= '<noscript><a href="' . esc_url( $short ) . '">' . esc_html( $short ) . '</a></noscript>';
		}

		$html .= '</div>';

		return $html;
	}
}
