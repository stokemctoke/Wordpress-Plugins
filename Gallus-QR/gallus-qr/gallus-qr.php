<?php
/**
 * Plugin Name:       Gallus QR
 * Plugin URI:        https://stokemctoke.com
 * Description:       Free, self-hosted custom QR code generator — centre logo, custom shapes, adjustable export size, PNG/SVG export, editable dynamic codes, scan analytics, and faithful re-download of saved designs.
 * Version:           2.0.0
 * Author:            Gallus Gadgets
 * Author URI:        https://gallusgadgets.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gallus-qr
 *
 * This header comment is what makes WordPress recognise the folder as a plugin
 * and list it under Plugins. The fields above show up on that screen.
 */

// Block direct access — WordPress defines ABSPATH, a raw web hit does not.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Handy constants so other files can find themselves and bust asset caches.
define( 'GALLUS_QR_VERSION', '2.0.0' );
define( 'GALLUS_QR_DB_VERSION', '3' );                       // bump when the schema changes
define( 'GALLUS_QR_PATH', plugin_dir_path( __FILE__ ) );   // /…/gallus-qr/
define( 'GALLUS_QR_URL', plugin_dir_url( __FILE__ ) );      // https://…/gallus-qr/

// Load the classes.
require_once GALLUS_QR_PATH . 'includes/class-settings.php';
require_once GALLUS_QR_PATH . 'includes/class-payloads.php';
require_once GALLUS_QR_PATH . 'includes/class-analytics.php';
require_once GALLUS_QR_PATH . 'includes/class-database.php';
require_once GALLUS_QR_PATH . 'includes/class-redirect.php';
require_once GALLUS_QR_PATH . 'includes/class-rest.php';
require_once GALLUS_QR_PATH . 'includes/class-admin.php';
require_once GALLUS_QR_PATH . 'includes/class-admin-stats.php';
require_once GALLUS_QR_PATH . 'includes/class-admin-tools.php';
require_once GALLUS_QR_PATH . 'includes/class-shortcode.php';
require_once GALLUS_QR_PATH . 'includes/class-cron.php';
require_once GALLUS_QR_PATH . 'includes/class-dashboard-widget.php';
require_once GALLUS_QR_PATH . 'includes/class-integrations.php';

/**
 * Activation: create the tables, grant the plugin capability to admins, and
 * register + flush the /qr/ rewrite rule so the redirect URLs work immediately
 * (no "Save Permalinks" needed by hand).
 */
register_activation_hook( __FILE__, static function () {
	$db = new Gallus_QR_Database();
	$db->create_tables();

	$role = get_role( 'administrator' );
	if ( $role ) {
		$role->add_cap( 'manage_gallus_qr' );
	}

	Gallus_QR_Redirect::register_rewrite();
	flush_rewrite_rules();
} );

// Deactivation: tidy up the rewrite rules and the daily cron event
// (tables/data are left intact).
register_deactivation_hook( __FILE__, static function () {
	flush_rewrite_rules();
	Gallus_QR_Cron::clear();
} );

// Boot the plugin once WordPress is ready.
add_action( 'plugins_loaded', static function () {
	$db = new Gallus_QR_Database();

	// Run schema upgrades in place — no manual reactivation needed after an update.
	$db->maybe_upgrade();

	$stats    = new Gallus_QR_Admin_Stats( $db );
	$settings = new Gallus_QR_Settings();
	$tools    = new Gallus_QR_Admin_Tools( $db );

	( new Gallus_QR_Redirect( $db ) )->init();
	( new Gallus_QR_REST( $db ) )->init();
	( new Gallus_QR_Admin( $db, $stats, $settings, $tools ) )->init();
	( new Gallus_QR_Shortcode( $db ) )->init();
	( new Gallus_QR_Cron( $db ) )->init();
	( new Gallus_QR_Dashboard_Widget( $db ) )->init();
	( new Gallus_QR_Integrations() )->init();
	$tools->init();
	$settings->init();
} );
