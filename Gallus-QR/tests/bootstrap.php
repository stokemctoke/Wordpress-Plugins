<?php
/**
 * PHPUnit bootstrap: loads the WP test suite (provided by @wordpress/env's
 * tests container) and this plugin.
 */

$gallus_qr_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $gallus_qr_tests_dir ) {
	$gallus_qr_tests_dir = '/wordpress-phpunit'; // wp-env default
}

if ( ! file_exists( $gallus_qr_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test suite at {$gallus_qr_tests_dir}.\n";
	echo "Run the tests through wp-env: npm run test:php\n";
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $gallus_qr_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/gallus-qr.php';
	}
);

require $gallus_qr_tests_dir . '/includes/bootstrap.php';
