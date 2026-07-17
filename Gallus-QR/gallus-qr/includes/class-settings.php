<?php
/**
 * Plugin settings: a single `gallus_qr_settings` option holding an array, plus
 * the capability gate every admin/REST surface checks. The settings screen
 * itself lives on the admin side; this class is the storage + accessor layer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gallus_QR_Settings {

	const OPTION = 'gallus_qr_settings';

	/**
	 * Capability required to manage QR codes. Granted to administrators on
	 * activation/upgrade; the settings screen can map it onto other roles.
	 *
	 * @return string
	 */
	public static function capability() {
		return apply_filters( 'gallus_qr_capability', 'manage_gallus_qr' );
	}

	/** @return array Default values for every setting. */
	public static function defaults() {
		return array(
			'capability_role'      => '',   // extra role granted the capability ('' = admins only)
			'retention_days'       => 0,    // prune scans older than N days (0 = keep forever)
			'bot_filter'           => 1,    // skip counting obvious bots/crawlers
			'default_fallback_url' => '',   // where paused/expired/capped scans land ('' = 410 page)
			'delete_on_uninstall'  => 0,    // drop tables + options on uninstall
		);
	}

	/** @return array Full settings array, defaults filled in. */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	/**
	 * One setting.
	 *
	 * @param string $key
	 * @return mixed Null for unknown keys.
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Persist a partial update (merged over the saved values).
	 *
	 * @param array $values
	 */
	public static function update( array $values ) {
		$known = array_intersect_key( $values, self::defaults() );
		update_option( self::OPTION, array_merge( self::all(), $known ) );
	}

	// --- Settings screen ----------------------------------------------------------

	/**
	 * Wire up WordPress hooks. Called from gallus-qr.php on plugins_loaded.
	 * (The menu entry is registered by Gallus_QR_Admin.)
	 */
	public function init() {
		add_action( 'admin_post_gallus_qr_save_settings', array( $this, 'handle_save' ) );
	}

	/**
	 * Save the settings form. Locked to manage_options — the capability
	 * mapping below is a security decision, not day-to-day QR work.
	 */
	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'gallus_qr_settings' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'gallus-qr' ) );
		}

		$role_key = isset( $_POST['capability_role'] ) ? sanitize_key( $_POST['capability_role'] ) : '';
		if ( $role_key && null === get_role( $role_key ) ) {
			$role_key = '';
		}

		$fallback = isset( $_POST['default_fallback_url'] ) ? esc_url_raw( wp_unslash( $_POST['default_fallback_url'] ) ) : '';
		if ( $fallback && ! wp_http_validate_url( $fallback ) ) {
			$fallback = '';
		}

		self::update(
			array(
				'capability_role'      => $role_key,
				'retention_days'       => isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 0,
				'bot_filter'           => empty( $_POST['bot_filter'] ) ? 0 : 1,
				'default_fallback_url' => $fallback,
				'delete_on_uninstall'  => empty( $_POST['delete_on_uninstall'] ) ? 0 : 1,
			)
		);

		// Re-map the capability: admins always keep it; exactly one extra role
		// (or none) may hold it besides them.
		$cap = 'manage_gallus_qr';
		foreach ( array_keys( wp_roles()->roles ) as $key ) {
			if ( 'administrator' === $key ) {
				continue;
			}
			$role = get_role( $key );
			if ( $role && $role->has_cap( $cap ) ) {
				$role->remove_cap( $cap );
			}
		}
		if ( $role_key && 'administrator' !== $role_key ) {
			$extra = get_role( $role_key );
			if ( $extra ) {
				$extra->add_cap( $cap );
			}
		}

		wp_safe_redirect( add_query_arg( 'gqr_saved', '1', admin_url( 'admin.php?page=gallus-qr-settings' ) ) );
		exit;
	}

	/**
	 * The settings screen.
	 */
	public function render_page() {
		$settings = self::all();
		?>
		<div class="wrap gqr-wrap">
			<h1><?php esc_html_e( 'Gallus QR — Settings', 'gallus-qr' ); ?></h1>

			<?php if ( isset( $_GET['gqr_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'gallus-qr' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="gallus_qr_save_settings">
				<?php wp_nonce_field( 'gallus_qr_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="gqr-set-role"><?php esc_html_e( 'Extra role with access', 'gallus-qr' ); ?></label>
						</th>
						<td>
							<select name="capability_role" id="gqr-set-role">
								<option value=""><?php esc_html_e( 'Administrators only', 'gallus-qr' ); ?></option>
								<?php foreach ( wp_roles()->roles as $key => $role ) : ?>
									<?php
									if ( 'administrator' === $key ) {
										continue;
									}
									?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $settings['capability_role'] ); ?>>
										<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Administrators can always manage QR codes; pick one more role to share access with.', 'gallus-qr' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gqr-set-retention"><?php esc_html_e( 'Keep scan data for', 'gallus-qr' ); ?></label>
						</th>
						<td>
							<input type="number" name="retention_days" id="gqr-set-retention" min="0" step="1" value="<?php echo esc_attr( (string) $settings['retention_days'] ); ?>" class="small-text">
							<?php esc_html_e( 'days (0 = forever)', 'gallus-qr' ); ?>
							<p class="description"><?php esc_html_e( 'Older scan rows are pruned daily. Lifetime totals and scan limits are unaffected.', 'gallus-qr' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Bot filtering', 'gallus-qr' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="bot_filter" value="1" <?php checked( $settings['bot_filter'] ); ?>>
								<?php esc_html_e( 'Don’t count scans from crawlers and link-preview bots', 'gallus-qr' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="gqr-set-fallback"><?php esc_html_e( 'Default fallback URL', 'gallus-qr' ); ?></label>
						</th>
						<td>
							<input type="url" name="default_fallback_url" id="gqr-set-fallback" value="<?php echo esc_attr( $settings['default_fallback_url'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Where paused, expired or limit-reached codes send visitors when the code has no fallback of its own. Empty = a plain “no longer active” page.', 'gallus-qr' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'gallus-qr' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( $settings['delete_on_uninstall'] ); ?>>
								<?php esc_html_e( 'Delete all codes, scans and settings when the plugin is uninstalled', 'gallus-qr' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'gallus-qr' ) ); ?>
			</form>
		</div>
		<?php
	}
}
