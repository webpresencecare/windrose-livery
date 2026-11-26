<?php
/**
 * The library of stored style variations.
 *
 * Each variation is a private post of type `windrose_livery`. No custom table. Two kinds of library (a "scope"):
 *
 * - **Shared** (scope 0): on the network's main site (the site itself on a single installation), managed by network
 *   administrators; offered on every site whose theme fits.
 * - **A site's own** (scope = the site's ID, networks only): on that site, marked `_windrose_livery_own`, managed
 *   by its administrators when the network allows it (`Network::site_admins()`); offered on that site only.
 *
 * | Where                         | Holds                                                                  |
 * |-------------------------------|------------------------------------------------------------------------|
 * | `post_title`, `post_name`     | Title and slug                                                         |
 * | `_windrose_livery_data`       | JSON `{settings, styles}` in theme.json shape; font sources written as |
 * |                               | `windrose-livery:<file>` (a file in the library's fonts folder)        |
 * | `_windrose_livery_theme`      | Stylesheet of the theme it was made for; shown on it and its children  |
 * | `_windrose_livery_version`    | 1, then one more each time it is replaced                              |
 * | `_windrose_livery_files`      | Its font and license files: stored name, original name, family, kind   |
 * | `_windrose_livery_own`        | 1 for a site's own style (scope = that site)                            |
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stored variations: read, save, delete.
 *
 * @phpstan-type FileEntry array{stored: string, name: string, family: string, kind: string}
 * @phpstan-type Data array{settings: array<string, mixed>, styles: array<string, mixed>}
 * @phpstan-type Variation array{id: int, scope: int, title: string, slug: string, description: string, theme: string, version: int, data: Data, files: list<FileEntry>, modified: string}
 */
final class Library {

	public const POST_TYPE    = 'windrose_livery';
	public const META_DATA    = '_windrose_livery_data';
	public const META_THEME   = '_windrose_livery_theme';
	public const META_VERSION = '_windrose_livery_version';
	public const META_FILES   = '_windrose_livery_files';
	public const META_OWN     = '_windrose_livery_own';
	public const TITLE_MAX    = 80;
	public const MAX_ITEMS    = 200;

	/**
	 * The library in use: 0 shared, or a site's ID (its own styles).
	 *
	 * @var int
	 */
	private static int $scope = 0;

	/**
	 * Variations read in this request, by scope.
	 *
	 * @var array<int, list<Variation>>
	 */
	private static array $cache = array();

	/**
	 * Resolved data by variation and version, in this request.
	 *
	 * @var array<string, Data>
	 */
	private static array $resolved = array();

	/**
	 * Hooks the post type.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Registers the private post type (no screen, no REST, no export: Livery's own screen manages it).
	 *
	 * @return void
	 */
	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Style variations', 'windrose-livery' ),
					'singular_name' => __( 'Style variation', 'windrose-livery' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'show_in_nav_menus'   => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'query_var'           => false,
				'rewrite'             => false,
				'can_export'          => false,
				'supports'            => array( 'title' ),
			)
		);
	}

	/**
	 * The library in use: 0 for the shared one, or a site's ID for that site's own styles.
	 *
	 * @return int
	 */
	public static function scope(): int {
		return self::$scope;
	}

	/**
	 * Whether a scope exists: 0, or a site of this network (networks only).
	 *
	 * @param int $scope Scope.
	 * @return bool
	 */
	public static function valid_scope( int $scope ): bool {
		if ( 0 === $scope ) {
			return true;
		}
		if ( ! is_multisite() || $scope < 0 ) {
			return false;
		}
		$site = get_site( $scope );
		return null !== $site && get_current_network_id() === (int) $site->network_id && ! (int) $site->deleted;
	}

	/**
	 * Uses a scope until changed again (REST requests: from their `scope` parameter).
	 *
	 * @param int $scope Scope (an invalid one becomes 0).
	 * @return void
	 */
	public static function use_scope( int $scope ): void {
		self::$scope = self::valid_scope( $scope ) ? $scope : 0;
	}

	/**
	 * Runs a callback with another scope.
	 *
	 * @template T
	 * @param int           $scope    Scope.
	 * @param callable(): T $callback Callback.
	 * @return T
	 */
	public static function with_scope( int $scope, callable $callback ) {
		$old = self::$scope;
		self::use_scope( $scope );
		try {
			return $callback();
		} finally {
			self::$scope = $old;
		}
	}

	/**
	 * The library site of the scope in use: the main site of the network (the site itself on a single installation)
	 * for the shared library, or the site whose own styles are in use.
	 *
	 * @return int
	 */
	public static function site_id(): int {
		return self::$scope > 0 ? self::$scope : self::network_site_id();
	}

	/**
	 * The site that holds what the whole network shares (shared library, font packs, Style Mermaid's font cache).
	 *
	 * @return int
	 */
	public static function network_site_id(): int {
		return is_multisite() ? get_main_site_id() : get_current_blog_id();
	}

	/**
	 * Whether the current user manages the library in use. Shared: network administrators (`manage_network_themes`)
	 * on a network, administrators (`edit_theme_options`) on a single site. A site's own: network administrators, and
	 * that site's administrators (`edit_theme_options` on it) when the network allows it.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		if ( self::$scope > 0 ) {
			return self::can_manage_site( self::$scope );
		}
		return is_multisite() ? current_user_can( 'manage_network_themes' ) : current_user_can( 'edit_theme_options' );
	}

	/**
	 * Whether the current user manages a site's own styles.
	 *
	 * @param int $site Site.
	 * @return bool
	 */
	public static function can_manage_site( int $site ): bool {
		if ( ! is_multisite() || ! self::valid_scope( $site ) || 0 === $site ) {
			return false;
		}
		if ( current_user_can( 'manage_network_themes' ) ) {
			return true;
		}
		return Network::site_admins() && current_user_can_for_site( $site, 'edit_theme_options' );
	}

	/**
	 * Runs a callback on the library site of the scope in use.
	 *
	 * @template T
	 * @param callable(): T $callback Callback.
	 * @return T
	 */
	public static function on_library_site( callable $callback ) {
		return self::on_site( self::site_id(), $callback );
	}

	/**
	 * Runs a callback on the network's library site (font packs, font cache), whatever the scope.
	 *
	 * @template T
	 * @param callable(): T $callback Callback.
	 * @return T
	 */
	public static function on_network_site( callable $callback ) {
		return self::on_site( self::network_site_id(), $callback );
	}

	/**
	 * Runs a callback on a site.
	 *
	 * @template T
	 * @param int           $site     Site.
	 * @param callable(): T $callback Callback.
	 * @return T
	 */
	public static function on_site( int $site, callable $callback ) {
		$switch = is_multisite() && get_current_blog_id() !== $site;
		if ( $switch ) {
			switch_to_blog( $site );
		}
		try {
			return $callback();
		} finally {
			if ( $switch ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Every stored variation of the library in use, newest first.
	 *
	 * @return list<Variation>
	 */
	public static function all(): array {
		$scope = self::$scope;
		if ( isset( self::$cache[ $scope ] ) ) {
			return self::$cache[ $scope ];
		}
		self::$cache[ $scope ] = self::read( $scope > 0 ? 'own' : ( is_multisite() ? 'shared' : 'any' ) );
		return self::$cache[ $scope ];
	}

	/**
	 * Every variation stored on the library site of the scope in use, of any scope (the main site holds the shared
	 * library and its own styles): their font files share one folder.
	 *
	 * @return list<Variation>
	 */
	public static function all_on_site(): array {
		return self::read( 'any' );
	}

	/**
	 * Reads variations on the library site.
	 *
	 * @param string $which shared (not marked own), own (marked), or any.
	 * @return list<Variation>
	 */
	private static function read( string $which ): array {
		$scope = self::$scope;
		return self::on_library_site(
			static function () use ( $which, $scope ): array {
				$args = array(
					'post_type'   => self::POST_TYPE,
					'post_status' => 'private',
					'numberposts' => self::MAX_ITEMS,
					'orderby'     => 'modified',
					'order'       => 'DESC',
				);
				if ( 'any' !== $which ) {
					// A meta query on the few posts of this type; run once per request and scope.
					$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => self::META_OWN,
							'compare' => 'own' === $which ? 'EXISTS' : 'NOT EXISTS',
						),
					);
				}
				$items = array();
				foreach ( get_posts( $args ) as $post ) {
					if ( $post instanceof WP_Post ) {
						$own     = '' !== (string) get_post_meta( $post->ID, self::META_OWN, true );
						$items[] = self::from_post( $post, $own ? ( $scope > 0 ? $scope : get_current_blog_id() ) : 0 );
					}
				}
				return $items;
			}
		);
	}

	/**
	 * The variations a site is offered: the shared library's, then the site's own (networks only).
	 *
	 * @param int    $site       Site.
	 * @param string $stylesheet Its stylesheet.
	 * @param string $template   Its parent.
	 * @return list<Variation>
	 */
	public static function offered( int $site, string $stylesheet, string $template ): array {
		$items = self::with_scope( 0, static fn(): array => self::for_theme( $stylesheet, $template ) );
		if ( is_multisite() ) {
			$items = array_merge( $items, self::with_scope( $site, static fn(): array => self::for_theme( $stylesheet, $template ) ) );
		}
		return $items;
	}

	/**
	 * One stored variation.
	 *
	 * @param int $id Post ID on the library site.
	 * @return Variation|null
	 */
	public static function get( int $id ): ?array {
		foreach ( self::all() as $item ) {
			if ( $item['id'] === $id ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * The stored variation with this title slug made for this theme (post slugs get a number when two themes share
	 * a title, so the title is compared).
	 *
	 * @param string $slug  `sanitize_title()` of the title.
	 * @param string $theme Stylesheet.
	 * @return Variation|null
	 */
	public static function find( string $slug, string $theme ): ?array {
		foreach ( self::all() as $item ) {
			if ( sanitize_title( $item['title'] ) === $slug && $item['theme'] === $theme ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Variations that fit a theme: made for this stylesheet, or for its parent.
	 *
	 * @param string $stylesheet Active stylesheet.
	 * @param string $template   Its parent (equal to the stylesheet when it has none).
	 * @return list<Variation>
	 */
	public static function for_theme( string $stylesheet, string $template ): array {
		$items = array();
		foreach ( self::all() as $item ) {
			if ( self::fits( $item, $stylesheet, $template ) ) {
				$items[] = $item;
			}
		}
		return $items;
	}

	/**
	 * Whether a variation fits a theme.
	 *
	 * @param array  $item       Variation.
	 * @phpstan-param Variation $item
	 * @param string $stylesheet Stylesheet.
	 * @param string $template   Its parent.
	 * @return bool
	 */
	public static function fits( array $item, string $stylesheet, string $template ): bool {
		return '' !== $item['theme'] && ( $item['theme'] === $stylesheet || $item['theme'] === $template );
	}

	/**
	 * Saves a variation: a new one, or a new version of `$replace`.
	 *
	 * @param array{title: string, description: string, theme: string, data: Data, files: list<FileEntry>} $variation Clean variation.
	 * @param int                                                                                          $replace   ID to replace, or 0.
	 * @return int|WP_Error Post ID.
	 */
	public static function save( array $variation, int $replace = 0 ) {
		$result = self::on_library_site(
			static function () use ( $variation, $replace ) {
				$own = '' !== (string) get_post_meta( $replace, self::META_OWN, true );
				if ( $replace > 0 && ( self::$scope > 0 ) !== $own ) {
					return new WP_Error( 'windrose_livery_not_found', __( 'This style variation no longer exists.', 'windrose-livery' ), array( 'status' => 404 ) );
				}
				$version = 1;
				if ( $replace > 0 ) {
					$old = get_post( $replace );
					if ( ! $old instanceof WP_Post || self::POST_TYPE !== $old->post_type ) {
						return new WP_Error( 'windrose_livery_not_found', __( 'This style variation no longer exists.', 'windrose-livery' ), array( 'status' => 404 ) );
					}
					$version = (int) get_post_meta( $replace, self::META_VERSION, true ) + 1;
				}
				$postarr = array(
					'post_type'    => self::POST_TYPE,
					'post_status'  => 'private',
					'post_title'   => $variation['title'],
					'post_name'    => sanitize_title( $variation['title'] ),
					'post_excerpt' => $variation['description'],
					'post_content' => '',
				);
				if ( $replace > 0 ) {
					$postarr['ID'] = $replace;
					$id            = wp_update_post( wp_slash( $postarr ), true );
				} else {
					$id = wp_insert_post( wp_slash( $postarr ), true );
				}
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				update_post_meta( $id, self::META_DATA, wp_slash( self::encode( $variation['data'] ) ) );
				update_post_meta( $id, self::META_THEME, $variation['theme'] );
				update_post_meta( $id, self::META_VERSION, $version );
				update_post_meta( $id, self::META_FILES, wp_slash( (string) wp_json_encode( $variation['files'] ) ) );
				if ( self::$scope > 0 ) {
					update_post_meta( $id, self::META_OWN, 1 );
				}
				return $id;
			}
		);
		self::flush();
		return $result;
	}

	/**
	 * Deletes a variation record (its font files are handled by Fonts::delete_unused()).
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$done = self::on_library_site(
			static function () use ( $id ): bool {
				$post = get_post( $id );
				if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
					return false;
				}
				if ( ( '' !== (string) get_post_meta( $id, self::META_OWN, true ) ) !== ( self::$scope > 0 ) ) {
					return false;
				}
				return (bool) wp_delete_post( $id, true );
			}
		);
		self::flush();
		return $done;
	}

	/**
	 * Forgets what was read in this request.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$cache    = array();
		self::$resolved = array();
	}

	/**
	 * A variation's data as WordPress gives a theme's variation files to the Site Editor: font sources as addresses,
	 * then through `WP_Theme_JSON`, which groups presets by origin (`"palette": { "theme": [...] }`), exactly as
	 * `WP_Theme_JSON_Resolver::get_style_variations()` does. Picking it, or applying it, saves this shape; the Site
	 * Editor's font code (7.2) expects it and fails on plain lists.
	 *
	 * @param array $item Variation.
	 * @phpstan-param Variation $item
	 * @return Data
	 */
	public static function resolved( array $item ): array {
		$key = $item['scope'] . ':' . $item['id'] . ':' . $item['version'] . ':' . $item['modified'];
		if ( isset( self::$resolved[ $key ] ) ) {
			return self::$resolved[ $key ];
		}
		$data = $item['data'];
		$fams = $data['settings']['typography']['fontFamilies'] ?? null;
		if ( is_array( $fams ) && is_array( $data['settings']['typography'] ?? null ) ) {
			// Font files live in the Font Library folder of the variation's own library site.
			$data['settings']['typography']['fontFamilies'] = self::with_scope( $item['scope'], static fn(): array => Fonts::resolve_families( $fams ) );
		}
		$raw = ( new \WP_Theme_JSON(
			array(
				'version'  => \WP_Theme_JSON::LATEST_SCHEMA,
				'settings' => $data['settings'],
				'styles'   => $data['styles'],
			)
		) )->get_raw_data();

		self::$resolved[ $key ] = array(
			'settings' => self::twins_as_custom( is_array( $raw['settings'] ?? null ) ? $raw['settings'] : array() ),
			'styles'   => is_array( $raw['styles'] ?? null ) ? $raw['styles'] : array(),
		);
		return self::$resolved[ $key ];
	}

	/**
	 * Light and dark twins (`Schemes`) under the palette's "custom" origin instead of "theme", so the Site Editor lists
	 * them apart from the style's own colours (Styles, Colors, Palette: Theme, then Custom). Core's palette panel has
	 * only its fixed groups (default, theme, custom): a group of their own ("Dark") would need core; their names say
	 * it ("Dark: Background"). Should core gain light and dark, this is the one place to undo.
	 *
	 * @param array<mixed> $settings Settings grouped by origin.
	 * @return array<mixed>
	 */
	public static function twins_as_custom( array $settings ): array {
		$theme = $settings['color']['palette']['theme'] ?? null;
		if ( ! is_array( $theme ) || ! array_is_list( $theme ) ) {
			return $settings;
		}
		$own   = array();
		$twins = array();
		foreach ( $theme as $color ) {
			$slug = is_array( $color ) && is_string( $color['slug'] ?? null ) ? $color['slug'] : '';
			if ( str_starts_with( $slug, Schemes::PREFIX ) ) {
				$twins[] = $color;
			} else {
				$own[] = $color;
			}
		}
		if ( array() === $twins ) {
			return $settings;
		}
		$custom = $settings['color']['palette']['custom'] ?? array();
		$custom = is_array( $custom ) && array_is_list( $custom ) ? $custom : array();

		$settings['color']['palette']['theme']  = $own;
		$settings['color']['palette']['custom'] = array_merge( $custom, $twins );
		return $settings;
	}

	/**
	 * Font families of resolved data (grouped by origin) or of stored data (a list), as one list.
	 *
	 * @param array<mixed> $settings Settings.
	 * @return array<mixed>
	 */
	public static function font_families( array $settings ): array {
		$families = $settings['typography']['fontFamilies'] ?? array();
		if ( ! is_array( $families ) ) {
			return array();
		}
		if ( array() === $families || array_is_list( $families ) ) {
			return $families;
		}
		$list = array();
		foreach ( $families as $origin ) {
			if ( is_array( $origin ) && array_is_list( $origin ) ) {
				$list = array_merge( $list, $origin );
			}
		}
		return $list;
	}

	/**
	 * A stable fingerprint of settings and styles (keys sorted, lists kept in order).
	 *
	 * @param array<mixed> $data Settings and styles.
	 * @return string
	 */
	public static function hash( array $data ): string {
		$pick = array(
			'settings' => is_array( $data['settings'] ?? null ) ? $data['settings'] : array(),
			'styles'   => is_array( $data['styles'] ?? null ) ? $data['styles'] : array(),
		);
		return md5( (string) wp_json_encode( self::sort_keys( $pick ) ) );
	}

	/**
	 * Sorts associative keys recursively; lists keep their order.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function sort_keys( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$value = array_map( array( __CLASS__, 'sort_keys' ), $value );
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}

	/**
	 * JSON for settings and styles (empty ones as objects).
	 *
	 * @param array<mixed> $data Data.
	 * @return string
	 */
	public static function encode( array $data ): string {
		return (string) wp_json_encode( self::objects( $data ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
	}

	/**
	 * Settings and styles with empty values as objects, as theme.json writes them.
	 *
	 * @param array<mixed> $data Data.
	 * @return array<string, mixed>
	 */
	public static function objects( array $data ): array {
		$out = array();
		foreach ( array( 'settings', 'styles' ) as $key ) {
			$out[ $key ] = is_array( $data[ $key ] ?? null ) && array() !== $data[ $key ] ? $data[ $key ] : new \stdClass();
		}
		return $out;
	}

	/**
	 * Reads a post into a variation.
	 *
	 * @param WP_Post $post  Post.
	 * @param int     $scope Its scope.
	 * @return Variation
	 */
	private static function from_post( WP_Post $post, int $scope ): array {
		$data  = json_decode( (string) get_post_meta( $post->ID, self::META_DATA, true ), true );
		$files = json_decode( (string) get_post_meta( $post->ID, self::META_FILES, true ), true );
		$list  = array();
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( is_array( $file ) && is_string( $file['stored'] ?? null ) && Fonts::valid_name( $file['stored'] ) ) {
				$list[] = array(
					'stored' => $file['stored'],
					'name'   => is_string( $file['name'] ?? null ) ? $file['name'] : $file['stored'],
					'family' => is_string( $file['family'] ?? null ) ? $file['family'] : '',
					'kind'   => 'license' === ( $file['kind'] ?? '' ) ? 'license' : 'font',
				);
			}
		}
		return array(
			'id'          => $post->ID,
			'scope'       => $scope,
			'title'       => $post->post_title,
			'slug'        => $post->post_name,
			'description' => $post->post_excerpt,
			'theme'       => (string) get_post_meta( $post->ID, self::META_THEME, true ),
			'version'     => max( 1, (int) get_post_meta( $post->ID, self::META_VERSION, true ) ),
			'data'        => array(
				'settings' => is_array( $data['settings'] ?? null ) ? $data['settings'] : array(),
				'styles'   => is_array( $data['styles'] ?? null ) ? $data['styles'] : array(),
			),
			'files'       => $list,
			'modified'    => (string) mysql2date( 'c', $post->post_modified_gmt . '+00:00', false ),
		);
	}
}
