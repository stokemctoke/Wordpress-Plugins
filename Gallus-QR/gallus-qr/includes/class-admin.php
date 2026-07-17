<?php
/**
 * Admin side of Gallus QR: the menu, the generator screen, and asset loading.
 * The Scan Stats screen lives in Gallus_QR_Admin_Stats; this class registers
 * its menu entry (it owns the parent menu) and loads its assets.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gallus_QR_Admin {

	/** @var Gallus_QR_Database */
	private $db;

	/** @var Gallus_QR_Admin_Stats */
	private $stats;

	/** @var Gallus_QR_Settings */
	private $settings;

	/** @var Gallus_QR_Admin_Tools */
	private $tools;

	/** Hook suffix for the generator page (assets load only here). @var string */
	private $page_hook = '';

	public function __construct( Gallus_QR_Database $db, Gallus_QR_Admin_Stats $stats, Gallus_QR_Settings $settings, Gallus_QR_Admin_Tools $tools ) {
		$this->db       = $db;
		$this->stats    = $stats;
		$this->settings = $settings;
		$this->tools    = $tools;
	}

	/**
	 * Wire up WordPress hooks. Called from gallus-qr.php on plugins_loaded.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_head', array( $this, 'menu_icon_css' ) );
	}

	/**
	 * Add "Gallus QR" (generator) plus a "Scan Stats" submenu.
	 */
	public function register_menu() {
		$cap = Gallus_QR_Settings::capability();

		$this->page_hook = add_menu_page(
			__( 'Gallus QR', 'gallus-qr' ),
			__( 'Gallus QR', 'gallus-qr' ),
			$cap,
			'gallus-qr',
			array( $this, 'render_generator_page' ),
			GALLUS_QR_URL . 'assets/img/menu-icon.png', // white "GG" mark; WP dims it to ~60% in the sidebar
			30
		);

		add_submenu_page(
			'gallus-qr',
			__( 'Generator', 'gallus-qr' ),
			__( 'Generator', 'gallus-qr' ),
			$cap,
			'gallus-qr',
			array( $this, 'render_generator_page' )
		);

		$stats_hook = add_submenu_page(
			'gallus-qr',
			__( 'Scan Stats', 'gallus-qr' ),
			__( 'Scan Stats', 'gallus-qr' ),
			$cap,
			'gallus-qr-stats',
			array( $this->stats, 'render_page' )
		);
		$this->stats->set_hook( $stats_hook );

		add_submenu_page(
			'gallus-qr',
			__( 'Bulk import', 'gallus-qr' ),
			__( 'Bulk import', 'gallus-qr' ),
			$cap,
			'gallus-qr-import',
			array( $this->tools, 'render_import_page' )
		);

		// Settings stay admin-only — capability mapping is a security decision.
		add_submenu_page(
			'gallus-qr',
			__( 'Settings', 'gallus-qr' ),
			__( 'Settings', 'gallus-qr' ),
			'manage_options',
			'gallus-qr-settings',
			array( $this->settings, 'render_page' )
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
				width: 20px;
				height: auto;
				padding-top: 7px;
				opacity: 1;
			}
		</style>
		<?php
	}

	/**
	 * Load the engine, shared designer, page scripts and styles — but only on
	 * our pages.
	 *
	 * @param string $hook The current admin page's hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		$stats_hook = $this->stats->hook();

		if ( $hook !== $this->page_hook && $hook !== $stats_hook ) {
			return;
		}

		wp_enqueue_script(
			'qr-code-styling',
			GALLUS_QR_URL . 'assets/js/lib/qr-code-styling.js',
			array(),
			'1.6.0',
			true
		);

		// Shared renderer — both screens (and the front-end block) draw through it.
		wp_enqueue_script(
			'gallus-qr-designer',
			GALLUS_QR_URL . 'assets/js/designer.js',
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

		// Stats screen: just the re-download helper.
		if ( $hook === $stats_hook ) {
			wp_enqueue_script(
				'gallus-qr-stats',
				GALLUS_QR_URL . 'assets/js/stats.js',
				array( 'gallus-qr-designer' ),
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
			wp_localize_script(
				'gallus-qr-stats',
				'GallusQRStats',
				array(
					'designs'  => $designs,
					'restBase' => esc_url_raw( rest_url( 'gallus-qr/v1/codes' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'i18n'     => array(
						'renamed'       => __( 'Code renamed.', 'gallus-qr' ),
						'retargeted'    => __( 'Destination updated — the printed code now points to the new URL.', 'gallus-qr' ),
						'deleteConfirm' => __( 'Delete this code and all its scan data? This cannot be undone.', 'gallus-qr' ),
						'requestFailed' => __( 'Request failed.', 'gallus-qr' ),
					),
				)
			);
			return;
		}

		// Generator screen: media picker (logo), payload builders + the full
		// generator UI.
		wp_enqueue_media();

		wp_enqueue_script(
			'gallus-qr-payloads',
			GALLUS_QR_URL . 'assets/js/payloads.js',
			array(),
			GALLUS_QR_VERSION,
			true
		);

		wp_enqueue_script(
			'gallus-qr-generator',
			GALLUS_QR_URL . 'assets/js/generator.js',
			array( 'gallus-qr-designer', 'gallus-qr-payloads' ),
			GALLUS_QR_VERSION,
			true
		);

		// Hand the front-end what it needs to save trackable codes.
		wp_localize_script(
			'gallus-qr-generator',
			'GallusQR',
			array(
				'restUrl'      => esc_url_raw( rest_url( 'gallus-qr/v1/codes' ) ),
				'slugCheckUrl' => esc_url_raw( rest_url( 'gallus-qr/v1/slug-check' ) ),
				'presetsUrl'   => esc_url_raw( rest_url( 'gallus-qr/v1/presets' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'qrBase'       => esc_url_raw( home_url( '/qr/' ) ),
				'i18n'         => array(
					'modeHelpTrack'    => __( 'Routes through your site so every scan is counted. Save it to generate the tracked link.', 'gallus-qr' ),
					'modeHelpDirect'   => __( 'Encodes your URL directly. Works forever, but scans can’t be counted — best for permanent things like PCBs.', 'gallus-qr' ),
					'saveTrack'        => __( 'Save & make trackable', 'gallus-qr' ),
					'saveLibrary'      => __( 'Save to library', 'gallus-qr' ),
					'saving'           => __( 'Saving…', 'gallus-qr' ),
					'badgeDirect'      => __( '○ Direct — not tracked', 'gallus-qr' ),
					'badgeLibrary'     => __( '● Saved to library — not tracked', 'gallus-qr' ),
					'badgeTracked'     => __( '● Tracked · via', 'gallus-qr' ),
					'badgePending'     => __( '○ Trackable — not saved yet', 'gallus-qr' ),
					'encodes'          => __( 'Encodes →', 'gallus-qr' ),
					'savePrompt'       => __( 'Save to generate your tracked link, then download.', 'gallus-qr' ),
					'downloadHint'     => __( 'Save first — otherwise you’d download an untracked code.', 'gallus-qr' ),
					'enterUrl'         => __( 'Enter a URL first.', 'gallus-qr' ),
					'fillFields'       => __( 'Fill in the required fields first.', 'gallus-qr' ),
					'restMissing'      => __( 'Saving is unavailable (REST config missing).', 'gallus-qr' ),
					'saveFailed'       => __( 'Save failed.', 'gallus-qr' ),
					'savedLibrary'     => __( 'Saved — find it under Scan Stats for re-downloads.', 'gallus-qr' ),
					'logoMediaTitle'   => __( 'Choose a centre logo', 'gallus-qr' ),
					'logoMediaButton'  => __( 'Use as logo', 'gallus-qr' ),
					'logoFromMedia'    => __( 'Logo: media library', 'gallus-qr' ),
					'logoFromUpload'   => __( 'Logo: uploaded file', 'gallus-qr' ),
					'presetNeedsName'  => __( 'Give the preset a name first.', 'gallus-qr' ),
					'presetSaved'      => __( 'Preset saved.', 'gallus-qr' ),
					'presetDeleted'    => __( 'Preset deleted.', 'gallus-qr' ),
					'presetSaveFailed' => __( 'Could not save the preset.', 'gallus-qr' ),
					'destBSchedule'    => __( 'Destination after the switch', 'gallus-qr' ),
					'destBAb'          => __( 'Second destination (B)', 'gallus-qr' ),
				),
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
						<span><?php esc_html_e( 'Content type', 'gallus-qr' ); ?></span>
						<select id="gqr-payload-type">
							<option value="url"><?php esc_html_e( 'Website (URL)', 'gallus-qr' ); ?></option>
							<option value="wifi"><?php esc_html_e( 'WiFi network', 'gallus-qr' ); ?></option>
							<option value="vcard"><?php esc_html_e( 'Contact (vCard)', 'gallus-qr' ); ?></option>
							<option value="email"><?php esc_html_e( 'Email', 'gallus-qr' ); ?></option>
							<option value="sms"><?php esc_html_e( 'SMS', 'gallus-qr' ); ?></option>
							<option value="tel"><?php esc_html_e( 'Phone number', 'gallus-qr' ); ?></option>
							<option value="event"><?php esc_html_e( 'Calendar event', 'gallus-qr' ); ?></option>
							<option value="text"><?php esc_html_e( 'Plain text', 'gallus-qr' ); ?></option>
						</select>
					</label>

					<div class="gqr-field" id="gqr-mode-wrap">
						<span><?php esc_html_e( 'Code type', 'gallus-qr' ); ?></span>
						<div class="gqr-mode" role="radiogroup" aria-label="<?php esc_attr_e( 'Code type', 'gallus-qr' ); ?>">
							<button type="button" class="gqr-mode-btn is-active" id="gqr-mode-direct" data-mode="direct" role="radio" aria-checked="true">
								<?php esc_html_e( 'Direct', 'gallus-qr' ); ?>
							</button>
							<button type="button" class="gqr-mode-btn" id="gqr-mode-trackable" data-mode="trackable" role="radio" aria-checked="false">
								<?php esc_html_e( 'Trackable', 'gallus-qr' ); ?>
							</button>
						</div>
						<span class="gqr-help" id="gqr-mode-help"></span>
					</div>

					<!-- URL -->
					<div class="gqr-payload-panel" data-type="url">
						<label class="gqr-field">
							<span><?php esc_html_e( 'Content (URL)', 'gallus-qr' ); ?></span>
							<input type="url" id="gqr-data" placeholder="https://stokemctoke.com" value="https://stokemctoke.com">
						</label>
						<details class="gqr-utm">
							<summary><?php esc_html_e( 'Campaign tracking (UTM)', 'gallus-qr' ); ?></summary>
							<div class="gqr-row">
								<label class="gqr-field">
									<span><?php esc_html_e( 'Source', 'gallus-qr' ); ?></span>
									<input type="text" data-utm="source" placeholder="<?php esc_attr_e( 'e.g. flyer', 'gallus-qr' ); ?>">
								</label>
								<label class="gqr-field">
									<span><?php esc_html_e( 'Medium', 'gallus-qr' ); ?></span>
									<input type="text" data-utm="medium" placeholder="<?php esc_attr_e( 'e.g. qr', 'gallus-qr' ); ?>">
								</label>
								<label class="gqr-field">
									<span><?php esc_html_e( 'Campaign', 'gallus-qr' ); ?></span>
									<input type="text" data-utm="campaign" placeholder="<?php esc_attr_e( 'e.g. summer-2026', 'gallus-qr' ); ?>">
								</label>
							</div>
						</details>
					</div>

					<!-- WiFi -->
					<div class="gqr-payload-panel" data-type="wifi" hidden>
						<label class="gqr-field">
							<span><?php esc_html_e( 'Network name (SSID)', 'gallus-qr' ); ?></span>
							<input type="text" data-field="ssid">
						</label>
						<div class="gqr-row">
							<label class="gqr-field">
								<span><?php esc_html_e( 'Password', 'gallus-qr' ); ?></span>
								<input type="text" data-field="password">
							</label>
							<label class="gqr-field">
								<span><?php esc_html_e( 'Security', 'gallus-qr' ); ?></span>
								<select data-field="encryption">
									<option value="WPA"><?php esc_html_e( 'WPA/WPA2/WPA3', 'gallus-qr' ); ?></option>
									<option value="WEP"><?php esc_html_e( 'WEP', 'gallus-qr' ); ?></option>
									<option value="nopass"><?php esc_html_e( 'None (open)', 'gallus-qr' ); ?></option>
								</select>
							</label>
						</div>
						<label class="gqr-field gqr-check">
							<input type="checkbox" data-field="hidden">
							<span><?php esc_html_e( 'Hidden network', 'gallus-qr' ); ?></span>
						</label>
					</div>

					<!-- vCard -->
					<div class="gqr-payload-panel" data-type="vcard" hidden>
						<div class="gqr-row">
							<label class="gqr-field">
								<span><?php esc_html_e( 'First name', 'gallus-qr' ); ?></span>
								<input type="text" data-field="first">
							</label>
							<label class="gqr-field">
								<span><?php esc_html_e( 'Last name', 'gallus-qr' ); ?></span>
								<input type="text" data-field="last">
							</label>
						</div>
						<div class="gqr-row">
							<label class="gqr-field">
								<span><?php esc_html_e( 'Organisation', 'gallus-qr' ); ?></span>
								<input type="text" data-field="org">
							</label>
							<label class="gqr-field">
								<span><?php esc_html_e( 'Job title', 'gallus-qr' ); ?></span>
								<input type="text" data-field="job">
							</label>
						</div>
						<div class="gqr-row">
							<label class="gqr-field">
								<span><?php esc_html_e( 'Phone', 'gallus-qr' ); ?></span>
								<input type="tel" data-field="phone">
							</label>
							<label class="gqr-field">
								<span><?php esc_html_e( 'Email', 'gallus-qr' ); ?></span>
								<input type="email" data-field="email">
							</label>
						</div>
						<label class="gqr-field">
							<span><?php esc_html_e( 'Website', 'gallus-qr' ); ?></span>
							<input type="url" data-field="url">
						</label>
						<label class="gqr-field">
							<span><?php esc_html_e( 'Street address', 'gallus-qr' ); ?></span>
							<input type="text" data-field="street">
						</label>
						<div class="gqr-row">
							<label class="gqr-field">
								<span><?php esc_html_e( 'City', 'gallus-qr' ); ?></span>
								<input type="text" data-field="city">
							</label>
							<label class="gqr-field">
								<span><?php esc_html_e( 'Postcode', 'gallus-qr' ); ?></span>
								<input type="text" data-field="zip">
							</label>
							<label class="gqr-field">
								<span><?php esc_html_e( 'Country', 'gallus-qr' ); ?></span>
								<input type="text" data-field="country">
							</label>
						</div>
					</div>

					<!-- Email -->
					<div class="gqr-payload-panel" data-type="email" hidden>
						<label class="gqr-field">
							<span><?php esc_html_e( 'To (email address)', 'gallus-qr' ); ?></span>
							<input type="email" data-field="to">
						</label>
						<label class="gqr-field">
							<span><?php esc_html_e( 'Subject', 'gallus-qr' ); ?></span>
							<input type="text" data-field="subject">
						</label>
						<label class="gqr-field">
							<span><?php esc_html_e( 'Body', 'gallus-qr' ); ?></span>
							<textarea data-field="body" rows="3"></textarea>
						</label>
					</div>

					<!-- SMS -->
					<div class="gqr-payload-panel" data-type="sms" hidden>
						<label class="gqr-field">
							<span><?php esc_html_e( 'Phone number', 'gallus-qr' ); ?></span>
							<input type="tel" data-field="number">
						</label>
						<label class="gqr-field">
							<span><?php esc_html_e( 'Message', 'gallus-qr' ); ?></span>
							<textarea data-field="message" rows="3"></textarea>
						</label>
					</div>

					<!-- Phone -->
					<div class="gqr-payload-panel" data-type="tel" hidden>
						<label class="gqr-field">
							<span><?php esc_html_e( 'Phone number', 'gallus-qr' ); ?></span>
							<input type="tel" data-field="number">
						</label>
					</div>

					<!-- Calendar event -->
					<div class="gqr-payload-panel" data-type="event" hidden>
						<label class="gqr-field">
							<span><?php esc_html_e( 'Event title', 'gallus-qr' ); ?></span>
							<input type="text" data-field="summary">
						</label>
						<div class="gqr-row">
							<label class="gqr-field">
								<span><?php esc_html_e( 'Starts', 'gallus-qr' ); ?></span>
								<input type="datetime-local" data-field="start">
							</label>
							<label class="gqr-field">
								<span><?php esc_html_e( 'Ends', 'gallus-qr' ); ?></span>
								<input type="datetime-local" data-field="end">
							</label>
						</div>
						<label class="gqr-field">
							<span><?php esc_html_e( 'Location', 'gallus-qr' ); ?></span>
							<input type="text" data-field="location">
						</label>
						<label class="gqr-field">
							<span><?php esc_html_e( 'Description', 'gallus-qr' ); ?></span>
							<textarea data-field="description" rows="3"></textarea>
						</label>
					</div>

					<!-- Plain text -->
					<div class="gqr-payload-panel" data-type="text" hidden>
						<label class="gqr-field">
							<span><?php esc_html_e( 'Text', 'gallus-qr' ); ?></span>
							<textarea data-field="text" rows="4"></textarea>
						</label>
					</div>

					<div class="gqr-row">
						<label class="gqr-field">
							<span><?php esc_html_e( 'Dot shape', 'gallus-qr' ); ?></span>
							<select id="gqr-dot-style">
								<option value="square"><?php esc_html_e( 'Square', 'gallus-qr' ); ?></option>
								<option value="rounded"><?php esc_html_e( 'Rounded', 'gallus-qr' ); ?></option>
								<option value="dots"><?php esc_html_e( 'Dots', 'gallus-qr' ); ?></option>
								<option value="classy"><?php esc_html_e( 'Classy', 'gallus-qr' ); ?></option>
								<option value="classy-rounded"><?php esc_html_e( 'Classy rounded', 'gallus-qr' ); ?></option>
								<option value="extra-rounded"><?php esc_html_e( 'Extra rounded', 'gallus-qr' ); ?></option>
							</select>
						</label>

						<label class="gqr-field">
							<span><?php esc_html_e( 'Corner shape', 'gallus-qr' ); ?></span>
							<select id="gqr-corner-style">
								<option value="square"><?php esc_html_e( 'Square', 'gallus-qr' ); ?></option>
								<option value="extra-rounded"><?php esc_html_e( 'Rounded', 'gallus-qr' ); ?></option>
								<option value="dot"><?php esc_html_e( 'Dot', 'gallus-qr' ); ?></option>
							</select>
						</label>

						<label class="gqr-field">
							<span><?php esc_html_e( 'Corner dot', 'gallus-qr' ); ?></span>
							<select id="gqr-corner-dot">
								<option value="auto"><?php esc_html_e( 'Auto', 'gallus-qr' ); ?></option>
								<option value="square"><?php esc_html_e( 'Square', 'gallus-qr' ); ?></option>
								<option value="dot"><?php esc_html_e( 'Dot', 'gallus-qr' ); ?></option>
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

					<div class="gqr-row">
						<label class="gqr-field">
							<span><?php esc_html_e( 'Gradient', 'gallus-qr' ); ?></span>
							<select id="gqr-gradient">
								<option value="none"><?php esc_html_e( 'None (solid)', 'gallus-qr' ); ?></option>
								<option value="linear"><?php esc_html_e( 'Linear', 'gallus-qr' ); ?></option>
								<option value="radial"><?php esc_html_e( 'Radial', 'gallus-qr' ); ?></option>
							</select>
						</label>

						<label class="gqr-field" id="gqr-fg2-field" hidden>
							<span><?php esc_html_e( 'Second colour', 'gallus-qr' ); ?></span>
							<input type="color" id="gqr-fg2" value="#2271b1">
						</label>
					</div>

					<label class="gqr-field gqr-check">
						<input type="checkbox" id="gqr-bg-transparent">
						<span><?php esc_html_e( 'Transparent background', 'gallus-qr' ); ?></span>
					</label>

					<div class="gqr-field">
						<span><?php esc_html_e( 'Centre logo', 'gallus-qr' ); ?></span>
						<div class="gqr-row gqr-logo-row">
							<button type="button" id="gqr-logo-media" class="button">
								<?php esc_html_e( 'Media Library…', 'gallus-qr' ); ?>
							</button>
							<label class="button gqr-upload-label">
								<?php esc_html_e( 'Upload…', 'gallus-qr' ); ?>
								<input type="file" id="gqr-logo" accept="image/png,image/jpeg,image/svg+xml" hidden>
							</label>
							<button type="button" id="gqr-logo-clear" class="button-link gqr-clear-link">
								<?php esc_html_e( 'Remove', 'gallus-qr' ); ?>
							</button>
						</div>
						<span class="gqr-help" id="gqr-logo-status"></span>
					</div>

					<!-- Frame / call-to-action label -->
					<div class="gqr-field">
						<span><?php esc_html_e( 'Frame', 'gallus-qr' ); ?></span>
						<div class="gqr-row">
							<label class="gqr-field">
								<span><?php esc_html_e( 'Style', 'gallus-qr' ); ?></span>
								<select id="gqr-frame-style">
									<option value="none"><?php esc_html_e( 'None', 'gallus-qr' ); ?></option>
									<option value="label-bottom"><?php esc_html_e( 'Label below', 'gallus-qr' ); ?></option>
									<option value="label-top"><?php esc_html_e( 'Label above', 'gallus-qr' ); ?></option>
								</select>
							</label>
							<label class="gqr-field">
								<span><?php esc_html_e( 'Text', 'gallus-qr' ); ?></span>
								<input type="text" id="gqr-frame-text" value="SCAN ME" maxlength="40">
							</label>
						</div>
						<div class="gqr-row" id="gqr-frame-colors" hidden>
							<label class="gqr-field">
								<span><?php esc_html_e( 'Band colour', 'gallus-qr' ); ?></span>
								<input type="color" id="gqr-frame-band" value="#000000">
							</label>
							<label class="gqr-field">
								<span><?php esc_html_e( 'Text colour', 'gallus-qr' ); ?></span>
								<input type="color" id="gqr-frame-textcolor" value="#ffffff">
							</label>
						</div>
					</div>

					<!-- Design presets -->
					<div class="gqr-field">
						<span><?php esc_html_e( 'Presets', 'gallus-qr' ); ?></span>
						<div class="gqr-row">
							<select id="gqr-preset-select">
								<option value=""><?php esc_html_e( '— Apply a preset —', 'gallus-qr' ); ?></option>
							</select>
							<button type="button" id="gqr-preset-delete" class="button-link gqr-clear-link" hidden>
								<?php esc_html_e( 'Delete', 'gallus-qr' ); ?>
							</button>
						</div>
						<div class="gqr-row">
							<input type="text" id="gqr-preset-name" placeholder="<?php esc_attr_e( 'Preset name', 'gallus-qr' ); ?>">
							<button type="button" id="gqr-preset-save" class="button">
								<?php esc_html_e( 'Save preset', 'gallus-qr' ); ?>
							</button>
						</div>
						<span class="gqr-help" id="gqr-preset-status"></span>
					</div>

					<label class="gqr-field">
						<span>
							<?php esc_html_e( 'Export size (px)', 'gallus-qr' ); ?>
							<output id="gqr-size-value">512</output>
						</span>
						<input type="range" id="gqr-size" min="128" max="1024" step="64" value="512">
						<span class="gqr-help"><?php esc_html_e( 'Downloaded PNG resolution (128–1024). SVG stays vector.', 'gallus-qr' ); ?></span>
					</label>

					<!-- Tracking (shown only in Trackable mode) -->
					<div id="gqr-track-fields" class="gqr-track-fields" hidden>
						<label class="gqr-field">
							<span><?php esc_html_e( 'Label (for your stats)', 'gallus-qr' ); ?></span>
							<input type="text" id="gqr-title" placeholder="<?php esc_attr_e( 'e.g. Business card', 'gallus-qr' ); ?>">
						</label>
						<label class="gqr-field" id="gqr-slug-field">
							<span><?php esc_html_e( 'Custom link (optional)', 'gallus-qr' ); ?></span>
							<span class="gqr-slug-row">
								<code>/qr/</code>
								<input type="text" id="gqr-slug" placeholder="<?php esc_attr_e( 'e.g. summer-sale', 'gallus-qr' ); ?>" maxlength="64" autocomplete="off" spellcheck="false">
							</span>
							<span class="gqr-help" id="gqr-slug-status"></span>
						</label>
						<details class="gqr-advanced" id="gqr-advanced">
							<summary><?php esc_html_e( 'Advanced options', 'gallus-qr' ); ?></summary>

							<div class="gqr-row">
								<label class="gqr-field">
									<span><?php esc_html_e( 'Expires', 'gallus-qr' ); ?></span>
									<input type="datetime-local" id="gqr-expires">
								</label>
								<label class="gqr-field">
									<span><?php esc_html_e( 'Scan limit', 'gallus-qr' ); ?></span>
									<input type="number" id="gqr-max-scans" min="0" step="1" placeholder="<?php esc_attr_e( '0 = unlimited', 'gallus-qr' ); ?>">
								</label>
							</div>

							<label class="gqr-field">
								<span><?php esc_html_e( 'Fallback URL (expired/paused/limit reached)', 'gallus-qr' ); ?></span>
								<input type="url" id="gqr-fallback" placeholder="<?php esc_attr_e( 'Leave empty for a plain “no longer active” page', 'gallus-qr' ); ?>">
							</label>

							<label class="gqr-field">
								<span><?php esc_html_e( 'Destination mode', 'gallus-qr' ); ?></span>
								<select id="gqr-dest-mode">
									<option value="single"><?php esc_html_e( 'Single destination', 'gallus-qr' ); ?></option>
									<option value="schedule"><?php esc_html_e( 'Switch on a date', 'gallus-qr' ); ?></option>
									<option value="ab"><?php esc_html_e( 'A/B rotation', 'gallus-qr' ); ?></option>
								</select>
							</label>

							<div id="gqr-dest-extra" hidden>
								<label class="gqr-field">
									<span id="gqr-dest-b-label"><?php esc_html_e( 'Second destination (B)', 'gallus-qr' ); ?></span>
									<input type="url" id="gqr-dest-b">
								</label>
								<label class="gqr-field" id="gqr-switch-field" hidden>
									<span><?php esc_html_e( 'Switch over at', 'gallus-qr' ); ?></span>
									<input type="datetime-local" id="gqr-switch-at">
								</label>
								<label class="gqr-field" id="gqr-split-field" hidden>
									<span>
										<?php esc_html_e( 'Traffic to B (%)', 'gallus-qr' ); ?>
										<output id="gqr-split-value">50</output>
									</span>
									<input type="range" id="gqr-ab-split" min="0" max="100" step="5" value="50">
								</label>
							</div>
						</details>

						<button type="button" id="gqr-save" class="button button-secondary">
							<?php esc_html_e( 'Save &amp; make trackable', 'gallus-qr' ); ?>
						</button>
						<p id="gqr-save-result" class="gqr-save-result" hidden></p>
					</div>
				</div>

				<!-- RIGHT: live preview + downloads -->
				<div class="gqr-preview">
					<div id="gqr-badge" class="gqr-badge"></div>
					<div id="gqr-canvas" class="gqr-canvas"></div>
					<p id="gqr-encodes" class="gqr-encodes"></p>
					<div class="gqr-downloads">
						<button type="button" id="gqr-download-png" class="button button-primary">
							<?php esc_html_e( 'PNG', 'gallus-qr' ); ?>
						</button>
						<button type="button" id="gqr-download-jpeg" class="button button-primary">
							<?php esc_html_e( 'JPEG', 'gallus-qr' ); ?>
						</button>
						<button type="button" id="gqr-download-svg" class="button button-primary">
							<?php esc_html_e( 'SVG', 'gallus-qr' ); ?>
						</button>
					</div>
					<p id="gqr-download-hint" class="gqr-help gqr-download-hint" hidden></p>
				</div>
			</div>
		</div>
		<?php
	}
}
