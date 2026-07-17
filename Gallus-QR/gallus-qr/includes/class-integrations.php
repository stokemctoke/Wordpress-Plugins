<?php
/**
 * Light-touch WordPress integrations: a "QR code" row action on posts, pages
 * and WooCommerce products, plus an admin-bar "QR for this page" shortcut —
 * both deep-link into the generator with the URL and title pre-filled
 * (generator.js reads the ?url= and ?title= query args).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gallus_QR_Integrations {

	/**
	 * Wire up WordPress hooks. Called from gallus-qr.php on plugins_loaded.
	 */
	public function init() {
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_link' ), 90 );
	}

	/**
	 * Post types that get the row action: posts, pages, and Woo products when
	 * WooCommerce is active. Filterable.
	 *
	 * @return array
	 */
	private function post_types() {
		$types = array( 'post', 'page' );
		if ( class_exists( 'WooCommerce' ) ) {
			$types[] = 'product';
		}
		return apply_filters( 'gallus_qr_integration_post_types', $types );
	}

	/**
	 * Generator URL pre-filled for one post.
	 *
	 * @param WP_Post $post
	 * @return string
	 */
	private function generator_url( $post ) {
		return add_query_arg(
			array(
				'page'  => 'gallus-qr',
				'url'   => rawurlencode( get_permalink( $post ) ),
				'title' => rawurlencode( get_the_title( $post ) ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * "QR code" link in the posts/pages/products list table.
	 *
	 * @param array   $actions
	 * @param WP_Post $post
	 * @return array
	 */
	public function row_action( $actions, $post ) {
		if ( 'publish' !== $post->post_status
			|| ! in_array( $post->post_type, $this->post_types(), true )
			|| ! current_user_can( Gallus_QR_Settings::capability() ) ) {
			return $actions;
		}

		$actions['gallus_qr'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $this->generator_url( $post ) ),
			esc_html__( 'QR code', 'gallus-qr' )
		);

		return $actions;
	}

	/**
	 * "QR for this page" in the admin bar — on singular front-end views and in
	 * the classic/block editor.
	 *
	 * @param WP_Admin_Bar $bar
	 */
	public function admin_bar_link( $bar ) {
		if ( ! current_user_can( Gallus_QR_Settings::capability() ) ) {
			return;
		}

		$post = null;
		if ( is_admin() ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && 'post' === $screen->base && ! empty( $_GET['post'] ) ) {
				$post = get_post( absint( $_GET['post'] ) );
			}
		} elseif ( is_singular() ) {
			$post = get_queried_object();
		}

		if ( ! $post instanceof WP_Post
			|| 'publish' !== $post->post_status
			|| ! in_array( $post->post_type, $this->post_types(), true ) ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'gallus-qr',
				'title' => __( 'QR for this page', 'gallus-qr' ),
				'href'  => $this->generator_url( $post ),
			)
		);
	}
}
