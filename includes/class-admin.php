<?php
/**
 * Livery's screen (React, src/admin, built into build/):
 *
 * - on a network: Network Admin, Themes, Style variations (`manage_network_themes`): the shared library;
 * - on a single site: Appearance, Style variations (`edit_theme_options`);
 * - on each site of a network, when the network allows it: Appearance, Style variations, the site's own styles,
 *   for its administrators (`edit_theme_options` there; network administrators too).
 *
 * Also the export download (`admin-post.php?action=windrose_livery_export&id=<id>&scope=<site>`, nonce, the right to
 * manage that library).
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screen and export.
 */
final class Admin {

	public const SLUG   = 'windrose-livery';
	public const HANDLE = 'windrose-livery-admin';
	public const EXPORT = 'windrose_livery_export';

	/**
	 * Hook suffix of the screen.
	 *
	 * @var string
	 */
	private static string $hook = '';

	/**
	 * Hooks the menu, assets, export and the Plugins screen link.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( __CLASS__, 'menu' ) );
			add_action( 'admin_menu', array( __CLASS__, 'site_menu' ) );
			add_filter( 'network_admin_plugin_action_links_' . plugin_basename( WINDROSE_LIVERY_FILE ), array( __CLASS__, 'action_links' ) );
		} else {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( WINDROSE_LIVERY_FILE ), array( __CLASS__, 'action_links' ) );
		}
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_' . self::EXPORT, array( __CLASS__, 'export' ) );
	}

	/**
	 * The screen's address.
	 *
	 * @return string
	 */
	public static function url(): string {
		return is_multisite() ? network_admin_url( 'themes.php?page=' . self::SLUG ) : admin_url( 'themes.php?page=' . self::SLUG );
	}

	/**
	 * The address of the current site's own screen (empty when the current user may not use it).
	 *
	 * @return string
	 */
	public static function site_url(): string {
		return is_multisite() && Library::can_manage_site( get_current_blog_id() ) ? admin_url( 'themes.php?page=' . self::SLUG ) : '';
	}

	/**
	 * Appearance, Style variations on a site of a network, for those who manage its own styles.
	 *
	 * @return void
	 */
	public static function site_menu(): void {
		if ( ! Library::can_manage_site( get_current_blog_id() ) ) {
			return;
		}
		$title      = __( 'Style variations', 'windrose-livery' );
		$hook       = add_theme_page( $title, $title, 'edit_theme_options', self::SLUG, array( __CLASS__, 'render' ) );
		self::$hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Adds the menu item under Themes (network) or Appearance (single site).
	 *
	 * @return void
	 */
	public static function menu(): void {
		$title      = __( 'Style variations', 'windrose-livery' );
		$hook       = is_multisite()
			? add_submenu_page( 'themes.php', $title, $title, 'manage_network_themes', self::SLUG, array( __CLASS__, 'render' ) )
			: add_theme_page( $title, $title, 'edit_theme_options', self::SLUG, array( __CLASS__, 'render' ) );
		self::$hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Prints the screen container; the app renders into it.
	 *
	 * @return void
	 */
	public static function render(): void {
		printf(
			'<div class="wrap windrose-livery"><h1>%1$s</h1><div id="windrose-livery-app"><p>%2$s</p></div><noscript><p>%3$s</p></noscript></div>',
			esc_html__( 'Style variations', 'windrose-livery' ),
			esc_html__( 'Loading…', 'windrose-livery' ),
			esc_html__( 'This screen needs JavaScript.', 'windrose-livery' )
		);
	}

	/**
	 * Loads the app on its screen only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( '' === self::$hook || $hook_suffix !== self::$hook ) {
			return;
		}
		$asset_file = WINDROSE_LIVERY_DIR . '/build/admin.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		$deps  = is_array( $asset ) && is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array();
		$ver   = is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : WINDROSE_LIVERY_VERSION;
		// On a site of a network (not Network Admin), the screen works on that site's own styles.
		$scope = is_multisite() && ! is_network_admin() ? get_current_blog_id() : 0;
		Library::use_scope( $scope );

		wp_enqueue_script( self::HANDLE, plugins_url( 'build/admin.js', WINDROSE_LIVERY_FILE ), $deps, $ver, array( 'in_footer' => true ) );
		wp_set_script_translations( self::HANDLE, 'windrose-livery', WINDROSE_LIVERY_DIR . '/languages' );
		wp_add_inline_script(
			self::HANDLE,
			'window.windroseLivery = ' . wp_json_encode(
				array(
					'root'       => '/' . Rest::NAMESPACE,
					'network'    => is_multisite() && 0 === $scope,
					'multisite'  => is_multisite(),
					'scope'      => $scope,
					'siteId'     => get_current_blog_id(),
					'siteName'   => get_bloginfo( 'name' ),
					'siteAdmins' => Network::site_admins(),
					'exportUrl'  => add_query_arg(
						array(
							'action'   => self::EXPORT,
							'_wpnonce' => wp_create_nonce( self::EXPORT ),
						),
						admin_url( 'admin-post.php' )
					),
					'maxUpload'  => wp_max_upload_size(),
					// The GitHub release that carries the font packs (Font packs card).
					'packsTag'   => 'v' . Packs::release(),
					'editor'     => admin_url( 'site-editor.php?p=%2Fstyles' ),
					'mermaid'    => Mermaid::config( 'admin' ),
				)
			) . ';',
			'before'
		);
		wp_enqueue_style( self::HANDLE, plugins_url( 'build/admin.css', WINDROSE_LIVERY_FILE ), array( 'wp-components' ), $ver );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
	}

	/**
	 * Sends a stored variation as a zip download.
	 *
	 * @return void
	 */
	public static function export(): void {
		check_admin_referer( self::EXPORT );
		Library::use_scope( isset( $_GET['scope'] ) ? absint( $_GET['scope'] ) : 0 );
		if ( ! Library::can_manage() ) {
			wp_die( esc_html__( 'You may not export style variations.', 'windrose-livery' ), '', array( 'response' => 403 ) );
		}
		$item = Library::get( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		if ( null === $item ) {
			wp_die( esc_html__( 'This style variation no longer exists.', 'windrose-livery' ), '', array( 'response' => 404 ) );
		}
		$zip = Package::export( $item );
		if ( is_wp_error( $zip ) ) {
			wp_die( esc_html( $zip->get_error_message() ), '', array( 'response' => 500 ) );
		}
		$slug = sanitize_title( $item['title'] );
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . ( '' === $slug ? 'style-variation' : $slug ) . '.zip"' );
		header( 'Content-Length: ' . (string) filesize( $zip ) );
		header( 'X-Content-Type-Options: nosniff' );
		// Deleted when PHP ends, also when the download is stopped half-way.
		register_shutdown_function( 'wp_delete_file', $zip );
		readfile( $zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams the temporary zip.
		exit;
	}

	/**
	 * "Style variations" link on the Plugins screen.
	 *
	 * @param array<string, string> $links Action links.
	 * @return array<string, string>
	 */
	public static function action_links( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}
		return array_merge(
			array( 'windrose-livery' => sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::url() ), esc_html__( 'Style variations', 'windrose-livery' ) ) ),
			$links
		);
	}
}
