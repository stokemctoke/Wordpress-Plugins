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

	/** Hook suffix for the Scan Stats page. @var string */
	private $stats_hook = '';

	public function __construct( Gallus_QR_Database $db ) {
		$this->db = $db;
	}

	/**
	 * Wire up WordPress hooks. Called from gallus-qr.php on plugins_loaded.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_head', array( $this, 'menu_icon_css' ) );

		// Rename / edit / delete handlers for the Scan Stats screen (admin-post.php).
		add_action( 'admin_post_gallus_qr_rename', array( $this, 'handle_rename' ) );
		add_action( 'admin_post_gallus_qr_destination', array( $this, 'handle_destination' ) );
		add_action( 'admin_post_gallus_qr_delete', array( $this, 'handle_delete' ) );
	}

	/**
	 * Handle a destination-change POST, then redirect back. The slug is
	 * unchanged so the printed QR keeps working and now points somewhere new.
	 */
	public function handle_destination() {
		$id = isset( $_POST['code_id'] ) ? absint( $_POST['code_id'] ) : 0;

		if ( ! current_user_can( 'manage_options' )
			|| ! check_admin_referer( 'gallus_qr_destination_' . $id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'gallus-qr' ) );
		}

		$destination = isset( $_POST['destination'] ) ? esc_url_raw( wp_unslash( $_POST['destination'] ) ) : '';

		if ( empty( $destination ) || ! wp_http_validate_url( $destination ) ) {
			wp_safe_redirect( add_query_arg( 'gqr_msg', 'badurl', $this->stats_url() ) );
			exit;
		}

		$this->db->update_code_destination( $id, $destination );

		wp_safe_redirect( add_query_arg( 'gqr_msg', 'retargeted', $this->stats_url() ) );
		exit;
	}

	/**
	 * Handle a rename POST from the Scan Stats screen, then redirect back.
	 */
	public function handle_rename() {
		$id = isset( $_POST['code_id'] ) ? absint( $_POST['code_id'] ) : 0;

		if ( ! current_user_can( 'manage_options' )
			|| ! check_admin_referer( 'gallus_qr_rename_' . $id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'gallus-qr' ) );
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$this->db->update_code_title( $id, $title );

		wp_safe_redirect( add_query_arg( 'gqr_msg', 'renamed', $this->stats_url() ) );
		exit;
	}

	/**
	 * Handle a delete POST from the Scan Stats screen, then redirect back.
	 */
	public function handle_delete() {
		$id = isset( $_POST['code_id'] ) ? absint( $_POST['code_id'] ) : 0;

		if ( ! current_user_can( 'manage_options' )
			|| ! check_admin_referer( 'gallus_qr_delete_' . $id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'gallus-qr' ) );
		}

		$this->db->delete_code( $id );

		wp_safe_redirect( add_query_arg( 'gqr_msg', 'deleted', $this->stats_url() ) );
		exit;
	}

	/** @return string URL of the Scan Stats admin page. */
	private function stats_url() {
		return admin_url( 'admin.php?page=gallus-qr-stats' );
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
			GALLUS_QR_URL . 'assets/img/menu-icon.png', // white "GG" mark; WP dims it to ~60% in the sidebar
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

		$this->stats_hook = add_submenu_page(
			'gallus-qr',
			__( 'Scan Stats', 'gallus-qr' ),
			__( 'Scan Stats', 'gallus-qr' ),
			'manage_options',
			'gallus-qr-stats',
			array( $this, 'render_stats_page' )
		);
	}

	/**
	 * Tame the custom "GG" menu icon: WordPress shows custom icons large and at
	 * 60% opacity (washed out). Force a small, pure-white, centred icon.
	 */
	public function menu_icon_css() {
		?>
		<style>
			#adminmenu #toplevel_page_gallus-qr .wp-menu-image img {
				width: 14px;
				height: auto;
				padding-top: 10px;
				opacity: 1;
			}
		</style>
		<?php
	}

	/**
	 * Load the engine, generator script and styles — but only on our page.
	 *
	 * @param string $hook The current admin page's hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		// The engine is needed on both screens (generator + stats re-download).
		if ( $hook !== $this->page_hook && $hook !== $this->stats_hook ) {
			return;
		}

		wp_enqueue_script(
			'qr-code-styling',
			GALLUS_QR_URL . 'assets/js/lib/qr-code-styling.js',
			array(),
			'1.6.0',
			true
		);

		wp_enqueue_style(
			'gallus-qr-admin',
			GALLUS_QR_URL . 'assets/css/admin.css',
			array(),
			GALLUS_QR_VERSION
		);

		// Stats screen: just the re-download helper.
		if ( $hook === $this->stats_hook ) {
			wp_enqueue_script(
				'gallus-qr-stats',
				GALLUS_QR_URL . 'assets/js/stats.js',
				array( 'qr-code-styling' ),
				GALLUS_QR_VERSION,
				true
			);

			// Map slug => stored design so re-downloads match the original.
			$designs = array();
			foreach ( $this->db->get_codes_with_counts() as $code ) {
				if ( ! empty( $code->design ) ) {
					$decoded = json_decode( $code->design, true );
					if ( is_array( $decoded ) ) {
						$designs[ $code->slug ] = $decoded;
					}
				}
			}
			wp_localize_script( 'gallus-qr-stats', 'GallusQRStats', array( 'designs' => $designs ) );
			return;
		}

		// Generator screen: the full generator UI.
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

					<label class="gqr-field">
						<span>
							<?php esc_html_e( 'Export size (px)', 'gallus-qr' ); ?>
							<output id="gqr-size-value">512</output>
						</span>
						<input type="range" id="gqr-size" min="128" max="1024" step="64" value="512">
						<span class="gqr-help"><?php esc_html_e( 'Downloaded PNG resolution (128–1024). SVG stays vector.', 'gallus-qr' ); ?></span>
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

	/** Allowed date ranges for the dashboard: query value => [label, days]. */
	private function ranges() {
		return array(
			'7'   => array( __( 'Last 7 days', 'gallus-qr' ), 7 ),
			'30'  => array( __( 'Last 30 days', 'gallus-qr' ), 30 ),
			'90'  => array( __( 'Last 90 days', 'gallus-qr' ), 90 ),
			'all' => array( __( 'All time', 'gallus-qr' ), 3650 ),
		);
	}

	/**
	 * The scan-stats dashboard: a date-range selector plus, per code, its
	 * editable label/destination, totals (total + unique), device split, a
	 * bar chart, and re-download/delete actions. Charts are server-rendered.
	 */
	public function render_stats_page() {
		$codes   = $this->db->get_codes_with_counts();
		$action  = esc_url( admin_url( 'admin-post.php' ) );
		$ranges  = $this->ranges();

		// Selected range (default 30 days).
		$range_key = isset( $_GET['gqr_range'] ) ? sanitize_key( $_GET['gqr_range'] ) : '30';
		if ( ! isset( $ranges[ $range_key ] ) ) {
			$range_key = '30';
		}
		$days  = $ranges[ $range_key ][1];
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days", current_time( 'timestamp' ) ) );
		?>
		<div class="wrap gqr-wrap">
			<h1><?php esc_html_e( 'Gallus QR — Scan Stats', 'gallus-qr' ); ?></h1>

			<?php $this->maybe_render_notice(); ?>

			<?php if ( empty( $codes ) ) : ?>
				<p>
					<?php esc_html_e( 'No trackable codes yet. Create one on the Generator screen with “Trackable” ticked.', 'gallus-qr' ); ?>
				</p>
			<?php else : ?>
				<form method="get" class="gqr-range-form">
					<input type="hidden" name="page" value="gallus-qr-stats">
					<label>
						<?php esc_html_e( 'Date range:', 'gallus-qr' ); ?>
						<select name="gqr_range" onchange="this.form.submit()">
							<?php foreach ( $ranges as $key => $info ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $range_key ); ?>>
									<?php echo esc_html( $info[0] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
				</form>

				<table class="widefat gqr-stats-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'gallus-qr' ); ?></th>
							<th><?php esc_html_e( 'Short link', 'gallus-qr' ); ?></th>
							<th><?php esc_html_e( 'Destination', 'gallus-qr' ); ?></th>
							<th><?php esc_html_e( 'Scans (range)', 'gallus-qr' ); ?></th>
							<th><?php esc_html_e( 'Devices', 'gallus-qr' ); ?></th>
							<th><?php echo esc_html( $ranges[ $range_key ][0] ); ?></th>
							<th><?php esc_html_e( 'Actions', 'gallus-qr' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $codes as $code ) :
							$short   = home_url( '/qr/' . $code->slug );
							$summary = $this->db->get_range_summary( (int) $code->id, $since );
							$devices = $this->db->get_device_breakdown( (int) $code->id, $since );
							?>
							<tr>
								<td>
									<form method="post" action="<?php echo $action; ?>" class="gqr-inline">
										<input type="hidden" name="action" value="gallus_qr_rename">
										<input type="hidden" name="code_id" value="<?php echo (int) $code->id; ?>">
										<?php wp_nonce_field( 'gallus_qr_rename_' . (int) $code->id ); ?>
										<input type="text" name="title" value="<?php echo esc_attr( $code->title ); ?>" placeholder="<?php esc_attr_e( 'Label', 'gallus-qr' ); ?>">
										<button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'gallus-qr' ); ?></button>
									</form>
								</td>
								<td>
									<a href="<?php echo esc_url( $short ); ?>" target="_blank" rel="noopener">
										/qr/<?php echo esc_html( $code->slug ); ?>
									</a>
								</td>
								<td>
									<form method="post" action="<?php echo $action; ?>" class="gqr-inline">
										<input type="hidden" name="action" value="gallus_qr_destination">
										<input type="hidden" name="code_id" value="<?php echo (int) $code->id; ?>">
										<?php wp_nonce_field( 'gallus_qr_destination_' . (int) $code->id ); ?>
										<input type="url" name="destination" value="<?php echo esc_attr( $code->destination ); ?>" class="gqr-dest-input">
										<button type="submit" class="button button-small"><?php esc_html_e( 'Update', 'gallus-qr' ); ?></button>
									</form>
								</td>
								<td class="gqr-total">
									<?php echo (int) $summary['total']; ?>
									<span class="gqr-sub"><?php
										/* translators: %d: number of unique visitors. */
										printf( esc_html__( '%d unique', 'gallus-qr' ), (int) $summary['unique'] );
									?></span>
								</td>
								<td class="gqr-devices">
									<?php
									$parts = array();
									foreach ( $devices as $name => $count ) {
										if ( $count > 0 ) {
											$parts[] = esc_html( $name . ': ' . $count );
										}
									}
									echo $parts ? implode( '<br>', $parts ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput
									?>
								</td>
								<td><?php $this->render_sparkline( (int) $code->id, (int) min( $days, 90 ) ); ?></td>
								<td class="gqr-actions">
									<button type="button" class="button button-small gqr-dl" data-url="<?php echo esc_url( $short ); ?>" data-slug="<?php echo esc_attr( $code->slug ); ?>" data-ext="png">PNG</button>
									<button type="button" class="button button-small gqr-dl" data-url="<?php echo esc_url( $short ); ?>" data-slug="<?php echo esc_attr( $code->slug ); ?>" data-ext="svg">SVG</button>
									<form method="post" action="<?php echo $action; ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this code and all its scan data? This cannot be undone.', 'gallus-qr' ) ); ?>');">
										<input type="hidden" name="action" value="gallus_qr_delete">
										<input type="hidden" name="code_id" value="<?php echo (int) $code->id; ?>">
										<?php wp_nonce_field( 'gallus_qr_delete_' . (int) $code->id ); ?>
										<button type="submit" class="button-link gqr-delete"><?php esc_html_e( 'Delete', 'gallus-qr' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="gqr-help">
					<?php esc_html_e( 'Re-download (PNG/SVG) regenerates the code from its short link using the design you saved it with. Codes saved before v0.5.0 come out plain black-on-white.', 'gallus-qr' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Show a success/error notice after a redirect from an admin-post action.
	 */
	private function maybe_render_notice() {
		$msg = isset( $_GET['gqr_msg'] ) ? sanitize_key( $_GET['gqr_msg'] ) : '';

		$success = array(
			'renamed'    => __( 'Code renamed.', 'gallus-qr' ),
			'deleted'    => __( 'Code deleted.', 'gallus-qr' ),
			'retargeted' => __( 'Destination updated — the printed code now points to the new URL.', 'gallus-qr' ),
		);

		if ( isset( $success[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $success[ $msg ] ) . '</p></div>';
		} elseif ( 'badurl' === $msg ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'That destination was not a valid URL.', 'gallus-qr' ) . '</p></div>';
		}
	}

	/**
	 * Render a per-day bar chart (up to 90 days) for one code as CSS bars.
	 *
	 * @param int $code_id
	 * @param int $days
	 */
	private function render_sparkline( $code_id, $days = 30 ) {
		$daily = $this->db->get_daily_scans( $code_id, $days );

		// Build an ordered list of the last N days, filling gaps with 0.
		$counts = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day            = gmdate( 'Y-m-d', strtotime( "-{$i} days", current_time( 'timestamp' ) ) );
			$counts[ $day ] = isset( $daily[ $day ] ) ? $daily[ $day ] : 0;
		}

		$max   = max( 1, max( $counts ) );
		$label = sprintf(
			/* translators: %d: number of days. */
			esc_attr__( 'Scans per day, last %d days', 'gallus-qr' ),
			$days
		);
		echo '<div class="gqr-spark" role="img" aria-label="' . esc_attr( $label ) . '">';
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
