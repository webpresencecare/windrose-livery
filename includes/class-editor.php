<?php
/**
 * Stored variations in the Site Editor.
 *
 * Core reads style variations only from the active theme's `styles/` folder and has no PHP filter on that list. The
 * Site Editor asks `GET /wp/v2/global-styles/themes/<theme>/variations` (also preloaded); Livery appends the stored
 * variations that fit the active theme to that answer (`rest_request_after_callbacks`, which also runs for preloaded
 * requests). A Playwright test checks it on every WordPress build, since it depends on the editor reading that route.
 *
 * The variations a site is offered: the shared library's and, on a network, the site's own.
 *
 * In the Site Editor's top Options menu (⋮):
 * - "Save styles as a variation" saves the site's saved styles (POST /from-site) into the shared library (network
 *   administrators; on a single site, its administrators) or into the site's own styles (its administrators, when
 *   the network allows it; network administrators choose where);
 * - "Add a dark palette" (or light): calculates twin colours (`Schemes`) from the current palette, checks and fixes
 *   their contrast, shows them, and adds them to the styles being edited (saved with core's Save button);
 * - "Preview dark" (or light): shows the canvas with the twins while it is on.
 * Light and dark items are for anyone who may edit the site's styles (`edit_theme_options`).
 *
 * Their fonts are added to the editor's canvas and previews as @font-face rules (core does the same for the theme's
 * variations, `wp_print_font_faces_from_style_variations()`). Once a site uses a variation, its saved styles hold the
 * fonts and core prints them on the front end like any other.
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site Editor integration.
 */
final class Editor {

	public const HANDLE = 'windrose-livery-fonts';
	public const SCRIPT = 'windrose-livery-editor';

	/**
	 * Hooks the REST answer and the editor fonts.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'add_variations' ), 10, 3 );
		add_action( 'enqueue_block_assets', array( __CLASS__, 'editor_fonts' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'menu_item' ) );
	}

	/**
	 * Loads the "Save styles as a variation" menu item, in the Site Editor only, for library managers.
	 *
	 * @return void
	 */
	public static function menu_item(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'site-editor' !== $screen->id || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		$shared     = Library::with_scope( 0, static fn(): bool => Library::can_manage() );
		$own        = Library::can_manage_site( get_current_blog_id() );
		$asset_file = WINDROSE_LIVERY_DIR . '/build/editor.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		$deps  = is_array( $asset ) && is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array();
		$ver   = is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : WINDROSE_LIVERY_VERSION;
		$theme = array();
		foreach ( array_unique( array( get_stylesheet(), get_template() ) ) as $stylesheet ) {
			$theme[] = array(
				'value' => $stylesheet,
				'label' => Themes::name( $stylesheet ),
			);
		}
		wp_enqueue_script( self::SCRIPT, plugins_url( 'build/editor.js', WINDROSE_LIVERY_FILE ), $deps, $ver, array( 'in_footer' => true ) );
		wp_set_script_translations( self::SCRIPT, 'windrose-livery', WINDROSE_LIVERY_DIR . '/languages' );
		wp_add_inline_script(
			self::SCRIPT,
			'window.windroseLivery = ' . wp_json_encode(
				array(
					'root'    => '/' . Rest::NAMESPACE,
					'network' => is_multisite(),
					'siteId'  => get_current_blog_id(),
					'themes'  => $theme,
					'screen'  => $shared ? Admin::url() : Admin::site_url(),
					'shared'  => $shared,
					'own'     => $own,
					'prefix'  => Schemes::PREFIX,
				)
			) . ';',
			'before'
		);
		wp_enqueue_style( self::SCRIPT, plugins_url( 'build/editor.css', WINDROSE_LIVERY_FILE ), array( 'wp-components' ), $ver );
		wp_style_add_data( self::SCRIPT, 'rtl', 'replace' );
	}

	/**
	 * Appends the fitting stored variations to the theme's.
	 *
	 * @param mixed           $response Response.
	 * @param mixed           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function add_variations( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request || ! $response instanceof WP_REST_Response || 'GET' !== $request->get_method() ) {
			return $response;
		}
		if ( 1 !== preg_match( '#^/wp/v2/global-styles/themes/(.+)/variations$#', $request->get_route(), $m ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( 200 !== $response->get_status() || ! is_array( $data ) || urldecode( $m[1] ) !== get_stylesheet() ) {
			return $response;
		}
		foreach ( Library::offered( get_current_blog_id(), get_stylesheet(), get_template() ) as $item ) {
			$entry = array(
				'version' => \WP_Theme_JSON::LATEST_SCHEMA,
				'title'   => $item['title'],
			);
			if ( '' !== $item['description'] ) {
				$entry['description'] = $item['description'];
			}
			$data[] = $entry + Library::objects( Library::resolved( $item ) );
		}
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Fonts of the fitting stored variations in the editor (canvas and style previews).
	 *
	 * @return void
	 */
	public static function editor_fonts(): void {
		if ( ! is_admin() ) {
			return;
		}
		$css = '';
		foreach ( Library::offered( get_current_blog_id(), get_stylesheet(), get_template() ) as $item ) {
			$css .= Fonts::css( Library::font_families( Library::resolved( $item )['settings'] ) );
		}
		if ( '' === $css ) {
			return;
		}
		wp_register_style( self::HANDLE, false, array(), WINDROSE_LIVERY_VERSION );
		wp_add_inline_style( self::HANDLE, $css );
		wp_enqueue_style( self::HANDLE );
	}
}
