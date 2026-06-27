<?php
/**
 * Admin side of Gallus QR: the menu, the generator screen, the scan-stats
 * dashboard, and asset loading.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gallus_QR_Admin {

	/** @var Gallus_QR_Database */
	private $db;

	/** Hook suffix for the generator page (assets load only here). @var string */
	private $page_hook = '';

	public function __construct( Gallus_QR_Database $db ) {
		$this->db = $db;
	}

	/**
	 * Wire up WordPress hooks. Called from gallus-qr.php on plugins_loaded.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add "Gallus QR" (generator) plus a "Scan Stats" submenu.
	 */
	public function register_menu() {
		$this->page_hook = add_menu_page(
			__( 'Gallus QR', 'gallus-qr' ),
			__( 'Gallus QR', 'gallus-qr' ),
			'manage_options',
			'gallus-qr',
			array( $this, 'render_generator_page' ),
			'dashicons-qrcode',
			30
		);

		add_submenu_page(
			'gallus-qr',
			__( 'Generator', 'gallus-qr' ),
			__( 'Generator', 'gallus-qr' ),
			'manage_options',
			'gallus-qr',
			array( $this, 'render_generator_page' )
		);

		add_submenu_page(
			'gallus-qr',
			__( 'Scan Stats', 'gallus-qr' ),
			__( 'Scan Stats', 'gallus-qr' ),
			'manage_options',
			'gallus-qr-stats',
			array( $this, 'render_stats_page' )
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

		wp_enqueue_script(
			'qr-code-styling',
			GALLUS_QR_URL . 'assets/js/lib/qr-code-styling.js',
			array(),
			'1.6.0',
			true
		);

		wp_enqueue_script(
			'gallus-qr-generator',
			GALLUS_QR_URL . 'assets/js/generator.js',
			array( 'qr-code-styling' ),
			GALLUS_QR_VERSION,
			true
		);

		// Hand the front-end what it needs to save trackable codes.
		wp_localize_script(
			'gallus-qr-generator',
			'GallusQR',
			array(
				'restUrl' => esc_url_raw( rest_url( 'gallus-qr/v1/codes' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'qrBase'  => esc_url_raw( home_url( '/qr/' ) ),
			)
		);

		wp_enqueue_style(
			'gallus-qr-admin',
			GALLUS_QR_URL . 'assets/css/admin.css',
			array(),
			GALLUS_QR_VERSION
		);
	}

	/**
	 * The generator screen. Markup is the two-column layout; generator.js wires
	 * the live behaviour against these element IDs.
	 */
	public function render_generator_page() {
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

					<!-- Tracking -->
					<div class="gqr-track">
						<label class="gqr-checkbox">
							<input type="checkbox" id="gqr-trackable">
							<span><?php esc_html_e( 'Trackable — count scans (dynamic code)', 'gallus-qr' ); ?></span>
						</label>
						<p class="gqr-help">
							<?php esc_html_e( 'On: the QR points at this site and each scan is counted. Off: the QR encodes your URL directly — works forever but cannot be counted (best for permanent hardware).', 'gallus-qr' ); ?>
						</p>

						<div id="gqr-track-fields" class="gqr-track-fields" hidden>
							<label class="gqr-field">
								<span><?php esc_html_e( 'Label (for your stats)', 'gallus-qr' ); ?></span>
								<input type="text" id="gqr-title" placeholder="<?php esc_attr_e( 'e.g. Business card', 'gallus-qr' ); ?>">
							</label>
							<button type="button" id="gqr-save" class="button button-secondary">
								<?php esc_html_e( 'Save &amp; make trackable', 'gallus-qr' ); ?>
							</button>
							<p id="gqr-save-result" class="gqr-save-result" hidden></p>
						</div>
					</div>
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

	/**
	 * The scan-stats dashboard: every saved code with its total and a 30-day
	 * bar chart (rendered server-side — no chart library needed).
	 */
	public function render_stats_page() {
		$codes = $this->db->get_codes_with_counts();
		?>
		<div class="wrap gqr-wrap">
			<h1><?php esc_html_e( 'Gallus QR — Scan Stats', 'gallus-qr' ); ?></h1>

			<?php if ( empty( $codes ) ) : ?>
				<p>
					<?php esc_html_e( 'No trackable codes yet. Create one on the Generator screen with “Trackable” ticked.', 'gallus-qr' ); ?>
				</p>
			<?php else : ?>
				<table class="widefat gqr-stats-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'gallus-qr' ); ?></th>
							<th><?php esc_html_e( 'Short link', 'gallus-qr' ); ?></th>
							<th><?php esc_html_e( 'Destination', 'gallus-qr' ); ?></th>
							<th><?php esc_html_e( 'Total scans', 'gallus-qr' ); ?></th>
							<th><?php esc_html_e( 'Last 30 days', 'gallus-qr' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $codes as $code ) : ?>
							<?php $short = home_url( '/qr/' . $code->slug ); ?>
							<tr>
								<td><?php echo esc_html( $code->title ? $code->title : '—' ); ?></td>
								<td>
									<a href="<?php echo esc_url( $short ); ?>" target="_blank" rel="noopener">
										/qr/<?php echo esc_html( $code->slug ); ?>
									</a>
								</td>
								<td class="gqr-dest"><?php echo esc_html( $code->destination ); ?></td>
								<td class="gqr-total"><?php echo (int) $code->total_scans; ?></td>
								<td><?php $this->render_sparkline( (int) $code->id ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a 30-day bar chart for one code as plain HTML/CSS bars.
	 *
	 * @param int $code_id
	 */
	private function render_sparkline( $code_id ) {
		$days  = 30;
		$daily = $this->db->get_daily_scans( $code_id, $days );

		// Build an ordered list of the last N days, filling gaps with 0.
		$counts = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day            = gmdate( 'Y-m-d', strtotime( "-{$i} days", current_time( 'timestamp' ) ) );
			$counts[ $day ] = isset( $daily[ $day ] ) ? $daily[ $day ] : 0;
		}

		$max = max( 1, max( $counts ) );
		echo '<div class="gqr-spark" role="img" aria-label="' . esc_attr__( 'Scans per day, last 30 days', 'gallus-qr' ) . '">';
		foreach ( $counts as $day => $hits ) {
			$pct   = (int) round( ( $hits / $max ) * 100 );
			$title = sprintf( '%s: %d', $day, $hits );
			printf(
				'<span class="gqr-spark-bar" style="height:%d%%" title="%s"></span>',
				max( 4, $pct ), // floor so empty days are still visible
				esc_attr( $title )
			);
		}
		echo '</div>';
	}
}
