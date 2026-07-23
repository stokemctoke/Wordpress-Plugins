<?php
/**
 * Plugin Name:       Stoke Chat
 * Plugin URI:        https://github.com/stokemctoke/Wordpress-Plugins
 * Description:       Self-hosted chat rooms for logged-in users. Public and private rooms, per-room roles, @mentions, and away email alerts.
 * Version:           1.1.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Stoke McToke
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       stoke-chat
 */

defined( 'ABSPATH' ) || exit;

define( 'STOKECHAT_VERSION', '1.1.3' );
define( 'STOKECHAT_DB_VERSION', '1' );
define( 'STOKECHAT_PLUGIN_FILE', __FILE__ );
define( 'STOKECHAT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'STOKECHAT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader: StokeChat\Foo_Bar -> includes/class-foo-bar.php,
 * StokeChat\Rest\Foo_Bar -> includes/rest/class-foo-bar.php.
 */
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'StokeChat\\' ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class, strlen( 'StokeChat\\' ) ) );
		$file  = 'class-' . str_replace( '_', '-', strtolower( array_pop( $parts ) ) ) . '.php';
		$dir   = STOKECHAT_PLUGIN_DIR . 'includes/';
		if ( $parts ) {
			$dir .= strtolower( implode( '/', $parts ) ) . '/';
		}
		if ( file_exists( $dir . $file ) ) {
			require $dir . $file;
		}
	}
);

register_activation_hook(
	__FILE__,
	function () {
		\StokeChat\Schema::install();
		\StokeChat\Capabilities::grant_default();
	}
);

add_action( 'plugins_loaded', array( 'StokeChat\\Plugin', 'instance' ) );
