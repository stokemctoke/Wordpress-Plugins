<?php
/**
 * Plugin Name:       Gallus QR
 * Plugin URI:        https://stokemctoke.com
 * Description:       Free, self-hosted custom QR code generator — centre logo, custom shapes, adjustable export size, PNG/SVG export, editable dynamic codes, and scan analytics.
 * Version:           0.4.0
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
define( 'GALLUS_QR_VERSION', '0.4.0' );
define( 'GALLUS_QR_PATH', plugin_dir_path( __FILE__ ) );   // /…/gallus-qr/
define( 'GALLUS_QR_URL', plugin_dir_url( __FILE__ ) );      // https://…/gallus-qr/

// Load the classes.
require_once GALLUS_QR_PATH . 'includes/class-database.php';
require_once GALLUS_QR_PATH . 'includes/class-redirect.php';
require_once GALLUS_QR_PATH . 'includes/class-rest.php';
require_once GALLUS_QR_PATH . 'includes/class-admin.php';

/**
 * Activation: create the tables and register + flush the /qr/ rewrite rule so
 * the redirect URLs work immediately (no "Save Permalinks" needed by hand).
 */
register_activation_hook( __FILE__, static function () {
	$db = new Gallus_QR_Database();
	$db->create_tables();

	Gallus_QR_Redirect::register_rewrite();
	flush_rewrite_rules();
} );

// Deactivation: tidy up the rewrite rules (tables/data are left intact).
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

// Boot the plugin once WordPress is ready.
add_action( 'plugins_loaded', static function () {
	$db = new Gallus_QR_Database();

	( new Gallus_QR_Redirect( $db ) )->init();
	( new Gallus_QR_REST( $db ) )->init();
	( new Gallus_QR_Admin( $db ) )->init();
} );
