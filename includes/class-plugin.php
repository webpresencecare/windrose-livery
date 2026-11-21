<?php
/**
 * Windrose Livery: start-up.
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the plugin.
 */
final class Plugin {

	/**
	 * Hooks everything (on plugins_loaded). Works on a single site and on a network.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy_content' ) );
		Library::init();
		Network::init();
		Font_Library::init();
		Schemes::init();
		Rename_Colors::init();
		Editor::init();
		Tracker::init();
		Rest::init();
		Mermaid::init();
		Patterns::init();
		Languages::init();
		Admin::init();
	}

	/**
	 * Loads translations.
	 *
	 * @return void
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'windrose-livery', false, dirname( plugin_basename( WINDROSE_LIVERY_FILE ) ) . '/languages' );
	}

	/**
	 * Suggested text for each site's privacy policy (Settings, Privacy, Policy guide).
	 *
	 * @return void
	 */
	public static function privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p>' . esc_html__( 'Windrose Livery collects nothing about visitors, sets no cookies and contacts no other service. The fonts of its style variations are served from this website, never from another host.', 'windrose-livery' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Where a page offers a light and dark switch, the visitor’s choice is kept in their own browser (local storage) so the next page opens the same way. It is never sent to this website or anyone else, and it is not a cookie. The same goes for the style a visitor makes in Style Mermaid when they open this website’s pattern library from it.', 'windrose-livery' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Where a page offers Style Mermaid (a tool to make and download a style variation), the choices made in it are sent to this website to build the preview and the download, and are not stored; the same goes for the patterns it can show with that style, which use sample text instead of this website’s content, also when a pattern library on this website shows its patterns in a chosen style. To limit how often one connection can use it, a keyed hash of the visitor’s IP address (not the address itself) and a counter are kept for one hour. If the site allows fonts from Google Fonts or Bunny Fonts there, this website downloads a chosen font once from that service and serves it itself: the visitor’s browser never contacts those services.', 'windrose-livery' ) . '</p>';
		wp_add_privacy_policy_content( __( 'Windrose Livery', 'windrose-livery' ), wp_kses_post( $text ) );
	}
}
