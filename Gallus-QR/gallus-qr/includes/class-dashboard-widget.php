<?php
/**
 * WP dashboard widget: the five most-scanned codes over the last 7 days,
 * linking through to the Scan Stats screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gallus_QR_Dashboard_Widget {

	/** @var Gallus_QR_Database */
	private $db;

	public function __construct( Gallus_QR_Database $db ) {
		$this->db = $db;
	}

	/**
	 * Wire up WordPress hooks. Called from gallus-qr.php on plugins_loaded.
	 */
	public function init() {
		add_action( 'wp_dashboard_setup', array( $this, 'register' ) );
	}

	public function register() {
		if ( ! current_user_can( Gallus_QR_Settings::capability() ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'gallus_qr_top_codes',
			__( 'Gallus QR — top codes (last 7 days)', 'gallus-qr' ),
			array( $this, 'render' )
		);
	}

	public function render() {
		$since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$top   = $this->db->get_top_codes( $since, 5 );

		if ( empty( $top ) ) {
			echo '<p>' . esc_html__( 'No scans in the last 7 days.', 'gallus-qr' ) . '</p>';
			return;
		}

		echo '<ol style="margin-left:1.2em">';
		foreach ( $top as $code ) {
			$label = $code->title ? $code->title : '/qr/' . $code->slug;
			printf(
				'<li>%s — <strong>%d</strong></li>',
				esc_html( $label ),
				(int) $code->hits
			);
		}
		echo '</ol>';

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=gallus-qr-stats' ) ),
			esc_html__( 'Full scan stats →', 'gallus-qr' )
		);
	}
}
