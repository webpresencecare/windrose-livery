<?php
/**
 * PHPUnit bootstrap: the WordPress test library (multisite), with Windrose Livery loaded as a network plugin.
 *
 * Environment:
 * - WP_TESTS_DIR: the test library (tests/phpunit of wordpress-develop). Default: wordpress-tests-lib in the system's
 *   temporary folder.
 * - WP_TESTS_CONFIG_FILE_PATH: its wp-tests-config.php (optional; default next to the test library).
 *
 * Use a database of its own for the tests, never a site's: the test library empties it. On GitHub (ci.yml),
 * bin/install-wp-tests.sh installs WordPress and the test library.
 *
 * @package Windrose\Livery
 */

$windrose_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : sys_get_temp_dir() . '/wordpress-tests-lib';

if ( getenv( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', getenv( 'WP_TESTS_CONFIG_FILE_PATH' ) );
}
// The PHPUnit Polyfills: this plugin's Composer copy, unless the PHPUnit in use already loaded them.
$windrose_polyfills = dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills';
if ( ! class_exists( 'Yoast\PHPUnitPolyfills\Autoload' ) && is_dir( $windrose_polyfills ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $windrose_polyfills );
}

require_once $windrose_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/windrose-livery.php';
	}
);

require $windrose_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/includes/class-livery-testcase.php';
