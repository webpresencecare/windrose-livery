<?php
/**
 * Font files of stored variations.
 *
 * Since 0.4 in the folder where WordPress's Font Library puts fonts (`wp_get_font_dir()`:
 * `uploads/fonts/` on the main site, `uploads/sites/<id>/fonts/` on another site) of the library site of the scope
 * (the main site for the shared library, the site itself for its own styles), and registered there in the Font
 * Library (`Font_Library`), so they show in Styles, Typography, Fonts like any installed font. Files are named
 * `<first 16 hex of their sha256>-<original name>`, so the same file is stored once and a file is never overwritten.
 * In stored data a font source is `windrose-livery:<file>`; it becomes an address on this website when a variation
 * is shown or applied. Nothing is ever loaded from another host.
 *
 * Accepted: WOFF2, WOFF, TrueType and OpenType files, recognized by their first bytes (not their extension), at most
 * 10 MB each; license texts (OFL.txt, LICENSE...) at most 200 KB.
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

use WP_Error;
use Windrose\Common\Files;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Font storage, checks and @font-face rules.
 */
final class Fonts {

	public const PREFIX      = 'windrose-livery:';
	public const FOLDER      = 'windrose-livery';
	public const MAX_FONT    = 10485760;
	public const MAX_LICENSE = 204800;

	/**
	 * Font formats by extension: first bytes, CSS format name.
	 */
	private const FORMATS = array(
		'woff2' => array( array( 'wOF2' ), 'woff2' ),
		'woff'  => array( array( 'wOFF' ), 'woff' ),
		'ttf'   => array( array( "\x00\x01\x00\x00", 'true' ), 'truetype' ),
		'otf'   => array( array( 'OTTO', "\x00\x01\x00\x00" ), 'opentype' ),
	);

	/**
	 * Fonts folders by library site: path and address.
	 *
	 * @var array<int, array{path: string, url: string}>
	 */
	private static array $dirs = array();

	/**
	 * The fonts folder of the scope in use: the Font Library's folder of its library site.
	 *
	 * @return array{path: string, url: string}
	 */
	public static function dir(): array {
		$site = Library::site_id();
		if ( ! isset( self::$dirs[ $site ] ) ) {
			self::$dirs[ $site ] = Library::on_library_site(
				static function (): array {
					$dir = wp_get_font_dir();
					return self::stable( (string) $dir['path'], (string) $dir['url'] );
				}
			);
		}
		return self::$dirs[ $site ];
	}

	/**
	 * Livery's own folder on the network's library site (`uploads/windrose-livery/`): font packs, Style Mermaid's
	 * font cache, and the fonts of versions before 0.4.
	 *
	 * @return array{path: string, url: string}
	 */
	public static function network_dir(): array {
		$site = -1 * Library::network_site_id();
		if ( ! isset( self::$dirs[ $site ] ) ) {
			self::$dirs[ $site ] = Library::on_network_site(
				static function (): array {
					$uploads = wp_upload_dir( null, false );
					$dir     = self::stable( (string) $uploads['basedir'], (string) $uploads['baseurl'] );
					return array(
						'path' => $dir['path'] . '/' . self::FOLDER,
						'url'  => $dir['url'] . '/' . self::FOLDER,
					);
				}
			);
		}
		return self::$dirs[ $site ];
	}

	/**
	 * A folder of the current site with an address built from the site's stored address. On a subdirectory network,
	 * WP_CONTENT_URL holds the path of the site of the request (/fr/wp-content): built this way it is the same on every
	 * site and from WP-CLI (get_site_url() and set_url_scheme() follow the request, http for WP-CLI).
	 *
	 * @param string $path Path.
	 * @param string $url  Address WordPress gives.
	 * @return array{path: string, url: string}
	 */
	private static function stable( string $path, string $url ): array {
		$path = untrailingslashit( wp_normalize_path( $path ) );
		$url  = untrailingslashit( $url );
		$root = untrailingslashit( wp_normalize_path( ABSPATH ) );
		if ( str_starts_with( $path, $root . '/' ) && '' === (string) get_option( 'upload_url_path' ) ) {
			$url = untrailingslashit( (string) get_option( 'siteurl' ) ) . substr( $path, strlen( $root ) );
		}
		return array(
			'path' => $path,
			'url'  => $url,
		);
	}

	/**
	 * Forgets the folders (tests switch upload folders).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$dirs = array();
	}

	/**
	 * Whether a stored file name has Livery's shape (no folder, no "..").
	 *
	 * @param string $name Stored name.
	 * @return bool
	 */
	public static function valid_name( string $name ): bool {
		return 1 === preg_match( '/^[a-f0-9]{16}-[A-Za-z0-9][A-Za-z0-9._-]{0,150}$/', $name ) && ! str_contains( $name, '..' );
	}

	/**
	 * The stored name for a file's content.
	 *
	 * @param string $name  Original file name.
	 * @param string $bytes Content.
	 * @return string
	 */
	public static function stored_name( string $name, string $bytes ): string {
		$base = sanitize_file_name( wp_basename( $name ) );
		$base = (string) preg_replace( '/[^A-Za-z0-9._-]/', '-', $base );
		$base = ltrim( str_replace( '..', '.', $base ), '.-_' );
		if ( '' === $base ) {
			$base = 'file';
		}
		return substr( hash( 'sha256', $bytes ), 0, 16 ) . '-' . substr( $base, 0, 120 );
	}

	/**
	 * Checks a font file by its first bytes and size.
	 *
	 * @param string $name  File name (for the extension and messages).
	 * @param string $bytes Content.
	 * @return string|WP_Error CSS format name.
	 */
	public static function check_font( string $name, string $bytes ) {
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! isset( self::FORMATS[ $ext ] ) ) {
			/* translators: %s: file name. */
			return new WP_Error( 'windrose_livery_font_type', sprintf( __( '%s is not a WOFF2, WOFF, TrueType or OpenType font.', 'windrose-livery' ), $name ) );
		}
		if ( strlen( $bytes ) > self::MAX_FONT ) {
			/* translators: %s: file name. */
			return new WP_Error( 'windrose_livery_font_size', sprintf( __( '%s is larger than 10 MB.', 'windrose-livery' ), $name ) );
		}
		foreach ( self::FORMATS[ $ext ][0] as $magic ) {
			if ( str_starts_with( $bytes, $magic ) ) {
				return self::FORMATS[ $ext ][1];
			}
		}
		/* translators: %s: file name. */
		return new WP_Error( 'windrose_livery_font_content', sprintf( __( '%s does not contain the font its name says.', 'windrose-livery' ), $name ) );
	}

	/**
	 * Checks a license text (plain text, small).
	 *
	 * @param string $bytes Content.
	 * @return bool
	 */
	public static function check_license( string $bytes ): bool {
		return strlen( $bytes ) <= self::MAX_LICENSE && ! str_contains( $bytes, "\0" ) && wp_check_invalid_utf8( $bytes ) === $bytes;
	}

	/**
	 * Path of a stored file.
	 *
	 * @param string $stored Stored name.
	 * @return string Empty for a bad name.
	 */
	public static function path( string $stored ): string {
		return self::valid_name( $stored ) ? self::dir()['path'] . '/' . $stored : '';
	}

	/**
	 * Address of a stored file.
	 *
	 * @param string $stored Stored name.
	 * @return string Empty for a bad name.
	 */
	public static function url( string $stored ): string {
		return self::valid_name( $stored ) ? self::dir()['url'] . '/' . rawurlencode( $stored ) : '';
	}

	/**
	 * Whether a stored file exists.
	 *
	 * @param string $stored Stored name.
	 * @return bool
	 */
	public static function exists( string $stored ): bool {
		$path = self::path( $stored );
		return '' !== $path && is_file( $path );
	}

	/**
	 * Writes a file into the fonts folder, unless it is already there (same name = same content).
	 *
	 * @param string $stored Stored name.
	 * @param string $bytes  Content.
	 * @return true|WP_Error
	 */
	public static function write( string $stored, string $bytes ) {
		$path = self::path( $stored );
		if ( '' === $path ) {
			return new WP_Error( 'windrose_livery_file_name', __( 'A file name was refused.', 'windrose-livery' ) );
		}
		if ( is_file( $path ) ) {
			return true;
		}
		if ( ! wp_mkdir_p( dirname( $path ) ) ) {
			return new WP_Error( 'windrose_livery_folder', __( 'The fonts folder could not be created in the uploads folder.', 'windrose-livery' ) );
		}
		if ( ! Files::write( $path, $bytes ) ) {
			/* translators: %s: file name. */
			return new WP_Error( 'windrose_livery_write', sprintf( __( '%s could not be written in the uploads folder.', 'windrose-livery' ), $stored ) );
		}
		return true;
	}

	/**
	 * Reads a stored file.
	 *
	 * @param string $stored Stored name.
	 * @return string|null
	 */
	public static function read( string $stored ): ?string {
		if ( ! self::exists( $stored ) ) {
			return null;
		}
		return Files::read( self::path( $stored ) );
	}

	/**
	 * Font families with `windrose-livery:` sources turned into addresses on this website.
	 *
	 * @param array<mixed> $families Font families (theme.json shape).
	 * @return array<mixed>
	 */
	public static function resolve_families( array $families ): array {
		foreach ( $families as $i => $family ) {
			if ( ! is_array( $family ) || ! is_array( $family['fontFace'] ?? null ) ) {
				continue;
			}
			foreach ( $family['fontFace'] as $j => $face ) {
				if ( ! is_array( $face ) || ! isset( $face['src'] ) ) {
					continue;
				}
				$src = array();
				foreach ( (array) $face['src'] as $one ) {
					$src[] = is_string( $one ) && str_starts_with( $one, self::PREFIX ) ? self::url( substr( $one, strlen( self::PREFIX ) ) ) : $one;
				}
				$families[ $i ]['fontFace'][ $j ]['src'] = $src;
			}
		}
		return $families;
	}

	/**
	 * Stored names referenced by font families (`windrose-livery:` sources).
	 *
	 * @param array<mixed> $families Font families.
	 * @return list<string>
	 */
	public static function referenced( array $families ): array {
		$names = array();
		foreach ( $families as $family ) {
			foreach ( is_array( $family ) && is_array( $family['fontFace'] ?? null ) ? $family['fontFace'] : array() as $face ) {
				foreach ( is_array( $face ) ? (array) ( $face['src'] ?? array() ) : array() as $one ) {
					if ( is_string( $one ) && str_starts_with( $one, self::PREFIX ) ) {
						$names[] = substr( $one, strlen( self::PREFIX ) );
					}
				}
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Maps an address on this installation (any site's host) to a file in the uploads folder (a font installed
	 * through a site's Font Library, or a file already in Livery's folder). Anything else is refused.
	 *
	 * @param string $url Address.
	 * @return string Path, or empty.
	 */
	public static function local_path( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'], $parts['path'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}
		if ( ! in_array( strtolower( $parts['host'] ), self::hosts(), true ) ) {
			return '';
		}
		// The uploads folder's address, whatever site path is in front of it (/fr/wp-content/uploads/...), and
		// whatever the folder is called (WordPress's own uploads folder, the UPLOADS constant included).
		$root = self::uploads_root();
		if ( '' === $root['marker'] || '' === $root['path'] ) {
			return '';
		}
		$at = strpos( rawurldecode( $parts['path'] ), $root['marker'] );
		if ( false === $at ) {
			return '';
		}
		$relative = substr( rawurldecode( $parts['path'] ), $at + strlen( $root['marker'] ) );
		$uploads  = realpath( $root['path'] );
		$path     = realpath( $root['path'] . '/' . $relative );
		if ( false === $path || false === $uploads || ! str_starts_with( $path, $uploads . DIRECTORY_SEPARATOR ) || ! is_file( $path ) ) {
			return '';
		}
		return $path;
	}

	/**
	 * The uploads folder of the whole installation (the main site's, which holds the other sites' folders): its path,
	 * and the part of its address found in any site's file addresses (`/wp-content/uploads/`, after a site's own path
	 * such as `/fr/`).
	 *
	 * @return array{path: string, marker: string}
	 */
	public static function uploads_root(): array {
		$read = static fn(): array => wp_upload_dir( null, false );
		$dir  = is_multisite() && ! is_main_site() ? Library::on_network_site( $read ) : $read();
		$path = is_string( $dir['basedir'] ?? null ) ? untrailingslashit( wp_normalize_path( $dir['basedir'] ) ) : '';
		// The address's path from the folder's place on disk (WordPress builds upload addresses the same way; its
		// baseurl after switch_to_blog() still carries the current site's path, so it is not used here).
		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$root    = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$marker  = '';
		if ( '' !== $path && str_starts_with( $path . '/', $content . '/' ) ) {
			$marker = '/' . wp_basename( $content ) . substr( $path, strlen( $content ) ) . '/';
		} elseif ( '' !== $path && str_starts_with( $path . '/', $root . '/' ) ) {
			$marker = substr( $path, strlen( $root ) ) . '/';
		}
		return array(
			'path'   => $path,
			'marker' => '/' === $marker ? '' : $marker,
		);
	}

	/**
	 * Host names of this installation (every site of the network).
	 *
	 * @return list<string>
	 */
	private static function hosts(): array {
		$hosts = array( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ), strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ) );
		if ( is_multisite() ) {
			foreach ( get_sites(
				array(
					'number' => 0,
					'fields' => 'ids',
				)
			) as $site ) {
				$hosts[] = strtolower( (string) wp_parse_url( get_home_url( (int) $site ), PHP_URL_HOST ) );
				$hosts[] = strtolower( (string) wp_parse_url( get_site_url( (int) $site ), PHP_URL_HOST ) );
			}
		}
		return array_values( array_unique( array_filter( $hosts ) ) );
	}

	/**
	 * Of these stored files (in the fonts folder of the scope in use), those no variation stored on that site (except
	 * `$except`) and no site's saved styles (revisions included) refer to any more: they can be deleted.
	 *
	 * @param array $names  Stored names.
	 * @phpstan-param list<string> $names
	 * @param int   $except Variation ID being deleted or replaced.
	 * @return list<string>
	 */
	public static function unused( array $names, int $except ): array {
		$names = array_values( array_filter( array_unique( $names ), array( __CLASS__, 'valid_name' ) ) );
		foreach ( Library::all_on_site() as $item ) {
			if ( $item['id'] === $except ) {
				continue;
			}
			$used  = array_column( $item['files'], 'stored' );
			$names = array_values( array_diff( $names, $used ) );
		}
		if ( array() === $names ) {
			return array();
		}
		$sites = is_multisite()
			? get_sites(
				array(
					'network_id' => get_current_network_id(),
					'fields'     => 'ids',
					'number'     => 0,
				)
			)
			: array( get_current_blog_id() );
		foreach ( $sites as $site ) {
			$switch = is_multisite() && get_current_blog_id() !== (int) $site;
			if ( $switch ) {
				switch_to_blog( (int) $site );
			}
			$names = array_values( array_filter( $names, array( __CLASS__, 'unused_here' ) ) );
			if ( $switch ) {
				restore_current_blog();
			}
			if ( array() === $names ) {
				break;
			}
		}
		return $names;
	}

	/**
	 * Whether no saved styles (or their revisions) of the current site mention a stored file.
	 *
	 * @param string $name Stored name.
	 * @return bool
	 */
	private static function unused_here( string $name ): bool {
		global $wpdb;
		// One indexed post type filter and a LIKE on the few global styles rows; run only when a variation is deleted.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->posts} parent ON p.post_parent = parent.ID
				WHERE ( p.post_type = 'wp_global_styles' OR ( p.post_type = 'revision' AND parent.post_type = 'wp_global_styles' ) )
				AND p.post_content LIKE %s LIMIT 1",
				'%' . $wpdb->esc_like( $name ) . '%'
			)
		);
		return null === $found;
	}

	/**
	 * Of the unused files of a variation, those that may go: a license text stays while a font of its family stays.
	 *
	 * @param array $files  The variation's files.
	 * @phpstan-param list<array{stored: string, name: string, family: string, kind: string}> $files
	 * @param array $unused Unused stored names (from unused()).
	 * @phpstan-param list<string> $unused
	 * @return list<string>
	 */
	public static function deletable( array $files, array $unused ): array {
		$kept = array();
		foreach ( $files as $file ) {
			if ( 'font' === $file['kind'] && ! in_array( $file['stored'], $unused, true ) ) {
				$kept[ $file['family'] ] = true;
			}
		}
		$out = array();
		foreach ( $files as $file ) {
			if ( in_array( $file['stored'], $unused, true ) && ( 'font' === $file['kind'] || ! isset( $kept[ $file['family'] ] ) ) ) {
				$out[] = $file['stored'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Deletes stored files, and their entries in the Font Library.
	 *
	 * @param array $names Stored names.
	 * @phpstan-param list<string> $names
	 * @return int How many were deleted.
	 */
	public static function delete( array $names ): int {
		Font_Library::forget( $names );
		$count = 0;
		foreach ( $names as $name ) {
			$path = self::path( $name );
			if ( '' !== $path && is_file( $path ) ) {
				wp_delete_file( $path );
				clearstatcache( true, $path );
				$count += self::exists( $name ) ? 0 : 1;
			}
		}
		return $count;
	}

	/**
	 * Font-face rules for font families whose sources are addresses (after resolve_families()).
	 *
	 * @param array<mixed> $families Font families.
	 * @return string CSS.
	 */
	public static function css( array $families ): string {
		$css = '';
		foreach ( $families as $family ) {
			foreach ( is_array( $family ) && is_array( $family['fontFace'] ?? null ) ? $family['fontFace'] : array() as $face ) {
				if ( ! is_array( $face ) || ! is_string( $face['fontFamily'] ?? null ) ) {
					continue;
				}
				$src = array();
				foreach ( (array) ( $face['src'] ?? array() ) as $url ) {
					if ( ! is_string( $url ) || '' === $url ) {
						continue;
					}
					$ext    = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
					$format = self::FORMATS[ $ext ][1] ?? '';
					$src[]  = "url('" . esc_url( $url ) . "')" . ( '' !== $format ? " format('" . $format . "')" : '' );
				}
				if ( array() === $src ) {
					continue;
				}
				$rule  = 'font-family:"' . str_replace( array( '"', '\\', '<' ), '', trim( $face['fontFamily'], "\"' " ) ) . '";';
				$rule .= 'font-style:' . self::css_value( $face['fontStyle'] ?? 'normal' ) . ';';
				$rule .= 'font-weight:' . self::css_value( $face['fontWeight'] ?? '400' ) . ';';
				$rule .= 'font-display:' . self::css_value( $face['fontDisplay'] ?? 'fallback' ) . ';';
				foreach ( array(
					'unicodeRange'    => 'unicode-range',
					'fontStretch'     => 'font-stretch',
					'sizeAdjust'      => 'size-adjust',
					'ascentOverride'  => 'ascent-override',
					'descentOverride' => 'descent-override',
					'lineGapOverride' => 'line-gap-override',
				) as $key => $property ) {
					if ( is_string( $face[ $key ] ?? null ) && '' !== $face[ $key ] ) {
						$rule .= $property . ':' . self::css_value( $face[ $key ] ) . ';';
					}
				}
				$css .= '@font-face{' . $rule . 'src:' . implode( ',', $src ) . ';}';
			}//end foreach
		}//end foreach
		return $css;
	}

	/**
	 * A CSS value already checked by the sanitizer, with anything that could leave the declaration removed.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function css_value( $value ): string {
		return (string) preg_replace( '/[^A-Za-z0-9 %+,.?-]/', '', is_scalar( $value ) ? (string) $value : '' );
	}
}
