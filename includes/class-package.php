<?php
/**
 * Variation packages: a `.json` file, or a `.zip` holding one variation file, optionally `fonts.json` and the font
 * files they name (the shape Livery's export writes).
 *
 * | Path in the zip            | Holds                                                               |
 * |----------------------------|---------------------------------------------------------------------|
 * | `my-brand/my-brand.json`   | theme.json shape: title, settings, styles                           |
 * | `my-brand/fonts.json`      | `{ "fontFamilies": [ ... ] }`, sources relative to this folder      |
 * | `my-brand/fonts/...`       | Font files, and their license texts (OFL.txt, LICENSE...)           |
 *
 * Nothing is ever extracted to disk: entries are read in memory, by name, within size limits, and a source is only
 * looked up inside the variation's folder (no "..", no absolute path, no address).
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

use WP_Error;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes variation packages.
 *
 * @phpstan-import-type Variation from Library
 */
final class Package {

	public const MAX_JSON    = 1048576;
	public const MAX_ENTRIES = 500;
	public const MAX_TOTAL   = 83886080;

	/**
	 * The decoded variation (fonts.json merged in).
	 *
	 * @var array<mixed>
	 */
	public array $variation = array();

	/**
	 * Title to use when the variation has none (the file name).
	 *
	 * @var string
	 */
	public string $fallback = '';

	/**
	 * Open zip, if any.
	 *
	 * @var ZipArchive|null
	 */
	private ?ZipArchive $zip = null;

	/**
	 * Folder of the variation file inside the zip ('' at the root).
	 *
	 * @var string
	 */
	private string $base = '';

	/**
	 * Zip entries: name => index (files only).
	 *
	 * @var array<string, int>
	 */
	private array $entries = array();

	/**
	 * Opens a package.
	 *
	 * @param string $path     Uploaded file.
	 * @param string $filename Its original name (the extension decides the kind).
	 * @return Package|WP_Error
	 */
	public static function open( string $path, string $filename ) {
		$ext  = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$self = new self();
		$name = pathinfo( wp_basename( $filename ), PATHINFO_FILENAME );

		$self->fallback = ucwords( str_replace( array( '-', '_' ), ' ', sanitize_text_field( $name ) ) );
		if ( 'json' === $ext ) {
			$size = (int) filesize( $path );
			if ( $size <= 0 || $size > self::MAX_JSON ) {
				return new WP_Error( 'windrose_livery_json_size', __( 'The style variation file is empty or larger than 1 MB.', 'windrose-livery' ) );
			}
			$decoded = self::decode( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local uploaded file.
			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			}
			$self->variation = $decoded;
			return $self;
		}
		if ( 'zip' !== $ext ) {
			return new WP_Error( 'windrose_livery_type', __( 'Choose a style variation file (.json) or a package (.zip).', 'windrose-livery' ) );
		}
		if ( ! class_exists( ZipArchive::class ) ) {
			return new WP_Error( 'windrose_livery_zip', __( 'This server cannot read zip files (PHP zip extension missing). Import the .json file alone.', 'windrose-livery' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
			return new WP_Error( 'windrose_livery_zip_open', __( 'This zip file cannot be read.', 'windrose-livery' ) );
		}
		$self->zip = $zip;
		$error     = $self->read_zip();
		return is_wp_error( $error ) ? $error : $self;
	}

	/**
	 * Lists the zip's files and finds the variation.
	 *
	 * @return true|WP_Error
	 */
	private function read_zip() {
		$zip = $this->zip;
		if ( null === $zip ) {
			return new WP_Error( 'windrose_livery_zip_open', __( 'This zip file cannot be read.', 'windrose-livery' ) );
		}
		if ( $zip->numFiles > self::MAX_ENTRIES ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
			return new WP_Error( 'windrose_livery_zip_entries', __( 'This zip file holds too many files for a style variation.', 'windrose-livery' ) );
		}
		$total      = 0;
		$candidates = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
			$stat = $zip->statIndex( $i );
			if ( ! is_array( $stat ) ) {
				continue;
			}
			$name   = (string) $stat['name'];
			$total += (int) $stat['size'];
			if ( str_ends_with( $name, '/' ) || ! self::safe_path( $name ) || str_starts_with( $name, '__MACOSX/' ) ) {
				continue;
			}
			$this->entries[ $name ] = $i;
			$base                   = wp_basename( $name );
			if ( str_ends_with( strtolower( $base ), '.json' ) && 'fonts.json' !== strtolower( $base ) && ! str_starts_with( $base, '.' ) ) {
				$candidates[] = $name;
			}
		}
		if ( $total > self::MAX_TOTAL ) {
			return new WP_Error( 'windrose_livery_zip_size', __( 'This zip file is larger than 80 MB once unpacked.', 'windrose-livery' ) );
		}
		if ( 1 !== count( $candidates ) ) {
			return new WP_Error(
				'windrose_livery_zip_variation',
				array() === $candidates
					? __( 'This zip file holds no style variation (.json) file.', 'windrose-livery' )
					/* translators: %s: list of file names. */
					: sprintf( __( 'This zip file holds several style variations (%s): one per zip file.', 'windrose-livery' ), implode( ', ', array_map( 'sanitize_text_field', $candidates ) ) )
			);
		}
		$file       = $candidates[0];
		$this->base = '.' === dirname( $file ) ? '' : dirname( $file );
		$json       = $this->entry( $file, self::MAX_JSON );
		if ( null === $json ) {
			return new WP_Error( 'windrose_livery_json_size', __( 'The style variation file is empty or larger than 1 MB.', 'windrose-livery' ) );
		}
		$decoded = self::decode( $json );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		$this->fallback  = ucwords( str_replace( array( '-', '_' ), ' ', sanitize_text_field( pathinfo( $file, PATHINFO_FILENAME ) ) ) );
		$this->variation = $decoded;

		$fonts_name = ( '' === $this->base ? '' : $this->base . '/' ) . 'fonts.json';
		if ( isset( $this->entries[ $fonts_name ] ) ) {
			$fonts = self::decode( (string) $this->entry( $fonts_name, self::MAX_JSON ) );
			if ( is_wp_error( $fonts ) ) {
				return new WP_Error( 'windrose_livery_fonts_json', __( 'fonts.json cannot be read.', 'windrose-livery' ) );
			}
			$this->merge_fonts( is_array( $fonts['fontFamilies'] ?? null ) ? $fonts['fontFamilies'] : array() );
		}
		return true;
	}

	/**
	 * Adds fonts.json's families to the variation's (same slug: fonts.json's wins).
	 *
	 * @param array<mixed> $families Families.
	 * @return void
	 */
	private function merge_fonts( array $families ): void {
		if ( array() === $families ) {
			return;
		}
		$settings = is_array( $this->variation['settings'] ?? null ) ? $this->variation['settings'] : array();
		$current  = is_array( $settings['typography']['fontFamilies'] ?? null ) ? $settings['typography']['fontFamilies'] : array();
		$by_slug  = array();
		foreach ( array_merge( $current, $families ) as $family ) {
			if ( is_array( $family ) ) {
				$by_slug[ is_string( $family['slug'] ?? null ) ? $family['slug'] : count( $by_slug ) . '-' ] = $family;
			}
		}
		$settings['typography']                 = is_array( $settings['typography'] ?? null ) ? $settings['typography'] : array();
		$settings['typography']['fontFamilies'] = array_values( $by_slug );
		$this->variation['settings']            = $settings;
	}

	/**
	 * The content of a file of the package, by its path relative to the variation's folder (`file:./` allowed).
	 *
	 * @param string $relative Relative path.
	 * @param int    $max      Size limit in bytes.
	 * @return array{name: string, bytes: string}|null
	 */
	public function file( string $relative, int $max = Fonts::MAX_FONT ): ?array {
		if ( null === $this->zip ) {
			return null;
		}
		$relative = (string) preg_replace( '#^(file:)?\./#', '', $relative );
		if ( ! self::safe_path( $relative ) || 1 === preg_match( '#^[a-z][a-z0-9+.-]*:#i', $relative ) ) {
			return null;
		}
		$name  = ( '' === $this->base ? '' : $this->base . '/' ) . $relative;
		$bytes = isset( $this->entries[ $name ] ) ? $this->entry( $name, $max ) : null;
		return null === $bytes ? null : array(
			'name'  => $name,
			'bytes' => $bytes,
		);
	}

	/**
	 * License texts next to a file of the package (OFL.txt, LICENSE, COPYING...).
	 *
	 * @param string $name Entry name of a font file.
	 * @return array<string, string> File name => text.
	 */
	public function licenses_near( string $name ): array {
		$dir   = dirname( $name );
		$found = array();
		foreach ( array_keys( $this->entries ) as $entry ) {
			if ( dirname( $entry ) !== $dir || 1 !== preg_match( '/^(OFL|LICEN[CS]E|COPYING|COPYRIGHT)([._-][A-Za-z0-9._-]*)?$/i', wp_basename( $entry ) ) ) {
				continue;
			}
			$text = $this->entry( $entry, Fonts::MAX_LICENSE );
			if ( null !== $text && Fonts::check_license( $text ) ) {
				$found[ wp_basename( $entry ) ] = $text;
			}
		}
		return $found;
	}

	/**
	 * Reads one entry within a size limit.
	 *
	 * @param string $name Entry name.
	 * @param int    $max  Limit.
	 * @return string|null Null when missing, empty or too large.
	 */
	private function entry( string $name, int $max ): ?string {
		if ( null === $this->zip || ! isset( $this->entries[ $name ] ) ) {
			return null;
		}
		$stat = $this->zip->statIndex( $this->entries[ $name ] );
		if ( ! is_array( $stat ) || (int) $stat['size'] <= 0 || (int) $stat['size'] > $max ) {
			return null;
		}
		$bytes = $this->zip->getFromIndex( $this->entries[ $name ], $max + 1 );
		return is_string( $bytes ) && strlen( $bytes ) === (int) $stat['size'] ? $bytes : null;
	}

	/**
	 * Closes the zip.
	 *
	 * @return void
	 */
	public function close(): void {
		if ( null !== $this->zip ) {
			$this->zip->close();
			$this->zip = null;
		}
	}

	/**
	 * Whether a relative path stays inside its folder.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function safe_path( string $path ): bool {
		if ( '' === $path || str_starts_with( $path, '/' ) || str_contains( $path, '\\' ) || str_contains( $path, "\0" ) ) {
			return false;
		}
		foreach ( explode( '/', $path ) as $part ) {
			if ( '..' === $part || '.' === $part ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Decodes JSON into an array.
	 *
	 * @param string $json JSON.
	 * @return array<mixed>|WP_Error
	 */
	private static function decode( string $json ) {
		$json    = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $json );
		$decoded = json_decode( $json, true, 64 );
		if ( ! is_array( $decoded ) || array_is_list( $decoded ) ) {
			return new WP_Error( 'windrose_livery_json', __( 'The style variation file is not valid JSON.', 'windrose-livery' ) );
		}
		return $decoded;
	}

	/**
	 * Writes a stored variation as a zip package (fonts and license texts included).
	 *
	 * @param array         $item Variation.
	 * @phpstan-param Variation $item
	 * @param callable|null $read Reads a stored file (default: the library's fonts folder); Style Mermaid passes the
	 *                            files of a variation it has just built.
	 * @phpstan-param (callable(string): ?string)|null $read
	 * @return string|WP_Error Path of a temporary zip file (the caller deletes it).
	 */
	public static function export( array $item, ?callable $read = null ) {
		if ( ! class_exists( ZipArchive::class ) ) {
			return new WP_Error( 'windrose_livery_zip', __( 'This server cannot write zip files (PHP zip extension missing).', 'windrose-livery' ) );
		}
		$slug     = sanitize_title( $item['title'] );
		$slug     = '' === $slug ? 'style-variation' : $slug;
		$settings = $item['data']['settings'];
		$families = is_array( $settings['typography']['fontFamilies'] ?? null ) ? $settings['typography']['fontFamilies'] : array();

		$paths = array();
		$used  = array();
		$files = array();
		foreach ( $item['files'] as $file ) {
			$folder = 'fonts/' . ( '' !== $file['family'] ? sanitize_title( $file['family'] ) : 'other' ) . '/';
			$name   = sanitize_file_name( $file['name'] );
			$path   = $folder . $name;
			for ( $n = 2; isset( $used[ $path ] ); $n++ ) {
				$path = $folder . pathinfo( $name, PATHINFO_FILENAME ) . '-' . $n . ( '' !== pathinfo( $name, PATHINFO_EXTENSION ) ? '.' . pathinfo( $name, PATHINFO_EXTENSION ) : '' );
			}
			$used[ $path ]                = true;
			$paths[ $file['stored'] ]     = $path;
			$files[ $slug . '/' . $path ] = $file['stored'];
		}
		foreach ( $families as $i => $family ) {
			foreach ( is_array( $family ) && is_array( $family['fontFace'] ?? null ) ? $family['fontFace'] : array() as $j => $face ) {
				$src = array();
				foreach ( is_array( $face ) ? (array) ( $face['src'] ?? array() ) : array() as $one ) {
					$stored = is_string( $one ) && str_starts_with( $one, Fonts::PREFIX ) ? substr( $one, strlen( Fonts::PREFIX ) ) : '';
					if ( isset( $paths[ $stored ] ) ) {
						$src[] = $paths[ $stored ];
					}
				}
				$families[ $i ]['fontFace'][ $j ]['src'] = $src;
			}
		}

		$variation = array(
			'$schema' => 'https://schemas.wp.org/trunk/theme.json',
			'version' => \WP_Theme_JSON::LATEST_SCHEMA,
			'title'   => $item['title'],
		);
		if ( '' !== $item['description'] ) {
			$variation['description'] = $item['description'];
		}
		if ( array() !== $files ) {
			unset( $settings['typography']['fontFamilies'] );
			if ( array() === ( $settings['typography'] ?? null ) ) {
				unset( $settings['typography'] );
			}
		} elseif ( array() !== $families ) {
			$settings['typography']['fontFamilies'] = $families;
		}
		$variation += Library::objects(
			array(
				'settings' => $settings,
				'styles'   => $item['data']['styles'],
			)
		);

		$zip_path = wp_tempnam( $slug . '.zip' );
		$zip      = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'windrose_livery_zip_write', __( 'The zip file could not be written.', 'windrose-livery' ) );
		}
		$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		$zip->addFromString( $slug . '/' . $slug . '.json', (string) wp_json_encode( $variation, $flags ) . "\n" );
		if ( array() !== $files ) {
			$zip->addFromString( $slug . '/fonts.json', (string) wp_json_encode( array( 'fontFamilies' => $families ), $flags ) . "\n" );
		}
		foreach ( $files as $path => $stored ) {
			$bytes = null === $read ? Library::with_scope( $item['scope'], static fn(): ?string => Fonts::read( $stored ) ) : $read( $stored );
			if ( null !== $bytes ) {
				$zip->addFromString( $path, $bytes );
			}
		}
		$zip->close();
		return $zip_path;
	}
}
