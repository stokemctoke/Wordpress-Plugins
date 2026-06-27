<?php
/**
 * Plugin Name:       Gallus QR
 * Plugin URI:        https://stokemctoke.com
 * Description:       Free, self-hosted custom QR code generator — centre logo, custom shapes, PNG/SVG export. (Milestone 1: generator only; scan tracking lands in Milestone 2.)
 * Version:           0.1.0
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
define( 'GALLUS_QR_VERSION', '0.1.0' );
define( 'GALLUS_QR_PATH', plugin_dir_path( __FILE__ ) );   // /…/gallus-qr/
define( 'GALLUS_QR_URL', plugin_dir_url( __FILE__ ) );      // https://…/gallus-qr/

// Load the admin class (menu, generator screen, asset loading).
require_once GALLUS_QR_PATH . 'includes/class-admin.php';

// Boot the plugin once WordPress is ready.
add_action( 'plugins_loaded', static function () {
	$admin = new Gallus_QR_Admin();
	$admin->init();
} );
