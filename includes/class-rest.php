<?php
/**
 * REST routes of Livery's screen (`windrose-livery/v1`). Every request works on one library, named by its `scope`
 * parameter (0 or none: the shared library; a site's ID: that site's own styles) and needs the right to manage
 * it (`Library::can_manage()`); applying and saving from a site also check the right on that site. A site's own
 * library only ever applies to, and saves from, that site.
 *
 * | Route                               | Method | Does                                                          |
 * |-------------------------------------|--------|---------------------------------------------------------------|
 * | `/variations`                       | GET    | Stored variations (summaries) and the installed block themes   |
 * | `/sites`                            | GET    | Sites, their theme, the variation they use                     |
 * | `/import`                           | POST   | Upload (`file`), `dry_run` (default true), `theme`, `replace`  |
 * | `/from-site`                        | POST   | `site`, `title`, `theme`, `dry_run`, `replace`                 |
 * | `/variations/<id>/apply`            | POST   | `sites`: list of site IDs                                      |
 * | `/variations/<id>`                  | DELETE | Deletes it; font files nothing uses any more go too            |
 * | `/variations/<id>/copy`             | POST   | A site's own (scope) into the shared library: `dry_run`,       |
 * |                                     |        | `replace` (network administrators)                             |
 * | `/site-styles`                      | GET    | Every site's own styles (network administrators)               |
 *
 * Export is a download (`admin-post.php?action=windrose_livery_export`), in Admin.
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes.
 *
 * @phpstan-import-type Variation from Library
 */
final class Rest {

	public const NAMESPACE = 'windrose-livery/v1';

	/**
	 * Hooks the routes.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'pick_scope' ), 5, 3 );
		// After the callback (or the refused permission), also for internal requests (rest_do_request()), which do not
		// run rest_post_dispatch.
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'reset_scope_after' ), 99, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'reset_scope' ) );
	}

	/**
	 * Uses the library a request of this namespace names (`scope`), before its permission check.
	 *
	 * @param mixed           $result  Response so far.
	 * @param mixed           $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return mixed
	 */
	public static function pick_scope( $result, $server, $request ) {
		if ( $request instanceof WP_REST_Request && str_starts_with( $request->get_route(), '/' . self::NAMESPACE . '/' ) ) {
			$scope = $request->get_param( 'scope' );
			Library::use_scope( is_numeric( $scope ) ? (int) $scope : 0 );
		}
		return $result;
	}

	/**
	 * Back to the shared library after a request of this namespace.
	 *
	 * @param mixed           $response Response.
	 * @param mixed           $handler  Handler.
	 * @param WP_REST_Request $request  Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return mixed
	 */
	public static function reset_scope_after( $response, $handler, $request ) {
		if ( $request instanceof WP_REST_Request && str_starts_with( $request->get_route(), '/' . self::NAMESPACE . '/' ) ) {
			Library::use_scope( 0 );
		}
		return $response;
	}

	/**
	 * Back to the shared library after a request.
	 *
	 * @param mixed $response Response.
	 * @return mixed
	 */
	public static function reset_scope( $response ) {
		Library::use_scope( 0 );
		return $response;
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		$manage = array( __CLASS__, 'can_manage' );
		$id     = array(
			'id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);
		register_rest_route(
			self::NAMESPACE,
			'/variations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_variations' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/sites',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_sites' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'import' ),
				'permission_callback' => $manage,
				'args'                => array(
					'dry_run' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'theme'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'title'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'replace' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/from-site',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'from_site' ),
				'permission_callback' => array( __CLASS__, 'can_save_from_site' ),
				'args'                => array(
					'site'    => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'title'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'theme'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'dry_run' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'replace' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/variations/(?P<id>\d+)/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'apply' ),
				'permission_callback' => $manage,
				'args'                => $id + array(
					'sites' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'default'  => array(),
						'maxItems' => Applier::MAX_SITES,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/variations/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete' ),
				'permission_callback' => $manage,
				'args'                => $id,
			)
		);
		if ( is_multisite() ) {
			register_rest_route(
				self::NAMESPACE,
				'/variations/(?P<id>\d+)/copy',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'copy' ),
					'permission_callback' => static fn(): bool => Library::scope() > 0 && self::can_manage_network(),
					'args'                => $id + array(
						'dry_run' => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'replace' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				)
			);
			register_rest_route(
				self::NAMESPACE,
				'/site-styles',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'site_styles' ),
					'permission_callback' => array( __CLASS__, 'can_manage_network' ),
				)
			);
		}//end if
	}

	/**
	 * Permission: manages what the whole network shares (the shared library, font packs, every site's switches).
	 *
	 * @return bool
	 */
	public static function can_manage_network(): bool {
		return Library::with_scope( 0, static fn(): bool => Library::can_manage() );
	}

	/**
	 * Permission: manages the library.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return Library::can_manage();
	}

	/**
	 * Permission: manages the library and may edit the styles of the site (on a network).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return bool
	 */
	public static function can_save_from_site( WP_REST_Request $request ): bool {
		if ( ! Library::can_manage() ) {
			return false;
		}
		if ( ! is_multisite() ) {
			return true;
		}
		$site = (int) $request->get_param( 'site' );
		if ( Library::scope() > 0 && Library::scope() !== $site ) {
			return false;
		}
		return $site > 0 && current_user_can_for_site( $site, 'edit_theme_options' );
	}

	/**
	 * GET /variations.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_variations(): WP_REST_Response {
		$themes = array();
		foreach ( Themes::block_themes() as $stylesheet => $theme ) {
			$themes[] = array(
				'value'  => $stylesheet,
				'label'  => $theme['name'],
				'parent' => $theme['parent'],
			);
		}
		return new WP_REST_Response(
			array(
				'items'  => array_map( array( __CLASS__, 'summary' ), Library::all() ),
				'themes' => $themes,
				'canCss' => Importer::may_add_css(),
			)
		);
	}

	/**
	 * GET /sites.
	 *
	 * @return WP_REST_Response
	 */
	public static function list_sites(): WP_REST_Response {
		return new WP_REST_Response( Applier::sites( Library::scope() > 0 ? array( Library::scope() ) : null ) );
	}

	/**
	 * POST /import.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function import( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = is_array( $files['file'] ?? null ) ? $files['file'] : array();
		$tmp   = is_string( $file['tmp_name'] ?? null ) ? $file['tmp_name'] : '';
		$name  = is_string( $file['name'] ?? null ) ? $file['name'] : '';
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'windrose_livery_upload', __( 'Choose a file to import.', 'windrose-livery' ), array( 'status' => 400 ) );
		}
		$result = Importer::import(
			$tmp,
			$name,
			array(
				'dry_run' => (bool) $request->get_param( 'dry_run' ),
				'theme'   => (string) $request->get_param( 'theme' ),
				'title'   => (string) $request->get_param( 'title' ),
				'replace' => (bool) $request->get_param( 'replace' ),
			)
		);
		return self::answer( $result );
	}

	/**
	 * POST /from-site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function from_site( WP_REST_Request $request ) {
		$site   = is_multisite() ? (int) $request->get_param( 'site' ) : get_current_blog_id();
		$result = Importer::from_site(
			$site,
			array(
				'dry_run' => (bool) $request->get_param( 'dry_run' ),
				'theme'   => (string) $request->get_param( 'theme' ),
				'title'   => (string) $request->get_param( 'title' ),
				'replace' => (bool) $request->get_param( 'replace' ),
			)
		);
		return self::answer( $result );
	}

	/**
	 * POST /variations/<id>/apply.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function apply( WP_REST_Request $request ) {
		$item = Library::get( (int) $request->get_param( 'id' ) );
		if ( null === $item ) {
			return new WP_Error( 'windrose_livery_not_found', __( 'This style variation no longer exists.', 'windrose-livery' ), array( 'status' => 404 ) );
		}
		$sites = $request->get_param( 'sites' );
		$sites = is_array( $sites ) ? array_values( array_map( 'intval', $sites ) ) : array();
		if ( Library::scope() > 0 ) {
			// A site's own styles apply to that site only.
			$sites = array( Library::scope() );
		}
		if ( is_multisite() && array() === $sites ) {
			return new WP_Error( 'windrose_livery_no_sites', __( 'Choose at least one site.', 'windrose-livery' ), array( 'status' => 400 ) );
		}
		return new WP_REST_Response( array( 'results' => Applier::apply( $item, $sites ) ) );
	}

	/**
	 * DELETE /variations/<id>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete( WP_REST_Request $request ) {
		$item = Library::get( (int) $request->get_param( 'id' ) );
		if ( null === $item ) {
			return new WP_Error( 'windrose_livery_not_found', __( 'This style variation no longer exists.', 'windrose-livery' ), array( 'status' => 404 ) );
		}
		$unused = Fonts::deletable( $item['files'], Fonts::unused( array_column( $item['files'], 'stored' ), $item['id'] ) );
		$fonts  = array_column( array_filter( $item['files'], static fn( array $file ): bool => 'font' === $file['kind'] ), 'stored' );
		if ( ! Library::delete( $item['id'] ) ) {
			return new WP_Error( 'windrose_livery_delete', __( 'The style variation could not be deleted.', 'windrose-livery' ), array( 'status' => 500 ) );
		}
		$deleted = Fonts::delete( $unused );
		return new WP_REST_Response(
			array(
				'deleted'      => true,
				'fontsKept'    => count( array_diff( $fonts, $unused ) ),
				'filesDeleted' => $deleted,
			)
		);
	}

	/**
	 * POST /variations/<id>/copy: a site's own variation (the scope) into the shared library.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function copy( WP_REST_Request $request ) {
		return self::answer(
			Importer::copy_to_shared(
				Library::scope(),
				(int) $request->get_param( 'id' ),
				array(
					'dry_run' => (bool) $request->get_param( 'dry_run' ),
					'replace' => (bool) $request->get_param( 'replace' ),
				)
			)
		);
	}

	/**
	 * GET /site-styles: every site's own styles.
	 *
	 * @return WP_REST_Response
	 */
	public static function site_styles(): WP_REST_Response {
		$out = array();
		foreach ( get_sites(
			array(
				'network_id' => get_current_network_id(),
				'fields'     => 'ids',
				'number'     => Applier::MAX_SITES,
				'deleted'    => 0,
			)
		) as $site ) {
			$site  = (int) $site;
			$items = Library::with_scope( $site, static fn(): array => array_map( array( __CLASS__, 'summary' ), Library::all() ) );
			foreach ( $items as $item ) {
				$out[] = $item + array(
					'siteName' => (string) get_blog_option( $site, 'blogname' ),
					'siteUrl'  => get_home_url( $site, '/' ),
				);
			}
		}
		return new WP_REST_Response( $out );
	}

	/**
	 * A REST answer for an importer result.
	 *
	 * @param array<string, mixed>|WP_Error $result Result.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function answer( $result ) {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
				$result->add_data( array( 'status' => 400 ) );
			}
			return $result;
		}
		return new WP_REST_Response( $result );
	}

	/**
	 * What the screen shows of a stored variation.
	 *
	 * @param array $item Variation.
	 * @phpstan-param Variation $item
	 * @return array<string, mixed>
	 */
	public static function summary( array $item ): array {
		$settings = $item['data']['settings'];
		$twins    = Schemes::twins( Importer::palette( $settings ) );
		return array(
			'id'          => $item['id'],
			'scope'       => $item['scope'],
			'title'       => $item['title'],
			'description' => $item['description'],
			'theme'       => $item['theme'],
			'themeName'   => Themes::name( $item['theme'] ),
			'version'     => $item['version'],
			'modified'    => $item['modified'],
			'palette'     => array_values( array_filter( Importer::palette( $settings ), static fn( array $c ): bool => ! str_starts_with( $c['slug'], Schemes::PREFIX ) ) ),
			'fonts'       => Importer::font_names( $settings ),
			'files'       => count( array_filter( $item['files'], static fn( array $file ): bool => 'font' === $file['kind'] ) ),
			'css'         => Importer::has_css( $item['data']['styles'] ),
			'scheme'      => $twins['scheme'],
			'contrast'    => Contrast::summary( $settings, $item['data']['styles'] ),
			'hash'        => Library::hash( Library::resolved( $item ) ),
		);
	}
}
