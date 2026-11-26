<?php
/**
 * Style Mermaid: makes a style variation for Ipsum from fonts, colors, typography, layout and buttons, with a preview.
 *
 * Two places, one app (`src/mermaid`):
 *
 * - Livery's screen, "Create a style" (library managers): add to the library, or download. On a site's own screen
 *   (where the network allows it): add to that site's styles, and use it there at once if wanted.
 * - The block `windrose/style-mermaid` on a public page: download only, where the site's switch is on (off by
 *   default). No account, no cookie, nothing stored about the visitor; downloads, previews and font requests are
 *   limited per visitor by `Rate_Limit` (a keyed hash of the address, kept one hour). Fonts from Google Fonts or Bunny
 *   Fonts need the site's second switch, which only network administrators set. Since 0.4 managers no longer get
 *   fonts from those services here: they install them in the Site Editor's Font Library, which Style Mermaid offers.
 *
 * Settings per site: option `windrose_livery_mermaid` = `{ public: bool, remote: bool }`.
 *
 * | Route (`windrose-livery/v1`) | Method   | Who                                   | Does                                   |
 * |------------------------------|----------|---------------------------------------|----------------------------------------|
 * | `/mermaid/catalog`           | GET      | managers; anyone where public is on    | Fonts, ready styles, palettes, scripts |
 * | `/mermaid/preview`           | POST     | same                                  | Stylesheet, font faces, contrast       |
 * | `/mermaid/font`              | POST     | anyone where both are on               | Gets a font from Google or Bunny       |
 * | `/mermaid/save`              | POST     | managers                              | Adds to the library (dry run first);   |
 * |                              |          |                                       | `apply` uses it on the scope's site    |
 * | `/mermaid/settings`          | GET/POST | managers (a site's own: that site)    | The switches of each site, with light  |
 * |                              |          |                                       | and dark (`switch`, `follow`)          |
 * | `/mermaid/packs`             | GET      | managers                              | Font packs                             |
 * | `/mermaid/packs`             | POST     | network managers                      | Uploads a pack (`file`)                |
 * | `/mermaid/packs/<script>`    | POST/DEL | network managers                      | Install from GitHub; remove            |
 *
 * Download: `admin-post.php?action=windrose_livery_mermaid` (POST `recipe`), a zip in Livery's package shape. For
 * visitors it is refused while a contrast check fails.
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
 * Style Mermaid.
 */
final class Mermaid {

	public const OPTION    = 'windrose_livery_mermaid';
	public const ACTION    = 'windrose_livery_mermaid';
	public const BLOCK     = 'windrose/style-mermaid';
	public const HANDLE    = 'windrose-livery-mermaid';
	public const LIMITS    = array(
		'download' => 20,
		'font'     => 20,
		'preview'  => 600,
	);
	public const MAX_SITES = 500;

	/**
	 * Hooks everything.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'download' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'download' ) );
	}

	/**
	 * The switches of a site.
	 *
	 * @param int $site Site (0: the current one).
	 * @return array{public: bool, remote: bool}
	 */
	public static function settings( int $site = 0 ): array {
		$value = is_multisite() && $site > 0 && get_current_blog_id() !== $site ? get_blog_option( $site, self::OPTION, array() ) : get_option( self::OPTION, array() );
		$value = is_array( $value ) ? $value : array();
		return array(
			'public' => ! empty( $value['public'] ),
			'remote' => ! empty( $value['public'] ) && ! empty( $value['remote'] ),
		);
	}

	/**
	 * Whether the current person may use Style Mermaid here.
	 *
	 * @return bool
	 */
	public static function can_use(): bool {
		return Library::can_manage() || self::settings()['public'];
	}

	/**
	 * Whether the current person may get fonts from Google Fonts or Bunny Fonts here: where the site allows it for
	 * its public Style Mermaid (managers install fonts in the Site Editor's Font Library instead).
	 *
	 * @return bool
	 */
	public static function can_fetch(): bool {
		return self::settings()['remote'];
	}

	/**
	 * Registers the block and the app's scripts.
	 *
	 * @return void
	 */
	public static function register_block(): void {
		foreach ( array(
			self::HANDLE            => 'mermaid',
			self::HANDLE . '-block' => 'mermaid-block',
		) as $handle => $name ) {
			$asset_file = WINDROSE_LIVERY_DIR . '/build/' . $name . '.asset.php';
			if ( ! is_readable( $asset_file ) ) {
				continue;
			}
			$asset = require $asset_file;
			$deps  = is_array( $asset ) && is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array();
			$ver   = is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : WINDROSE_LIVERY_VERSION;
			$src   = plugins_url( 'build/' . $name . '.js', WINDROSE_LIVERY_FILE );
			if ( '' === $src ) {
				continue;
			}
			wp_register_script( $handle, $src, $deps, $ver, array( 'in_footer' => true ) );
			wp_set_script_translations( $handle, 'windrose-livery', WINDROSE_LIVERY_DIR . '/languages' );
			if ( is_readable( WINDROSE_LIVERY_DIR . '/build/' . $name . '.css' ) ) {
				wp_register_style( $handle, plugins_url( 'build/' . $name . '.css', WINDROSE_LIVERY_FILE ), self::HANDLE === $handle ? array( 'wp-components' ) : array(), $ver );
				wp_style_add_data( $handle, 'rtl', 'replace' );
			}
		}
		register_block_type(
			WINDROSE_LIVERY_DIR . '/blocks/style-mermaid',
			array( 'render_callback' => array( __CLASS__, 'render' ) )
		);
		if ( wp_script_is( self::HANDLE . '-block', 'registered' ) ) {
			wp_add_inline_script(
				self::HANDLE . '-block',
				'window.windroseLiveryMermaidBlock = ' . wp_json_encode(
					array(
						'public'  => self::settings()['public'],
						'screen'  => Admin::url(),
						'manager' => Library::can_manage(),
					)
				) . ';',
				'before'
			);
		}
	}

	/**
	 * What the app needs (both places).
	 *
	 * @param string $mode admin or public.
	 * @return array<string, mixed>
	 */
	public static function config( string $mode ): array {
		return array(
			'mode'           => $mode,
			'root'           => '/' . Rest::NAMESPACE,
			'download'       => admin_url( 'admin-post.php?action=' . self::ACTION ),
			// Managers install fonts in the Font Library instead: never the fetch form on Livery's screen.
			'remote'         => 'admin' === $mode ? false : self::can_fetch(),
			'ipsum'          => wp_get_theme( Builder::THEME )->exists(),
			'blockStyles'    => includes_url( 'css/dist/block-library/style.min.css' ),
			'manager'        => Library::can_manage(),
			'network'        => Rest::can_manage_network(),
			'scope'          => Library::scope(),
			'fontsUrl'       => 'admin' === $mode ? admin_url( 'site-editor.php?p=%2Fstyles&section=%2Ftypography' ) : '',
			// A pattern library of this site (0.7, Windrose Knotboard): "See the patterns in this style".
			'patternLibrary' => self::pattern_library(),
			// The pattern browser (0.6): this site (on Livery's screen, the screen's list of sites is used instead).
			'patterns'       => array(
				'nonce' => 'admin' === $mode && Patterns::manages() ? wp_create_nonce( Patterns::NONCE ) : '',
				'site'  => array(
					'id'         => get_current_blog_id(),
					'name'       => (string) get_bloginfo( 'name' ),
					'url'        => home_url( '/' ),
					'stylesheet' => get_stylesheet(),
					'template'   => get_template(),
					'render'     => Patterns::render_url(),
					'rest'       => get_rest_url( null, Rest::NAMESPACE . '/patterns' ),
				),
			),
		);
	}

	/**
	 * The address of this site's pattern library, where another plugin offers one (filter
	 * `windrose_livery_pattern_library`, Windrose Knotboard): Style Mermaid then offers "See the patterns in this style",
	 * which keeps the style being made in the visitor's browser and opens the library. '' when there is none.
	 *
	 * @return string
	 */
	public static function pattern_library(): string {
		$url = apply_filters( 'windrose_livery_pattern_library', '' );
		return is_string( $url ) && '' !== $url ? esc_url_raw( $url ) : '';
	}

	/**
	 * The block on a page: the app, where the site's switch is on.
	 *
	 * @return string
	 */
	public static function render(): string {
		if ( ! self::settings()['public'] ) {
			return '';
		}
		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_script( self::HANDLE, 'window.windroseLiveryMermaid = ' . wp_json_encode( self::config( 'public' ) ) . ';', 'before' );
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes( array( 'class' => 'windrose-livery-mermaid-block' ) ) : 'class="windrose-livery-mermaid-block"';
		return sprintf(
			'<div %1$s><div class="windrose-livery-mermaid-root" data-windrose-livery-mermaid><p>%2$s</p></div><noscript><p>%3$s</p></noscript></div>',
			$wrapper,
			esc_html__( 'Loading Style Mermaid…', 'windrose-livery' ),
			esc_html__( 'Style Mermaid needs JavaScript.', 'windrose-livery' )
		);
	}

	/**
	 * REST routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		$recipe = array(
			'recipe' => array(
				'type'     => 'string',
				'required' => true,
			),
		);
		register_rest_route(
			Rest::NAMESPACE,
			'/mermaid/catalog',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'catalog' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
			)
		);
		register_rest_route(
			Rest::NAMESPACE,
			'/mermaid/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'preview' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
				'args'                => $recipe,
			)
		);
		register_rest_route(
			Rest::NAMESPACE,
			'/mermaid/font',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'font' ),
				'permission_callback' => array( __CLASS__, 'can_fetch' ),
				'args'                => array(
					'provider' => array(
						'type'    => 'string',
						'enum'    => array( 'google', 'bunny' ),
						'default' => 'bunny',
					),
					'family'   => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 2000,
					),
					'weights'  => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'default'  => array( 400, 700 ),
						'maxItems' => 9,
					),
					'italic'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'script'   => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
		register_rest_route(
			Rest::NAMESPACE,
			'/mermaid/save',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'save' ),
				'permission_callback' => array( Rest::class, 'can_manage' ),
				'args'                => $recipe + array(
					'dry_run' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'replace' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'apply'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			Rest::NAMESPACE,
			'/mermaid/packs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_packs' ),
					'permission_callback' => array( Rest::class, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'upload_pack' ),
					'permission_callback' => array( Rest::class, 'can_manage_network' ),
				),
			)
		);
		register_rest_route(
			Rest::NAMESPACE,
			'/mermaid/packs/(?P<script>[a-z-]{2,40})',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'install_pack' ),
					'permission_callback' => array( Rest::class, 'can_manage_network' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'remove_pack' ),
					'permission_callback' => array( Rest::class, 'can_manage_network' ),
				),
			)
		);
		register_rest_route(
			Rest::NAMESPACE,
			'/mermaid/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => array( Rest::class, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'set_settings' ),
					'permission_callback' => array( Rest::class, 'can_manage' ),
					'args'                => array(
						'site'   => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'public' => array( 'type' => 'boolean' ),
						'remote' => array( 'type' => 'boolean' ),
						'switch' => array( 'type' => 'boolean' ),
						'follow' => array( 'type' => 'boolean' ),
					),
				),
			)
		);
	}

	/**
	 * A 429 answer when a visitor reached the limit of an action (managers are not limited).
	 *
	 * @param string $action Action.
	 * @return WP_Error|null
	 */
	private static function limited( string $action ): ?WP_Error {
		if ( Library::can_manage() || Rate_Limit::hit( $action, self::LIMITS[ $action ] ) ) {
			return null;
		}
		return new WP_Error(
			'windrose_livery_limit',
			__( 'Too many requests from your connection in the last hour. Please try again later.', 'windrose-livery' ),
			array(
				'status'      => 429,
				'retry_after' => Rate_Limit::retry_after( $action ),
			)
		);
	}

	/**
	 * GET /mermaid/catalog.
	 *
	 * @return WP_REST_Response
	 */
	public static function catalog(): WP_REST_Response {
		$response = new WP_REST_Response( Catalog::for_browser() + array( 'remote' => self::can_fetch() ) );
		// Fresh each time: installing a font pack changes it.
		$response->header( 'Cache-Control', 'private, no-cache' );
		return $response;
	}

	/**
	 * POST /mermaid/preview.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function preview( WP_REST_Request $request ) {
		$limit = self::limited( 'preview' );
		if ( null !== $limit ) {
			return $limit;
		}
		$recipe = Builder::parse( (string) $request->get_param( 'recipe' ) );
		if ( is_wp_error( $recipe ) ) {
			return $recipe;
		}
		$preview = Builder::preview( $recipe );
		return is_wp_error( $preview ) ? $preview : new WP_REST_Response( $preview );
	}

	/**
	 * POST /mermaid/font: a family from Google Fonts or Bunny Fonts, downloaded once by this server.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function font( WP_REST_Request $request ) {
		$what = Remote_Fonts::understand( (string) $request->get_param( 'family' ), (string) $request->get_param( 'provider' ) );
		if ( is_wp_error( $what ) ) {
			return $what;
		}
		$script  = (string) $request->get_param( 'script' );
		$subsets = Remote_Fonts::LATIN;
		if ( '' !== $script ) {
			$info = Catalog::script( $script );
			if ( null === $info || ! empty( $info['system'] ) ) {
				return new WP_Error( 'windrose_livery_script', __( 'Unknown script.', 'windrose-livery' ), array( 'status' => 400 ) );
			}
			$subsets = $info['subsets'] ?? array();
		}
		$weights = $request->get_param( 'weights' );
		$weights = is_array( $weights ) ? array_values( array_map( 'intval', $weights ) ) : array();
		$italic  = '' === $script && (bool) $request->get_param( 'italic' );

		// Only a family not cached yet costs requests to the service, and counts for the visitor's limit.
		if ( null === Remote_Fonts::get( Remote_Fonts::key( $what['provider'], $what['family'], $weights, $italic, $subsets ) ) ) {
			$limit = self::limited( 'font' );
			if ( null !== $limit ) {
				return $limit;
			}
		}
		$entry = Remote_Fonts::fetch( $what['provider'], $what['family'], $weights, $italic, $subsets );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		$faces = array();
		foreach ( $entry['faces'] as $face ) {
			$faces[] = array(
				'fontFamily'   => $entry['name'],
				'fontStyle'    => $face['fontStyle'],
				'fontWeight'   => $face['fontWeight'],
				'unicodeRange' => $face['unicodeRange'],
				'src'          => array( Remote_Fonts::url( $face['stored'] ) ),
			);
		}
		return new WP_REST_Response(
			array(
				'key'      => $entry['key'],
				'provider' => $entry['provider'],
				'name'     => $entry['name'],
				'slug'     => '' === $script ? Builder::remote_slug( $entry ) : $entry['slug'],
				'subsets'  => $entry['subsets'],
				'weights'  => $entry['weights'],
				'italic'   => $entry['italic'],
				'files'    => count( $entry['faces'] ),
				'bytes'    => $entry['bytes'],
				'covers'   => array_values( array_intersect( array( 'cyrillic', 'greek' ), $entry['subsets'] ) ),
				'css'      => Fonts::css( array( array( 'fontFace' => $faces ) ) ),
			)
		);
	}

	/**
	 * POST /mermaid/save: dry run (preview of what would be added) or add to the library.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save( WP_REST_Request $request ) {
		$recipe = Builder::parse( (string) $request->get_param( 'recipe' ) );
		if ( is_wp_error( $recipe ) ) {
			return $recipe;
		}
		if ( '' === trim( $recipe['title'] ) ) {
			return new WP_Error( 'windrose_livery_title', __( 'The style variation needs a title.', 'windrose-livery' ), array( 'status' => 400 ) );
		}
		if ( is_wp_error( Builder::ipsum_data() ) ) {
			return Builder::ipsum_data();
		}
		$built = Builder::clean( $recipe );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		$dry    = (bool) $request->get_param( 'dry_run' );
		$result = Importer::add_built(
			$built['clean'],
			$built['pending'],
			array(
				'dry_run' => $dry,
				'replace' => (bool) $request->get_param( 'replace' ),
			)
		);
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
				$result->add_data( array( 'status' => 400 ) );
			}
			return $result;
		}
		$extra = array(
			'checks'     => $built['checks'],
			'twinChecks' => $built['twinChecks'],
		);
		// "Add and use on this site": a site's own style, used on that site at once (rights checked there).
		if ( ! $dry && (bool) $request->get_param( 'apply' ) && Library::scope() > 0 && is_int( $result['id'] ?? null ) ) {
			$item = Library::get( $result['id'] );
			if ( null !== $item ) {
				$extra['applied'] = Applier::apply( $item, array( Library::scope() ) );
			}
		}
		return new WP_REST_Response( $result + $extra );
	}

	/**
	 * GET /mermaid/packs.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_packs(): WP_REST_Response {
		return new WP_REST_Response( Packs::for_browser() );
	}

	/**
	 * POST /mermaid/packs/<script>: installs a pack from this version's GitHub release (the manager's click).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function install_pack( WP_REST_Request $request ) {
		$result = Packs::install_from_github( (string) $request->get_param( 'script' ) );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( Packs::for_browser() );
	}

	/**
	 * POST /mermaid/packs with `file`: installs an uploaded pack.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload_pack( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = is_array( $files['file'] ?? null ) ? $files['file'] : array();
		$tmp   = is_string( $file['tmp_name'] ?? null ) ? $file['tmp_name'] : '';
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'windrose_livery_upload', __( 'Choose a font pack (.zip) to upload.', 'windrose-livery' ), array( 'status' => 400 ) );
		}
		$result = Packs::install_file( $tmp );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( Packs::for_browser() );
	}

	/**
	 * DELETE /mermaid/packs/<script>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function remove_pack( WP_REST_Request $request ) {
		if ( ! Packs::remove( (string) $request->get_param( 'script' ) ) ) {
			return new WP_Error( 'windrose_livery_pack_missing', __( 'This font pack is not installed.', 'windrose-livery' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( Packs::for_browser() );
	}

	/**
	 * GET /mermaid/settings: the switches of each site (on a site's own screen, that site; the site itself on a
	 * single installation), with light and dark and whether the site's style has twins.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_settings(): WP_REST_Response {
		if ( Library::scope() > 0 ) {
			$sites = array( Library::scope() );
		} elseif ( is_multisite() ) {
			$sites = get_sites(
				array(
					'network_id' => get_current_network_id(),
					'number'     => self::MAX_SITES,
					'fields'     => 'ids',
					'deleted'    => 0,
					'archived'   => 0,
				)
			);
		} else {
			$sites = array( get_current_blog_id() );
		}
		$out = array();
		foreach ( $sites as $site ) {
			$out[] = self::site_row( (int) $site );
		}
		return new WP_REST_Response( $out );
	}

	/**
	 * One site's switches.
	 *
	 * @param int $site Site.
	 * @return array<string, mixed>
	 */
	private static function site_row( int $site ): array {
		$twins = Library::on_site(
			$site,
			static function (): string {
				\WP_Theme_JSON_Resolver::clean_cached_data();
				$scheme = Schemes::current()['scheme'];
				\WP_Theme_JSON_Resolver::clean_cached_data();
				return $scheme;
			}
		);
		return array(
			'id'           => $site,
			'name'         => is_multisite() ? (string) get_blog_option( $site, 'blogname' ) : get_bloginfo( 'name' ),
			'url'          => is_multisite() ? get_home_url( $site, '/' ) : home_url( '/' ),
			'twins'        => $twins,
			'remoteLocked' => ! Rest::can_manage_network(),
		) + self::settings( $site ) + Schemes::settings( $site );
	}

	/**
	 * POST /mermaid/settings: changes the switches given; the others stay. Fonts from Google Fonts and Bunny Fonts for
	 * visitors only by network administrators (the network's server fetches them).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function set_settings( WP_REST_Request $request ) {
		$site = is_multisite() ? (int) $request->get_param( 'site' ) : get_current_blog_id();
		if ( is_multisite() ) {
			$found = get_site( $site );
			if ( null === $found || get_current_network_id() !== (int) $found->network_id ) {
				return new WP_Error( 'windrose_livery_site', __( 'This site does not exist.', 'windrose-livery' ), array( 'status' => 404 ) );
			}
			if ( Library::scope() > 0 && Library::scope() !== $site ) {
				return new WP_Error( 'windrose_livery_site', __( 'You may change this site’s switches only.', 'windrose-livery' ), array( 'status' => 403 ) );
			}
		}
		$old    = self::settings( $site );
		$public = null === $request->get_param( 'public' ) ? $old['public'] : (bool) $request->get_param( 'public' );
		$remote = null === $request->get_param( 'remote' ) || ! Rest::can_manage_network() ? $old['remote'] : (bool) $request->get_param( 'remote' );
		$value  = array(
			'public' => $public,
			'remote' => $public && $remote,
		);
		if ( is_multisite() ) {
			update_blog_option( $site, self::OPTION, $value );
		} else {
			update_option( self::OPTION, $value );
		}
		$scheme = Schemes::settings( $site );
		$on     = null === $request->get_param( 'switch' ) ? $scheme['switch'] : (bool) $request->get_param( 'switch' );
		$follow = null === $request->get_param( 'follow' ) ? $scheme['follow'] : (bool) $request->get_param( 'follow' );
		Schemes::save_settings( $site, $on, $follow );
		return new WP_REST_Response( self::site_row( $site ) );
	}

	/**
	 * The download (admin-post.php, for visitors too): a zip of the built style. Nothing is stored.
	 *
	 * @return void
	 */
	public static function download(): void {
		// A public download that changes nothing: no nonce (visitors have none); the input is checked like an import.
		$json   = isset( $_POST['recipe'] ) && is_string( $_POST['recipe'] ) ? wp_unslash( $_POST['recipe'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, decoded and checked by Builder and Sanitizer.
		$result = self::prepare_download( $json );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$data = is_array( $data ) ? $data : array();
			if ( isset( $data['retry_after'] ) ) {
				header( 'Retry-After: ' . (int) $data['retry_after'] );
			}
			$body = array( 'message' => $result->get_error_message() );
			if ( isset( $data['checks'] ) ) {
				$body['checks'] = $data['checks'];
			}
			wp_send_json_error( $body, is_int( $data['status'] ?? null ) ? $data['status'] : 400 );
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $result['slug'] . '.zip"' );
		header( 'Content-Length: ' . (string) filesize( $result['zip'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		// Deleted when PHP ends, also when the download is stopped half-way.
		register_shutdown_function( 'wp_delete_file', $result['zip'] );
		readfile( $result['zip'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams the temporary zip.
		exit;
	}

	/**
	 * Checks a download request and builds its zip.
	 *
	 * @param string $json Recipe.
	 * @return array{zip: string, slug: string}|WP_Error The temporary zip (the caller deletes it).
	 */
	public static function prepare_download( string $json ) {
		$manager = Library::can_manage();
		if ( ! $manager && ! self::settings()['public'] ) {
			return new WP_Error( 'windrose_livery_mermaid_off', __( 'Style Mermaid is not available on this site.', 'windrose-livery' ), array( 'status' => 403 ) );
		}
		$limit = self::limited( 'download' );
		if ( null !== $limit ) {
			return $limit;
		}
		$recipe = Builder::parse( $json );
		$built  = is_wp_error( $recipe ) ? $recipe : Builder::clean( $recipe );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		if ( ! $manager && ( ! Contrast::passes( $built['checks'] ) || ( null !== $built['twinChecks'] && ! Contrast::passes( $built['twinChecks'] ) ) ) ) {
			return new WP_Error(
				'windrose_livery_contrast',
				__( 'Some colors do not have enough contrast yet. Fix them, then download.', 'windrose-livery' ),
				array(
					'status'     => 422,
					'checks'     => $built['checks'],
					'twinChecks' => $built['twinChecks'],
				)
			);
		}
		$zip = Builder::zip( $built['clean'], $built['pending'] );
		if ( is_wp_error( $zip ) ) {
			$zip->add_data( array( 'status' => 500 ) );
			return $zip;
		}
		$slug = sanitize_title( $built['clean']['title'] );
		return array(
			'zip'  => $zip,
			'slug' => '' === $slug ? 'style-variation' : $slug,
		);
	}
}
