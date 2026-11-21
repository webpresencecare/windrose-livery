<?php
/**
 * Adds variations to the library: from a package (import) or from a site's current styles (save as variation).
 *
 * Both run twice from the screen: first as a dry run (nothing written; the answer is the preview: title, theme,
 * colors, fonts, what was left out, which variation it would replace), then for real with the same input, checked
 * again from scratch.
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

use WP_Error;
use WP_Theme_JSON;
use WP_Theme_JSON_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import and save as variation.
 *
 * @phpstan-import-type Variation from Library
 * @phpstan-import-type FileEntry from Library
 * @phpstan-import-type Clean from Sanitizer
 * @phpstan-type Pending array<string, array{entry: FileEntry, bytes: string}>
 * @phpstan-type Options array{dry_run?: bool, theme?: string, replace?: bool, title?: string}
 */
final class Importer {

	public const MAX_FILES = 60;

	/**
	 * Imports a package.
	 *
	 * @param string $path     Uploaded file.
	 * @param string $filename Its original name.
	 * @param array  $options  Dry run (default true), theme (default: guessed), replace an existing one.
	 * @phpstan-param Options $options
	 * @return array<string, mixed>|WP_Error Preview, or the saved variation's summary.
	 */
	public static function import( string $path, string $filename, array $options = array() ) {
		$package = Package::open( $path, $filename );
		if ( is_wp_error( $package ) ) {
			return $package;
		}
		$pending = array();
		$mapper  = static function ( string $src, string $family ) use ( $package, &$pending ) {
			if ( 1 === preg_match( '#^([a-z][a-z0-9+.-]*:)?//#i', $src ) || str_starts_with( $src, 'data:' ) ) {
				return null;
			}
			$file = $package->file( $src );
			if ( null === $file ) {
				return null;
			}
			$name  = wp_basename( $file['name'] );
			$check = Fonts::check_font( $name, $file['bytes'] );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			if ( count( $pending ) >= self::MAX_FILES ) {
				return new WP_Error( 'windrose_livery_files', __( 'Left out: fonts beyond the first 60 files.', 'windrose-livery' ) );
			}
			$stored = Fonts::stored_name( $name, $file['bytes'] );
			self::add_pending( $pending, $stored, $name, $family, 'font', $file['bytes'] );
			foreach ( $package->licenses_near( $file['name'] ) as $license => $text ) {
				self::add_pending( $pending, Fonts::stored_name( $license, $text ), $license, $family, 'license', $text );
			}
			return Fonts::PREFIX . $stored;
		};
		$clean   = Sanitizer::clean( $package->variation, $package->fallback, self::may_add_css(), $mapper );
		$package->close();
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( isset( $options['title'] ) && '' !== trim( sanitize_text_field( $options['title'] ) ) ) {
			$clean['title'] = mb_substr( trim( sanitize_text_field( $options['title'] ) ), 0, Library::TITLE_MAX );
		}
		$theme = (string) ( $options['theme'] ?? '' );
		if ( '' === $theme ) {
			$theme = Themes::detect( $clean['data'] );
		}
		return self::finish( $clean, $pending, $theme, $options );
	}

	/**
	 * Saves a site's current styles (what its Site Editor saved for its active theme) as a variation.
	 *
	 * @param int   $site_id Site (the current site on a single installation).
	 * @param array $options Dry run (default true), title (required), theme: the site's theme or its parent
	 *                       (default the site's), replace.
	 * @phpstan-param Options $options
	 * @return array<string, mixed>|WP_Error
	 */
	public static function from_site( int $site_id, array $options = array() ) {
		$switch = is_multisite() && get_current_blog_id() !== $site_id;
		if ( is_multisite() && ! get_site( $site_id ) ) {
			return new WP_Error( 'windrose_livery_site', __( 'This site does not exist.', 'windrose-livery' ), array( 'status' => 404 ) );
		}
		if ( $switch ) {
			switch_to_blog( $site_id );
		}
		WP_Theme_JSON_Resolver::clean_cached_data();
		$stylesheet = get_stylesheet();
		$template   = get_template();
		$post       = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() );
		$site_name  = get_bloginfo( 'name' );
		WP_Theme_JSON_Resolver::clean_cached_data();
		if ( $switch ) {
			restore_current_blog();
			WP_Theme_JSON_Resolver::clean_cached_data();
		}
		$content = is_string( $post['post_content'] ?? null ) ? json_decode( $post['post_content'], true ) : null;
		if ( ! is_array( $content ) || ( array() === ( $content['settings'] ?? array() ) && array() === ( $content['styles'] ?? array() ) ) ) {
			return new WP_Error( 'windrose_livery_no_styles', __( 'This site has no saved styles for its theme yet: change something in the Site Editor, Styles, first.', 'windrose-livery' ), array( 'status' => 400 ) );
		}
		$theme = (string) ( $options['theme'] ?? '' );
		if ( '' === $theme ) {
			$theme = $stylesheet;
		}
		if ( $theme !== $stylesheet && $theme !== $template ) {
			return new WP_Error( 'windrose_livery_theme', __( 'Choose the site’s theme or its parent theme.', 'windrose-livery' ), array( 'status' => 400 ) );
		}

		$raw = array(
			'version'  => is_int( $content['version'] ?? null ) ? $content['version'] : WP_Theme_JSON::LATEST_SCHEMA,
			'title'    => (string) ( $options['title'] ?? '' ),
			'settings' => self::flatten_origins( is_array( $content['settings'] ?? null ) ? $content['settings'] : array() ),
			'styles'   => is_array( $content['styles'] ?? null ) ? $content['styles'] : array(),
		);

		$pending = array();
		$mapper  = static function ( string $src, string $family ) use ( &$pending ) {
			if ( str_starts_with( $src, Fonts::PREFIX ) ) {
				$src = Fonts::url( substr( $src, strlen( Fonts::PREFIX ) ) );
			}
			$path = Fonts::local_path( $src );
			if ( '' === $path ) {
				return null;
			}
			$bytes = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file in the uploads folder.
			$name  = wp_basename( $path );
			if ( 1 === preg_match( '/^[a-f0-9]{16}-(.+)$/', $name, $m ) && Fonts::valid_name( $name ) ) {
				$name = $m[1];
			}
			$check = Fonts::check_font( $name, $bytes );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			if ( count( $pending ) >= self::MAX_FILES ) {
				return new WP_Error( 'windrose_livery_files', __( 'Left out: fonts beyond the first 60 files.', 'windrose-livery' ) );
			}
			$stored = Fonts::stored_name( $name, $bytes );
			self::add_pending( $pending, $stored, $name, $family, 'font', $bytes );
			return Fonts::PREFIX . $stored;
		};
		$clean   = Sanitizer::clean( $raw, (string) $site_name, self::may_add_css(), $mapper );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		if ( '' === trim( (string) ( $options['title'] ?? '' ) ) ) {
			return new WP_Error( 'windrose_livery_title', __( 'The style variation needs a title.', 'windrose-livery' ), array( 'status' => 400 ) );
		}
		// Licenses of fonts already in the library travel with them.
		foreach ( Library::all() as $item ) {
			foreach ( $item['files'] as $file ) {
				if ( 'license' === $file['kind'] && self::pending_has_family( $pending, $file['family'] ) ) {
					$text = Fonts::read( $file['stored'] );
					if ( null !== $text ) {
						self::add_pending( $pending, $file['stored'], $file['name'], $file['family'], 'license', $text );
					}
				}
			}
		}
		return self::finish( $clean, $pending, $theme, $options );
	}

	/**
	 * Adds a variation built by Style Mermaid (already cleaned, files in memory) for Ipsum.
	 *
	 * @param array $clean   Clean variation.
	 * @phpstan-param Clean $clean
	 * @param array $pending Files to store.
	 * @phpstan-param Pending $pending
	 * @param array $options Dry run (default true), replace.
	 * @phpstan-param Options $options
	 * @return array<string, mixed>|WP_Error
	 */
	public static function add_built( array $clean, array $pending, array $options ) {
		return self::finish( $clean, $pending, Builder::THEME, $options );
	}

	/**
	 * Preview, or write files and save.
	 *
	 * @param array  $clean   Clean variation.
	 * @phpstan-param Clean $clean
	 * @param array  $pending Files to store.
	 * @phpstan-param Pending $pending
	 * @param string $theme   Stylesheet.
	 * @param array  $options Options.
	 * @phpstan-param Options $options
	 * @return array<string, mixed>|WP_Error
	 */
	private static function finish( array $clean, array $pending, string $theme, array $options ) {
		// Every way into the library (import, a site's styles, a copy to the shared library): rules that survive for site administrators.
		$clean['data'] = Languages::into_slugs( $clean['data'] );
		if ( ! Themes::exists( $theme ) ) {
			return new WP_Error( 'windrose_livery_theme', __( 'Choose an installed block theme.', 'windrose-livery' ), array( 'status' => 400 ) );
		}
		// Keep only files the clean data still refers to (and the licenses of their families).
		$families = $clean['data']['settings']['typography']['fontFamilies'] ?? array();
		$used     = Fonts::referenced( is_array( $families ) ? $families : array() );
		$fams     = array();
		foreach ( $pending as $stored => $file ) {
			if ( 'font' === $file['entry']['kind'] && in_array( $stored, $used, true ) ) {
				$fams[ $file['entry']['family'] ] = true;
			}
		}
		$pending = array_filter(
			$pending,
			static fn( array $file, string $stored ): bool => 'font' === $file['entry']['kind'] ? in_array( $stored, $used, true ) : isset( $fams[ $file['entry']['family'] ] ),
			ARRAY_FILTER_USE_BOTH
		);

		$existing = Library::find( sanitize_title( $clean['title'] ), $theme );
		$replace  = ! empty( $options['replace'] ) && null !== $existing;
		$preview  = array(
			'title'       => $clean['title'],
			'description' => $clean['description'],
			'theme'       => $theme,
			'themeName'   => Themes::name( $theme ),
			'palette'     => self::palette( $clean['data']['settings'] ),
			'fonts'       => self::font_names( $clean['data']['settings'] ),
			'files'       => count( $pending ),
			'css'         => $clean['css'],
			'notes'       => $clean['notes'],
			'replaces'    => null === $existing ? null : array(
				'id'      => $existing['id'],
				'title'   => $existing['title'],
				'version' => $existing['version'],
			),
		);
		if ( $options['dry_run'] ?? true ) {
			return $preview;
		}
		if ( null !== $existing && ! $replace ) {
			return new WP_Error(
				'windrose_livery_exists',
				/* translators: %s: variation title. */
				sprintf( __( 'A style variation called %s already exists for this theme. Replace it, or change the title.', 'windrose-livery' ), $existing['title'] ),
				array( 'status' => 409 )
			);
		}
		$quota = self::quota( $pending );
		if ( is_wp_error( $quota ) ) {
			return $quota;
		}
		foreach ( $pending as $stored => $file ) {
			$written = Fonts::write( (string) $stored, $file['bytes'] );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
		}
		$entries = array_values( array_map( static fn( array $file ): array => $file['entry'], $pending ) );
		$old     = $replace && null !== $existing ? array_column( $existing['files'], 'stored' ) : array();
		$id      = Library::save(
			array(
				'title'       => $clean['title'],
				'description' => $clean['description'],
				'theme'       => $theme,
				'data'        => $clean['data'],
				'files'       => $entries,
			),
			$replace && null !== $existing ? $existing['id'] : 0
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		// The fonts appear in the library site's Font Library, as installed fonts do.
		$families = $clean['data']['settings']['typography']['fontFamilies'] ?? array();
		Font_Library::register( is_array( $families ) ? $families : array() );
		if ( array() !== $old ) {
			// Files of the replaced version that nothing uses any more.
			$gone = array_values( array_diff( $old, array_keys( $pending ) ) );
			Fonts::delete( Fonts::deletable( $existing['files'], Fonts::unused( $gone, $id ) ) );
		}
		$item = Library::get( $id );
		return null === $item ? $preview : Rest::summary( $item );
	}

	/**
	 * On a network with upload quotas: whether the new files fit in the library site's free space.
	 *
	 * @param array $pending Files to store.
	 * @phpstan-param Pending $pending
	 * @return true|WP_Error
	 */
	private static function quota( array $pending ) {
		if ( ! is_multisite() || get_site_option( 'upload_space_check_disabled' ) ) {
			return true;
		}
		$bytes = 0;
		foreach ( $pending as $stored => $file ) {
			$bytes += Fonts::exists( (string) $stored ) ? 0 : strlen( $file['bytes'] );
		}
		if ( 0 === $bytes ) {
			return true;
		}
		$free = Library::on_library_site( static fn(): int => (int) get_upload_space_available() );
		if ( $bytes <= $free ) {
			return true;
		}
		return new WP_Error(
			'windrose_livery_quota',
			/* translators: 1: size of the fonts, 2: free space. */
			sprintf( __( 'The fonts of this style need %1$s, but this site has only %2$s of upload space left.', 'windrose-livery' ), size_format( $bytes ), size_format( $free ) ),
			array( 'status' => 507 )
		);
	}

	/**
	 * Copies a site's own variation into the shared library (network administrators), with its files.
	 *
	 * @param int   $site    Site whose own variation it is.
	 * @param int   $id      Its ID there.
	 * @param array $options Dry run (default true), replace.
	 * @phpstan-param Options $options
	 * @return array<string, mixed>|WP_Error
	 */
	public static function copy_to_shared( int $site, int $id, array $options ) {
		$item = Library::with_scope( $site, static fn(): ?array => Library::get( $id ) );
		if ( null === $item || $item['scope'] !== $site ) {
			return new WP_Error( 'windrose_livery_not_found', __( 'This style variation no longer exists.', 'windrose-livery' ), array( 'status' => 404 ) );
		}
		$pending = array();
		foreach ( $item['files'] as $file ) {
			$bytes = Library::with_scope( $site, static fn(): ?string => Fonts::read( $file['stored'] ) );
			if ( null !== $bytes ) {
				self::add_pending( $pending, $file['stored'], $file['name'], $file['family'], $file['kind'], $bytes );
			}
		}
		$clean = array(
			'title'       => $item['title'],
			'description' => $item['description'],
			'data'        => $item['data'],
			'css'         => self::has_css( $item['data']['styles'] ),
			'notes'       => array(),
		);
		return Library::with_scope( 0, static fn() => self::finish( $clean, $pending, $item['theme'], $options ) );
	}

	/**
	 * Whether styles hold custom CSS anywhere.
	 *
	 * @param array<mixed> $node Styles node.
	 * @return bool
	 */
	public static function has_css( array $node ): bool {
		foreach ( $node as $key => $value ) {
			if ( 'css' === $key || ( is_array( $value ) && self::has_css( $value ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the current user may bring custom CSS.
	 *
	 * @return bool
	 */
	public static function may_add_css(): bool {
		return current_user_can( 'edit_css' ) && current_user_can( 'unfiltered_html' );
	}

	/**
	 * Adds a file to store.
	 *
	 * @param array  $pending Files, added to.
	 * @phpstan-param Pending $pending
	 * @param string $stored  Stored name.
	 * @param string $name    Original name.
	 * @param string $family  Family slug.
	 * @param string $kind    font or license.
	 * @param string $bytes   Content.
	 * @return void
	 */
	private static function add_pending( array &$pending, string $stored, string $name, string $family, string $kind, string $bytes ): void {
		if ( isset( $pending[ $stored ] ) ) {
			return;
		}
		$pending[ $stored ] = array(
			'entry' => array(
				'stored' => $stored,
				'name'   => sanitize_file_name( $name ),
				'family' => sanitize_title( $family ),
				'kind'   => $kind,
			),
			'bytes' => $bytes,
		);
	}

	/**
	 * Whether a family has a font among the pending files.
	 *
	 * @param array  $pending Files.
	 * @phpstan-param Pending $pending
	 * @param string $family  Family slug.
	 * @return bool
	 */
	private static function pending_has_family( array $pending, string $family ): bool {
		foreach ( $pending as $file ) {
			if ( 'font' === $file['entry']['kind'] && $file['entry']['family'] === $family ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A site's saved settings with presets grouped by origin (as the Site Editor saves them) turned into plain lists,
	 * as variation files have them: the theme's edited presets, then the site's own; core's defaults are left out.
	 *
	 * @param array<mixed> $settings Saved settings.
	 * @return array<mixed>
	 */
	public static function flatten_origins( array $settings ): array {
		$flatten  = static function ( array $node ): array {
			foreach ( WP_Theme_JSON::PRESETS_METADATA as $meta ) {
				$value = _wp_array_get( $node, $meta['path'], null );
				if ( ! is_array( $value ) || array() === $value || array_is_list( $value ) ) {
					continue;
				}
				$list  = array();
				$slugs = array();
				foreach ( array( 'theme', 'custom' ) as $origin ) {
					foreach ( is_array( $value[ $origin ] ?? null ) ? $value[ $origin ] : array() as $preset ) {
						$slug = is_array( $preset ) && is_string( $preset['slug'] ?? null ) ? $preset['slug'] : '';
						if ( '' !== $slug && ! isset( $slugs[ $slug ] ) ) {
							$slugs[ $slug ] = true;
							$list[]         = $preset;
						}
					}
				}
				_wp_array_set( $node, $meta['path'], $list );
			}
			return $node;
		};
		$settings = $flatten( $settings );
		if ( is_array( $settings['blocks'] ?? null ) ) {
			foreach ( $settings['blocks'] as $name => $block ) {
				if ( is_array( $block ) ) {
					$settings['blocks'][ $name ] = $flatten( $block );
				}
			}
		}
		return $settings;
	}

	/**
	 * The palette of settings, for previews.
	 *
	 * @param array<mixed> $settings Settings.
	 * @return list<array{slug: string, name: string, color: string}>
	 */
	public static function palette( array $settings ): array {
		$out = array();
		foreach ( is_array( $settings['color']['palette'] ?? null ) ? $settings['color']['palette'] : array() as $color ) {
			if ( is_array( $color ) && is_string( $color['slug'] ?? null ) && is_string( $color['color'] ?? null ) ) {
				$out[] = array(
					'slug'  => $color['slug'],
					'name'  => is_string( $color['name'] ?? null ) ? $color['name'] : $color['slug'],
					'color' => $color['color'],
				);
			}
		}
		return $out;
	}

	/**
	 * Font family names of settings, for previews.
	 *
	 * @param array<mixed> $settings Settings.
	 * @return list<array{name: string, slug: string, faces: int}>
	 */
	public static function font_names( array $settings ): array {
		$out = array();
		foreach ( is_array( $settings['typography']['fontFamilies'] ?? null ) ? $settings['typography']['fontFamilies'] : array() as $family ) {
			if ( is_array( $family ) && is_string( $family['slug'] ?? null ) ) {
				$out[] = array(
					'name'  => is_string( $family['name'] ?? null ) ? $family['name'] : $family['slug'],
					'slug'  => $family['slug'],
					'faces' => is_array( $family['fontFace'] ?? null ) ? count( $family['fontFace'] ) : 0,
				);
			}
		}
		return $out;
	}
}
