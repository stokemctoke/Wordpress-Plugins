<?php
/**
 * Admin side of Gallus QR: registers the menu, renders the generator screen,
 * and loads the JS/CSS only on our own page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gallus_QR_Admin {

	/**
	 * The hook suffix WordPress returns for our admin page. We keep it so we
	 * can load assets ONLY here, not across all of wp-admin.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Wire up WordPress hooks. Called from gallus-qr.php on plugins_loaded.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add "Gallus QR" to the wp-admin sidebar.
	 */
	public function register_menu() {
		$this->page_hook = add_menu_page(
			__( 'Gallus QR', 'gallus-qr' ),       // browser <title>
			__( 'Gallus QR', 'gallus-qr' ),       // sidebar label
			'manage_options',                       // capability — admins only (v1 = just you)
			'gallus-qr',                            // menu slug
			array( $this, 'render_page' ),          // callback that prints the screen
			'dashicons-qrcode',                     // sidebar icon
			30                                       // position
		);
	}

	/**
	 * Load the engine, generator script and styles — but only on our page.
	 *
	 * @param string $hook The current admin page's hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		// Bundled QR engine (no CDN — version-pinned, works offline).
		wp_enqueue_script(
			'qr-code-styling',
			GALLUS_QR_URL . 'assets/js/lib/qr-code-styling.js',
			array(),
			'1.6.0',
			true
		);

		// Our generator UI, dependent on the engine being loaded first.
		wp_enqueue_script(
			'gallus-qr-generator',
			GALLUS_QR_URL . 'assets/js/generator.js',
			array( 'qr-code-styling' ),
			GALLUS_QR_VERSION,
			true
		);

		wp_enqueue_style(
			'gallus-qr-admin',
			GALLUS_QR_URL . 'assets/css/admin.css',
			array(),
			GALLUS_QR_VERSION
		);
	}

	/**
	 * Print the generator screen. The markup is the two-column layout; all the
	 * live behaviour happens in generator.js against these element IDs.
	 */
	public function render_page() {
		?>
		<div class="wrap gqr-wrap">
			<h1><?php esc_html_e( 'Gallus QR', 'gallus-qr' ); ?></h1>
			<p class="gqr-tagline">
				<?php esc_html_e( 'Design a custom QR code, then download it as PNG or SVG.', 'gallus-qr' ); ?>
			</p>

			<div class="gqr-layout">
				<!-- LEFT: controls -->
				<div class="gqr-controls">
					<label class="gqr-field">
						<span><?php esc_html_e( 'Content (URL)', 'gallus-qr' ); ?></span>
						<input type="url" id="gqr-data" placeholder="https://stokemctoke.com" value="https://stokemctoke.com">
					</label>

					<div class="gqr-row">
						<label class="gqr-field">
							<span><?php esc_html_e( 'Dot shape', 'gallus-qr' ); ?></span>
							<select id="gqr-dot-style">
								<option value="square"><?php esc_html_e( 'Square', 'gallus-qr' ); ?></option>
								<option value="rounded"><?php esc_html_e( 'Rounded', 'gallus-qr' ); ?></option>
							</select>
						</label>

						<label class="gqr-field">
							<span><?php esc_html_e( 'Corner shape', 'gallus-qr' ); ?></span>
							<select id="gqr-corner-style">
								<option value="square"><?php esc_html_e( 'Square', 'gallus-qr' ); ?></option>
								<option value="extra-rounded"><?php esc_html_e( 'Rounded', 'gallus-qr' ); ?></option>
							</select>
						</label>
					</div>

					<div class="gqr-row">
						<label class="gqr-field">
							<span><?php esc_html_e( 'Foreground', 'gallus-qr' ); ?></span>
							<input type="color" id="gqr-fg" value="#000000">
						</label>

						<label class="gqr-field">
							<span><?php esc_html_e( 'Background', 'gallus-qr' ); ?></span>
							<input type="color" id="gqr-bg" value="#ffffff">
						</label>

						<label class="gqr-field gqr-invert">
							<button type="button" id="gqr-invert" class="button">
								<?php esc_html_e( 'Invert', 'gallus-qr' ); ?>
							</button>
						</label>
					</div>

					<label class="gqr-field">
						<span><?php esc_html_e( 'Centre logo (PNG)', 'gallus-qr' ); ?></span>
						<input type="file" id="gqr-logo" accept="image/png,image/svg+xml">
						<button type="button" id="gqr-logo-clear" class="button-link gqr-clear-link">
							<?php esc_html_e( 'Remove logo', 'gallus-qr' ); ?>
						</button>
					</label>
				</div>

				<!-- RIGHT: live preview + downloads -->
				<div class="gqr-preview">
					<div id="gqr-canvas" class="gqr-canvas"></div>
					<div class="gqr-downloads">
						<button type="button" id="gqr-download-png" class="button button-primary">
							<?php esc_html_e( 'Download PNG', 'gallus-qr' ); ?>
						</button>
						<button type="button" id="gqr-download-svg" class="button button-primary">
							<?php esc_html_e( 'Download SVG', 'gallus-qr' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
