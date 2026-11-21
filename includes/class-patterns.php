<?php
/**
 * Pattern browser (0.6): the site's registered patterns with a style applied, rendered by WordPress itself.
 *
 * - `GET /windrose-livery/v1/patterns` (on the site that renders): its patterns grouped by category. Administrators
 *   get every registered pattern the inserter offers (theme, core, plugins); visitors of a public Style Mermaid only
 *   the theme's (Ipsum's) and WordPress's own, what a site has by default with a new style.
 * - `POST <home>/?windrose_livery_patterns=1`: a page of one category's patterns (or all), one below the other, each
 *   after a small label. For that one request the site's saved styles are replaced by the style
 *   (`wp_theme_json_data_user`, core's public filter) and core's block theme canvas renders core Pattern blocks, so
 *   the stylesheet, fonts and HTML are what the site would show with the style applied, byte for byte.
 *   Fields: `style` and `scope` (a stored style) or `recipe` (Style Mermaid's unsaved style), `category`, `sample`,
 *   `_wpnonce`. Nothing is saved (not even the menu the Navigation block makes on a site's first visit).
 * - Patterns of another plugin (0.7, for Windrose Knotboard): `source` (the plugin's key), `items` (its own item keys,
 *   comma-separated, in order) and `layout` (`list`: each after its label; `page`: one after the other, as a page).
 *   The plugin answers the filter `windrose_livery_pattern_items` (null, source, items, visitor) with a list of
 *   `name`, `title` and block markup `content`, only what that person may see, or a WP_Error. The filter
 *   `windrose_livery_visitor_previews` (false, source) opens visitors' previews for that source (its public library)
 *   even where the public Style Mermaid is off; everything else stays as for visitors of Style Mermaid.
 *
 * Who: with a valid nonce, people who may edit theme options on this site and use Livery (a stored style also needs
 * the right to manage its library; a site's own style renders on that site only). Anyone else is a visitor: only where
 * the site's public Style Mermaid is on (or the source's public library), only an unsaved style, always with sample
 * content (`Sample`), counted in Style Mermaid's previews per hour. The style must fit the site's theme (Style Mermaid's: Ipsum or a child theme).
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

use WP_Block_Pattern_Categories_Registry;
use WP_Block_Patterns_Registry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Theme_JSON;
use WP_Theme_JSON_Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pattern browser.
 *
 * @phpstan-type Group array{key: string, label: string, patterns: list<array{name: string, title: string}>}
 * @phpstan-type Item array{name: string, title: string, content: string}
 * @phpstan-type Context array{visitor: bool, sample: bool, data: array{settings: array<mixed>, styles: array<mixed>}, category: string, items: list<Item>|null, layout: string}
 */
final class Patterns {

	public const QUERY  = 'windrose_livery_patterns';
	public const NONCE  = 'windrose_livery_patterns';
	public const HANDLE = 'windrose-livery-patterns';

	/** Filter: another plugin's items for a render (0.7). */
	public const ITEMS_FILTER = 'windrose_livery_pattern_items';

	/** Filter: visitors' previews for a source's public library (0.7). */
	public const VISITORS_FILTER = 'windrose_livery_visitor_previews';

	/** At most this many items in one render. */
	public const MAX_ITEMS = 60;

	/**
	 * The style data in place of the site's saved styles, while rendering.
	 *
	 * @var array{settings: array<mixed>, styles: array<mixed>}|null
	 */
	private static ?array $data = null;

	/**
	 * Hooks the route, and the renderer when this request asks for it.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		// Only a flag: what it renders is checked in render() (nonce, rights, switches).
		if ( isset( $_GET[ self::QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_filter( 'show_admin_bar', '__return_false' );
			add_action( 'template_redirect', array( __CLASS__, 'render' ), 0 );
		}
	}

	/**
	 * The address that renders patterns on the current site.
	 *
	 * @return string
	 */
	public static function render_url(): string {
		return add_query_arg( self::QUERY, '1', home_url( '/' ) );
	}

	/**
	 * Registers the list route.
	 *
	 * @return void
	 */
	public static function routes(): void {
		register_rest_route(
			Rest::NAMESPACE,
			'/patterns',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list' ),
				'permission_callback' => array( __CLASS__, 'can_list' ),
			)
		);
	}

	/**
	 * Whether the current person works here as an administrator (rather than as a visitor).
	 *
	 * @return bool
	 */
	public static function manages(): bool {
		return current_user_can( 'edit_theme_options' )
			&& ( Library::with_scope( 0, static fn(): bool => Library::can_manage() ) || Library::can_manage_site( get_current_blog_id() ) );
	}

	/**
	 * Who may list this site's patterns: administrators, and anyone where the public Style Mermaid is on.
	 *
	 * @return bool
	 */
	public static function can_list(): bool {
		return self::manages() || Mermaid::settings()['public'];
	}

	/**
	 * Whether visitors may get previews: the public Style Mermaid, or a source's public library.
	 *
	 * @param string $source Source key, or '' for the site's registered patterns.
	 * @return bool
	 */
	public static function visitors( string $source = '' ): bool {
		if ( Mermaid::settings()['public'] ) {
			return true;
		}
		return '' !== $source && true === apply_filters( 'windrose_livery_visitor_previews', false, $source );
	}

	/**
	 * GET /patterns.
	 *
	 * @return WP_REST_Response
	 */
	public static function list(): WP_REST_Response {
		$visitor  = ! self::manages();
		$response = new WP_REST_Response(
			array(
				'visitor'    => $visitor,
				'stylesheet' => get_stylesheet(),
				'template'   => get_template(),
				'groups'     => self::groups( $visitor ),
			)
		);
		$response->header( 'Cache-Control', 'private, no-cache' );
		return $response;
	}

	/**
	 * The patterns offered, grouped by category label (core registers "Posts" twice: `query` and `posts`), sorted by
	 * label, uncategorized ones last. Patterns hidden from the inserter (`inserter: false`, such as page layouts) are
	 * left out.
	 *
	 * @param bool $visitor Only the theme's and WordPress's own.
	 * @return list<Group>
	 */
	public static function groups( bool $visitor ): array {
		$labels = array();
		foreach ( WP_Block_Pattern_Categories_Registry::get_instance()->get_all_registered() as $category ) {
			if ( is_string( $category['name'] ?? null ) && is_string( $category['label'] ?? null ) ) {
				$labels[ $category['name'] ] = $category['label'];
			}
		}
		$other  = __( 'Uncategorized', 'windrose-livery' );
		$groups = array();
		foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			if ( ! is_string( $pattern['name'] ?? null ) || false === ( $pattern['inserter'] ?? true ) || ( $visitor && ! self::is_default( $pattern ) ) ) {
				continue;
			}
			$categories = is_array( $pattern['categories'] ?? null ) && array() !== $pattern['categories'] ? $pattern['categories'] : array( 'uncategorized' );
			foreach ( $categories as $slug ) {
				$slug                               = (string) $slug;
				$label                              = $labels[ $slug ] ?? ( 'uncategorized' === $slug ? $other : ucfirst( str_replace( array( '-', '_' ), ' ', $slug ) ) );
				$groups[ $label ]['slugs'][ $slug ] = true;
				$groups[ $label ]['patterns'][ $pattern['name'] ] = is_string( $pattern['title'] ?? null ) && '' !== $pattern['title'] ? $pattern['title'] : $pattern['name'];
			}
		}
		uksort(
			$groups,
			static function ( $a, $b ) use ( $other ): int {
				$last = ( $other === $a ) <=> ( $other === $b );
				return 0 !== $last ? $last : strnatcasecmp( (string) $a, (string) $b );
			}
		);
		$list = array();
		foreach ( $groups as $label => $group ) {
			$slugs = array_keys( $group['slugs'] );
			sort( $slugs );
			$patterns = array();
			foreach ( $group['patterns'] as $name => $title ) {
				$patterns[] = array(
					'name'  => (string) $name,
					'title' => $title,
				);
			}
			$list[] = array(
				'key'      => implode( ',', $slugs ),
				'label'    => (string) $label,
				'patterns' => $patterns,
			);
		}
		return $list;
	}

	/**
	 * Whether a pattern is one every site with the style has: WordPress's own, or a file of the parent theme
	 * (Ipsum for Style Mermaid's styles).
	 *
	 * @param array<string, mixed> $pattern Registered pattern.
	 * @return bool
	 */
	public static function is_default( array $pattern ): bool {
		if ( 'core' === ( $pattern['source'] ?? '' ) ) {
			return true;
		}
		$file = is_string( $pattern['filePath'] ?? null ) ? wp_normalize_path( $pattern['filePath'] ) : '';
		return '' !== $file && str_starts_with( $file, trailingslashit( wp_normalize_path( get_template_directory() ) ) );
	}

	/**
	 * Renders the page of patterns (template_redirect), or answers with a JSON error.
	 *
	 * @return void
	 */
	public static function render(): void {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'post' !== $method ) {
			self::fail( new WP_Error( 'windrose_livery_method', __( 'Patterns are shown from Livery’s screen or Style Mermaid.', 'windrose-livery' ), array( 'status' => 405 ) ) );
		}
		$context = self::context();
		if ( is_wp_error( $context ) ) {
			self::fail( $context );
		}
		if ( $context['visitor'] && ! Rate_Limit::hit( 'preview', Mermaid::LIMITS['preview'] ) ) {
			header( 'Retry-After: ' . (string) Rate_Limit::retry_after( 'preview' ) );
			self::fail( new WP_Error( 'windrose_livery_limit', __( 'Too many requests from your connection in the last hour. Please try again later.', 'windrose-livery' ), array( 'status' => 429 ) ) );
		}

		self::use_data( $context['data'] );
		if ( $context['sample'] ) {
			Sample::start( Schemes::current_palette() );
		}
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		// Nothing is saved: a site without a menu would otherwise get one made by the Navigation block's fallback.
		add_filter( 'wp_navigation_should_create_fallback', '__return_false' );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'page_css' ), 30 );

		global $_wp_current_template_content, $_wp_current_template_id;
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's block theme canvas renders these core globals.
		$_wp_current_template_id      = get_stylesheet() . '//windrose-livery-patterns';
		$_wp_current_template_content = null === $context['items']
			? self::markup( self::groups( $context['visitor'] ), $context['category'] )
			: self::items_markup( $context['items'], $context['layout'] );
		// phpcs:enable
		status_header( 200 );
		include ABSPATH . WPINC . '/template-canvas.php';
		exit;
	}

	/**
	 * Replaces the site's saved styles by this data for the rest of the request.
	 *
	 * @param array{settings: array<mixed>, styles: array<mixed>} $data Data (presets grouped by origin, as WordPress keeps them).
	 * @return void
	 */
	public static function use_data( array $data ): void {
		self::$data = $data;
		// First, so other filters (such as Windrose Helm's Bunny Fonts faces) see it as they see applied styles.
		add_filter( 'wp_theme_json_data_user', array( __CLASS__, 'user_data' ), -1000 );
		wp_clean_theme_json_cache();
	}

	/**
	 * Back to the site's own saved styles.
	 *
	 * @return void
	 */
	public static function stop_data(): void {
		self::$data = null;
		remove_filter( 'wp_theme_json_data_user', array( __CLASS__, 'user_data' ), -1000 );
		wp_clean_theme_json_cache();
	}

	/**
	 * The style in place of the saved styles.
	 *
	 * @param mixed $theme_json Saved styles.
	 * @return mixed
	 */
	public static function user_data( $theme_json ) {
		if ( null === self::$data ) {
			return $theme_json;
		}
		return new WP_Theme_JSON_Data(
			array(
				'version'                     => WP_Theme_JSON::LATEST_SCHEMA,
				'isGlobalStylesUserThemeJSON' => true,
				'settings'                    => self::$data['settings'],
				'styles'                      => self::$data['styles'],
			),
			'custom'
		);
	}

	/**
	 * What to render, after the checks.
	 *
	 * @return Context|WP_Error
	 */
	public static function context() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce is checked here; without it the request is a visitor's.
		$nonce    = isset( $_POST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$style    = isset( $_POST['style'] ) ? absint( $_POST['style'] ) : 0;
		$scope    = isset( $_POST['scope'] ) ? absint( $_POST['scope'] ) : 0;
		$recipe   = isset( $_POST['recipe'] ) && is_string( $_POST['recipe'] ) ? wp_unslash( $_POST['recipe'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, read and checked by Builder::parse().
		$category = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
		$sample   = ! empty( $_POST['sample'] );
		$source   = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$keys     = isset( $_POST['items'] ) && is_string( $_POST['items'] ) ? self::item_keys( sanitize_text_field( wp_unslash( $_POST['items'] ) ) ) : array();
		$layout   = isset( $_POST['layout'] ) && 'page' === $_POST['layout'] ? 'page' : 'list';
		// phpcs:enable
		$admin = '' !== $nonce && false !== wp_verify_nonce( $nonce, self::NONCE ) && self::manages();

		if ( $style > 0 ) {
			if ( ! $admin ) {
				return new WP_Error( 'windrose_livery_forbidden', __( 'You may not see this style.', 'windrose-livery' ), array( 'status' => 403 ) );
			}
			if ( $scope > 0 && get_current_blog_id() !== $scope ) {
				return new WP_Error( 'windrose_livery_site', __( 'A site’s own style shows on that site only.', 'windrose-livery' ), array( 'status' => 409 ) );
			}
			$item = Library::with_scope( $scope, static fn() => Library::can_manage() ? Library::get( $style ) : false );
			if ( false === $item ) {
				return new WP_Error( 'windrose_livery_forbidden', __( 'You may not see this style.', 'windrose-livery' ), array( 'status' => 403 ) );
			}
			if ( null === $item ) {
				return new WP_Error( 'windrose_livery_missing', __( 'This style variation no longer exists.', 'windrose-livery' ), array( 'status' => 404 ) );
			}
			if ( ! Library::fits( $item, get_stylesheet(), get_template() ) ) {
				return new WP_Error( 'windrose_livery_theme', __( 'This style was made for another theme than this site’s.', 'windrose-livery' ), array( 'status' => 409 ) );
			}
			$data = Library::with_scope( $scope, static fn(): array => Library::resolved( $item ) );
		} else {
			if ( ! $admin && ! self::visitors( $source ) ) {
				return new WP_Error( 'windrose_livery_forbidden', __( 'Style Mermaid is not available here.', 'windrose-livery' ), array( 'status' => 403 ) );
			}
			if ( Builder::THEME !== get_template() ) {
				return new WP_Error( 'windrose_livery_theme', __( 'Style Mermaid makes styles for the Ipsum theme, and this site uses another theme.', 'windrose-livery' ), array( 'status' => 409 ) );
			}
			$parsed = Builder::parse( $recipe );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}
			$built = Builder::style_data( $parsed );
			if ( is_wp_error( $built ) ) {
				return $built;
			}
			$raw  = ( new WP_Theme_JSON(
				array(
					'version'  => WP_Theme_JSON::LATEST_SCHEMA,
					'settings' => $built['settings'],
					'styles'   => $built['styles'],
				)
			) )->get_raw_data();
			$data = array(
				'settings' => Library::twins_as_custom( is_array( $raw['settings'] ?? null ) ? $raw['settings'] : array() ),
				'styles'   => is_array( $raw['styles'] ?? null ) ? $raw['styles'] : array(),
			);
		}//end if

		$items = null;
		if ( '' !== $source ) {
			$items = self::items( $source, $keys, ! $admin );
			if ( is_wp_error( $items ) ) {
				return $items;
			}
		}

		return array(
			'visitor'  => ! $admin,
			'sample'   => ! $admin || $sample,
			'data'     => $data,
			'category' => $category,
			'items'    => $items,
			'layout'   => $layout,
		);
	}

	/**
	 * Item keys from the request: letters, digits, `-`, `_`, `/` and `:` only, at most MAX_ITEMS, duplicates kept
	 * (a page may show the same pattern twice).
	 *
	 * @param string $value Comma-separated keys.
	 * @return list<string>
	 */
	public static function item_keys( string $value ): array {
		$keys = array();
		foreach ( explode( ',', $value ) as $key ) {
			$key = trim( $key );
			if ( '' !== $key && strlen( $key ) <= 200 && 1 === preg_match( '#^[A-Za-z0-9_\-/:]+$#', $key ) ) {
				$keys[] = $key;
			}
		}
		return array_slice( $keys, 0, self::MAX_ITEMS );
	}

	/**
	 * Another plugin's items for this render, checked for shape.
	 *
	 * @param string $source  Source key.
	 * @param array  $keys    Item keys, in order.
	 * @phpstan-param list<string> $keys
	 * @param bool   $visitor Whether the person is a visitor.
	 * @return list<Item>|WP_Error
	 */
	public static function items( string $source, array $keys, bool $visitor ) {
		if ( array() === $keys ) {
			return new WP_Error( 'windrose_livery_items', __( 'Choose at least one pattern.', 'windrose-livery' ), array( 'status' => 400 ) );
		}
		$answer = apply_filters( 'windrose_livery_pattern_items', null, $source, $keys, $visitor );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		if ( ! is_array( $answer ) ) {
			return new WP_Error( 'windrose_livery_source', __( 'These patterns are not available here.', 'windrose-livery' ), array( 'status' => 404 ) );
		}
		$items = array();
		foreach ( $answer as $item ) {
			if ( is_array( $item ) && is_string( $item['name'] ?? null ) && is_string( $item['title'] ?? null ) && is_string( $item['content'] ?? null ) ) {
				$items[] = array(
					'name'    => $item['name'],
					'title'   => $item['title'],
					'content' => $item['content'],
				);
			}
		}
		if ( array() === $items ) {
			return new WP_Error( 'windrose_livery_source', __( 'These patterns are not available here.', 'windrose-livery' ), array( 'status' => 404 ) );
		}
		return array_slice( $items, 0, self::MAX_ITEMS );
	}

	/**
	 * The page's block markup for another plugin's items: as a list (a label before each) or as one page.
	 *
	 * @param array  $items  Items.
	 * @phpstan-param list<Item> $items
	 * @param string $layout `list` or `page`.
	 * @return string
	 */
	public static function items_markup( array $items, string $layout ): string {
		$out = '';
		$i   = 0;
		foreach ( $items as $item ) {
			if ( 'page' !== $layout ) {
				$out .= sprintf(
					'<p class="windrose-livery-patterns__label" id="windrose-livery-pattern-%1$d" data-windrose-livery-pattern="%2$s">%3$s</p>',
					++$i,
					esc_attr( $item['name'] ),
					esc_html( $item['title'] )
				) . "\n";
			}
			$out .= $item['content'] . "\n\n";
		}
		return $out;
	}

	/**
	 * The page's block markup: for each pattern a label, then core's Pattern block, at the top level as in a template.
	 *
	 * @param array  $groups   Groups.
	 * @phpstan-param list<Group> $groups
	 * @param string $category Group key, or '' for all.
	 * @return string
	 */
	public static function markup( array $groups, string $category ): string {
		$patterns = array();
		foreach ( $groups as $group ) {
			if ( '' === $category || $group['key'] === $category ) {
				foreach ( $group['patterns'] as $pattern ) {
					$patterns[ $pattern['name'] ] = $pattern['title'];
				}
			}
		}
		if ( array() === $patterns ) {
			return '<p class="windrose-livery-patterns__label">' . esc_html__( 'No patterns in this category.', 'windrose-livery' ) . '</p>';
		}
		$out = '';
		$i   = 0;
		foreach ( $patterns as $name => $title ) {
			$out .= sprintf(
				'<p class="windrose-livery-patterns__label" id="windrose-livery-pattern-%1$d" data-windrose-livery-pattern="%2$s">%3$s</p>',
				++$i,
				esc_attr( $name ),
				esc_html( $title )
			) . "\n<!-- wp:pattern " . wp_json_encode( array( 'slug' => $name ), JSON_UNESCAPED_SLASHES ) . " /-->\n";
		}
		return $out;
	}

	/**
	 * The labels' look (kept apart from the style shown), and the other palette's rules for the preview's switch.
	 *
	 * @return void
	 */
	public static function page_css(): void {
		$css = '.wp-site-blocks>.windrose-livery-patterns__label{all:revert;display:block;box-sizing:border-box;margin:48px 0 0!important;padding:6px 12px;border-block:1px solid #dcdcde;background:#f6f7f7;color:#1e1e1e;font:600 13px/1.4 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;letter-spacing:normal;text-transform:none;text-align:start}'
			. '.wp-site-blocks>.windrose-livery-patterns__label:first-child{margin-top:0!important}';
		wp_register_style( self::HANDLE, false, array(), WINDROSE_LIVERY_VERSION );
		wp_add_inline_style( self::HANDLE, $css . Schemes::css( Schemes::current(), false ) );
		wp_enqueue_style( self::HANDLE );
	}

	/**
	 * Sends an error as JSON and stops.
	 *
	 * @param WP_Error $error Error.
	 * @return never
	 */
	private static function fail( WP_Error $error ): never {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && is_int( $data['status'] ?? null ) ? $data['status'] : 400;
		wp_send_json_error( array( 'message' => $error->get_error_message() ), $status );
	}
}
