<?php
/**
 * Plugin Name:       Windrose Livery - Style Variations
 * Plugin URI:        https://www.webpresencecare.com/plugins
 * Description:       Style variations kept in the database: make them with Style Mermaid, import, save a site's styles, export, and apply to sites; browse the site's patterns with a style; light and dark palettes with a switch block. They appear in the Site Editor next to the theme's own, on the theme they were made for and its child themes, and theme updates never remove them.
 * Version:           0.9.5
 * Requires at least: 7.2
 * Requires PHP:      8.4
 * Author:            Patricia BT
 * Author URI:        https://patriciabt.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       windrose-livery
 * Domain Path:       /languages
 * Network:           true
 * Update URI:        https://github.com/webpresencecare/windrose-livery
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WINDROSE_LIVERY_VERSION', '0.9.5' );
define( 'WINDROSE_LIVERY_FILE', __FILE__ );
define( 'WINDROSE_LIVERY_DIR', __DIR__ );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$name = substr( $class_name, strlen( $prefix ) );
		$file = __DIR__ . '/includes/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( ! str_contains( $name, '\\' ) && is_readable( $file ) ) {
			require_once $file;
		}
	}
);

require_once __DIR__ . '/common/bootstrap.php';

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
