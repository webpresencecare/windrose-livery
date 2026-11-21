<?php
/**
 * The network's switch for site administrators: network option `windrose_livery_network` =
 * `{ site_admins: bool }`, off by default, set by network administrators on Livery's network screen.
 *
 * When on, each site's administrators (`edit_theme_options` there) get Appearance, Style variations on their own
 * site: their site's own styles (Style Mermaid, import, export, delete; "Save styles as a variation" in the Site
 * Editor), and their site's switches (Style Mermaid on public pages, light and dark). Their styles stay on their site;
 * a network administrator can copy one into the shared library. When turned off again, their styles stay and keep
 * showing in their Site Editor; only the screen goes.
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Network settings.
 */
final class Network {

	public const OPTION = 'windrose_livery_network';

	/**
	 * Hooks the route.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Whether site administrators may use Livery on their own site (networks only).
	 *
	 * @return bool
	 */
	public static function site_admins(): bool {
		if ( ! is_multisite() ) {
			return false;
		}
		$value = get_site_option( self::OPTION, array() );
		return is_array( $value ) && ! empty( $value['site_admins'] );
	}

	/**
	 * Route `/network` (GET, POST), network administrators only.
	 *
	 * @return void
	 */
	public static function routes(): void {
		if ( ! is_multisite() ) {
			return;
		}
		$permission = static fn(): bool => current_user_can( 'manage_network_themes' );
		register_rest_route(
			Rest::NAMESPACE,
			'/network',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'set' ),
					'permission_callback' => $permission,
					'args'                => array(
						'site_admins' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * GET /network.
	 *
	 * @return WP_REST_Response
	 */
	public static function get(): WP_REST_Response {
		return new WP_REST_Response( array( 'siteAdmins' => self::site_admins() ) );
	}

	/**
	 * POST /network.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public static function set( WP_REST_Request $request ): WP_REST_Response {
		update_site_option( self::OPTION, array( 'site_admins' => (bool) $request->get_param( 'site_admins' ) ) );
		return self::get();
	}
}
