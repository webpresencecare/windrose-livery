<?php
/**
 * Font packs: the fonts of Style Mermaid's pairs for one script (Arabic, Hebrew, Devanagari...), cut down to that
 * script, installed once and shared by every style. They need neither Google Fonts nor Bunny Fonts.
 *
 * - Built by `bin/font-packs.py` from the google/fonts repository (SIL Open Font License) and published with each
 *   Livery release as `windrose-livery-fonts-<script>.zip` (`.github/workflows/release.yml`).
 * - Installed by a library manager: "Install" (the Livery release that built the packs on GitHub, through Windrose Common's
 *   `Updater::release_asset()`: GitHub's published sha256 checked), or "Upload a pack" (the same zip, for sites that cannot reach GitHub).
 * - Checked like an import: read in memory by name, never extracted; `pack.json` (format 1) names only the families
 *   Style Mermaid offers for that script; faces checked with the import's rules; WOFF2 files by their first bytes,
 *   license texts as plain text; at most 300 entries, 30 MB packed, 60 MB unpacked.
 * - Stored in `windrose-livery/packs/<script>/<family>/<file>` in the library site's uploads; index: option
 *   `windrose_livery_packs` on the library site. A style made with a pack keeps its own copies of the files (in
 *   the library site's Font Library), so removing a pack never breaks a style.
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

use Windrose\Common\Files;
use Windrose\Common\Updater;
use WP_Error;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Font packs.
 *
 * @phpstan-type PackFace array{file: string, fontWeight: string, fontStyle: string, unicodeRange: string}
 * @phpstan-type PackFamily array{name: string, slug: string, generic: string, sizeAdjust: string, license: string, faces: list<PackFace>}
 * @phpstan-type Installed array{script: string, livery: string, source: string, time: int, bytes: int, families: list<PackFamily>}
 */
final class Packs {

	public const OPTION       = 'windrose_livery_packs';
	public const FOLDER       = 'packs';
	public const PREFIX       = 'windrose-livery-fonts-';
	public const FORMAT       = 1;
	public const MAX_ZIP      = 31457280;
	public const MAX_UNPACKED = 62914560;
	public const MAX_ENTRIES  = 300;
	public const MAX_JSON     = 204800;

	/**
	 * What this version's packs hold, before installing (`assets/mermaid/packs.json`, written by the build).
	 *
	 * @return array<string, array{file: string, bytes: int, families: list<array{name: string, slug: string, generic: string, sizeAdjust: string}>}>
	 */
	public static function available(): array {
		$path    = WINDROSE_LIVERY_DIR . '/assets/mermaid/packs.json';
		$decoded = is_readable( $path ) ? wp_json_file_decode( $path, array( 'associative' => true ) ) : null;
		$packs   = is_array( $decoded ) && is_array( $decoded['packs'] ?? null ) ? $decoded['packs'] : array();
		/**
		 * Written by bin/font-packs.py.
		 *
		 * @var array<string, array{file: string, bytes: int, families: list<array{name: string, slug: string, generic: string, sizeAdjust: string}>}> $packs
		 */
		return $packs;
	}

	/**
	 * The Livery release whose GitHub release carries the packs (`version` in `assets/mermaid/packs.json`): the
	 * release that built them. Later releases keep pointing to it until the fonts change, so a release builds the
	 * packs only when its fonts are new.
	 *
	 * @return string Version (x.y.z).
	 */
	public static function release(): string {
		$path    = WINDROSE_LIVERY_DIR . '/assets/mermaid/packs.json';
		$decoded = is_readable( $path ) ? wp_json_file_decode( $path, array( 'associative' => true ) ) : null;
		$version = is_array( $decoded ) && is_string( $decoded['version'] ?? null ) ? $decoded['version'] : '';
		return 1 === preg_match( '/^\d+\.\d+\.\d+$/', $version ) ? $version : WINDROSE_LIVERY_VERSION;
	}

	/**
	 * Installed packs, by script.
	 *
	 * @return array<string, Installed>
	 */
	public static function installed(): array {
		$value = Library::on_network_site( static fn() => get_option( self::OPTION, array() ) );
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $script => $pack ) {
			if ( is_string( $script ) && is_array( $pack ) && is_array( $pack['families'] ?? null ) ) {
				/**
				 * Written by install_file() only.
				 *
				 * @var Installed $pack
				 */
				$out[ $script ] = $pack;
			}
		}
		return $out;
	}

	/**
	 * An installed family.
	 *
	 * @param string $script Script slug.
	 * @param string $slug   Family slug.
	 * @return PackFamily|null
	 */
	public static function find_family( string $script, string $slug ): ?array {
		foreach ( self::installed()[ $script ]['families'] ?? array() as $family ) {
			if ( $family['slug'] === $slug ) {
				return $family;
			}
		}
		return null;
	}

	/**
	 * The packs folder (library site's uploads): path and address.
	 *
	 * @return array{path: string, url: string}
	 */
	public static function dir(): array {
		$base = Fonts::network_dir();
		return array(
			'path' => $base['path'] . '/' . self::FOLDER,
			'url'  => $base['url'] . '/' . self::FOLDER,
		);
	}

	/**
	 * Path of an installed pack file (a face or the license of an installed family), or empty.
	 *
	 * @param string $script Script.
	 * @param string $slug   Family slug.
	 * @param string $file   File name.
	 * @return string
	 */
	public static function path( string $script, string $slug, string $file ): string {
		$family = self::find_family( $script, $slug );
		if ( null === $family || ! in_array( $file, array_merge( array_column( $family['faces'], 'file' ), array( $family['license'] ) ), true ) ) {
			return '';
		}
		return self::dir()['path'] . '/' . $script . '/' . $slug . '/' . $file;
	}

	/**
	 * Address of an installed pack file, or empty.
	 *
	 * @param string $script Script.
	 * @param string $slug   Family slug.
	 * @param string $file   File name.
	 * @return string
	 */
	public static function url( string $script, string $slug, string $file ): string {
		return '' === self::path( $script, $slug, $file ) ? '' : self::dir()['url'] . '/' . rawurlencode( $script ) . '/' . rawurlencode( $slug ) . '/' . rawurlencode( $file );
	}

	/**
	 * Reads an installed pack file.
	 *
	 * @param string $script Script.
	 * @param string $slug   Family slug.
	 * @param string $file   File name.
	 * @return string|null
	 */
	public static function read( string $script, string $slug, string $file ): ?string {
		$path  = self::path( $script, $slug, $file );
		$bytes = '' === $path ? null : Files::read( $path );
		return '' !== $bytes ? $bytes : null;
	}

	/**
	 * The family names Style Mermaid offers for a script (its pairs and language fonts).
	 *
	 * @param string $script Script slug.
	 * @return list<string>|null Null for an unknown script or one that uses system fonts.
	 */
	public static function allowed_names( string $script ): ?array {
		$info = Catalog::script( $script );
		if ( null === $info || ! empty( $info['system'] ) ) {
			return null;
		}
		$names = array();
		foreach ( $info['pairs'] ?? array() as $pair ) {
			$names[] = $pair['heading'];
			$names[] = $pair['text'];
		}
		foreach ( $info['languages'] ?? array() as $language ) {
			$names = array_merge( $names, $language['fonts'] );
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Installs a script's pack from the GitHub release that carries the packs (release(); a library manager's click).
	 *
	 * @param string $script Script slug.
	 * @return Installed|WP_Error
	 */
	public static function install_from_github( string $script ) {
		if ( null === self::allowed_names( $script ) ) {
			return new WP_Error( 'windrose_livery_pack_script', __( 'There is no font pack for this script.', 'windrose-livery' ), array( 'status' => 400 ) );
		}
		$file = Updater::release_asset( 'windrose-livery', 'v' . self::release(), self::PREFIX . $script . '.zip', self::MAX_ZIP );
		if ( is_wp_error( $file ) ) {
			$file->add_data( array( 'status' => 502 ) );
			return $file;
		}
		$result = self::install_file( $file, $script );
		wp_delete_file( $file );
		return $result;
	}

	/**
	 * Checks a pack zip and installs it (replacing an installed pack of the same script).
	 *
	 * @param string $path     Zip file.
	 * @param string $expected Script it must be for (empty: any).
	 * @return Installed|WP_Error
	 */
	public static function install_file( string $path, string $expected = '' ) {
		$read = self::read_zip( $path );
		if ( is_wp_error( $read ) ) {
			$read->add_data( array( 'status' => 400 ) );
			return $read;
		}
		if ( '' !== $expected && $read['pack']['script'] !== $expected ) {
			return new WP_Error( 'windrose_livery_pack_script', __( 'This pack is for another script.', 'windrose-livery' ), array( 'status' => 400 ) );
		}
		return Library::on_network_site(
			static function () use ( $read ) {
				$pack   = $read['pack'];
				$script = $pack['script'];
				$base   = self::dir()['path'] . '/' . $script;
				$fs     = Files::direct();
				if ( is_dir( $base ) ) {
					$fs->rmdir( $base, true );
				}
				foreach ( $read['files'] as $relative => $bytes ) {
					$target = $base . '/' . $relative;
					if ( ! wp_mkdir_p( dirname( $target ) ) || ! Files::write( $target, $bytes ) ) {
						$fs->rmdir( $base, true );
						return new WP_Error( 'windrose_livery_pack_write', __( 'The pack could not be written in the uploads folder.', 'windrose-livery' ), array( 'status' => 500 ) );
					}
				}
				$all            = get_option( self::OPTION, array() );
				$all            = is_array( $all ) ? $all : array();
				$all[ $script ] = $pack;
				update_option( self::OPTION, $all, false );
				return $pack;
			}
		);
	}

	/**
	 * Removes an installed pack (styles keep their own copies of the files).
	 *
	 * @param string $script Script.
	 * @return bool Whether one was installed.
	 */
	public static function remove( string $script ): bool {
		if ( ! isset( self::installed()[ $script ] ) || 1 !== preg_match( '/^[a-z-]+$/', $script ) ) {
			return false;
		}
		Library::on_network_site(
			static function () use ( $script ): void {
				$base = self::dir()['path'] . '/' . $script;
				if ( is_dir( $base ) ) {
					Files::direct()->rmdir( $base, true );
				}
				$all = get_option( self::OPTION, array() );
				$all = is_array( $all ) ? $all : array();
				unset( $all[ $script ] );
				update_option( self::OPTION, $all, false );
				$dir = self::dir()['path'];
				if ( is_dir( $dir ) && array() === array_diff( (array) scandir( $dir ), array( '.', '..' ) ) ) {
					Files::direct()->rmdir( $dir );
				}
			}
		);
		return true;
	}

	/**
	 * Every pack folder and the index (uninstall).
	 *
	 * @return void
	 */
	public static function remove_all(): void {
		foreach ( array_keys( self::installed() ) as $script ) {
			self::remove( $script );
		}
		Library::on_network_site( static fn() => delete_option( self::OPTION ) );
	}

	/**
	 * Reads and checks a pack zip: its index and the files it names.
	 *
	 * @param string $path Zip file.
	 * @return array{pack: Installed, files: array<string, string>}|WP_Error Files by path inside the script folder.
	 */
	public static function read_zip( string $path ) {
		$bad = static fn( string $why ): WP_Error => new WP_Error(
			'windrose_livery_pack',
			/* translators: %s: reason. */
			sprintf( __( 'This is not a usable font pack: %s', 'windrose-livery' ), $why )
		);
		if ( ! class_exists( ZipArchive::class ) ) {
			return new WP_Error( 'windrose_livery_zip', __( 'This server cannot read zip files (PHP zip extension missing).', 'windrose-livery' ) );
		}
		$size = is_readable( $path ) ? (int) filesize( $path ) : 0;
		if ( $size <= 0 || $size > self::MAX_ZIP ) {
			return $bad( __( 'empty, or larger than 30 MB.', 'windrose-livery' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) {
			return $bad( __( 'the zip file cannot be read.', 'windrose-livery' ) );
		}
		try {
			if ( $zip->numFiles > self::MAX_ENTRIES ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
				return $bad( __( 'too many files.', 'windrose-livery' ) );
			}
			$entries = array();
			$total   = 0;
			for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
				$stat = $zip->statIndex( $i );
				if ( ! is_array( $stat ) ) {
					continue;
				}
				$total                            += (int) $stat['size'];
				$entries[ (string) $stat['name'] ] = array(
					'index' => $i,
					'size'  => (int) $stat['size'],
				);
			}
			if ( $total > self::MAX_UNPACKED ) {
				return $bad( __( 'larger than 60 MB unpacked.', 'windrose-livery' ) );
			}
			$found = preg_grep( '#^' . preg_quote( self::PREFIX, '#' ) . '[a-z-]+/pack\.json$#', array_keys( $entries ) );
			$roots = false === $found ? array() : array_values( $found );
			if ( 1 !== count( $roots ) ) {
				return $bad( __( 'pack.json is missing.', 'windrose-livery' ) );
			}
			$root  = dirname( $roots[0] );
			$entry = static function ( string $name, int $max ) use ( $zip, $entries ): ?string {
				$one = $entries[ $name ] ?? null;
				if ( null === $one || $one['size'] <= 0 || $one['size'] > $max ) {
					return null;
				}
				$bytes = $zip->getFromIndex( $one['index'], $max + 1 );
				return is_string( $bytes ) && strlen( $bytes ) === $one['size'] ? $bytes : null;
			};
			$json  = $entry( $roots[0], self::MAX_JSON );
			$index = null === $json ? null : json_decode( $json, true, 16 );
			if ( ! is_array( $index ) ) {
				return $bad( __( 'pack.json cannot be read.', 'windrose-livery' ) );
			}
			$script  = is_string( $index['script'] ?? null ) ? $index['script'] : '';
			$allowed = self::allowed_names( $script );
			if ( self::FORMAT !== ( $index['format'] ?? null ) || self::PREFIX . $script !== $root || null === $allowed ) {
				return $bad( __( 'unknown format or script.', 'windrose-livery' ) );
			}
			$families = is_array( $index['families'] ?? null ) && array_is_list( $index['families'] ) ? $index['families'] : array();
			if ( array() === $families || count( $families ) > 20 ) {
				return $bad( __( 'no families, or too many.', 'windrose-livery' ) );
			}
			$files = array();
			$clean = array();
			foreach ( $families as $family ) {
				$one = self::check_family( $family, $allowed, $root, $entry, $files );
				if ( is_wp_error( $one ) ) {
					return $bad( $one->get_error_message() );
				}
				$clean[] = $one;
			}
			$source = is_string( $index['source'] ?? null ) && str_starts_with( $index['source'], 'https://github.com/google/fonts/' ) ? esc_url_raw( $index['source'] ) : '';
			$livery = is_string( $index['livery'] ?? null ) && 1 === preg_match( '/^\d+\.\d+\.\d+$/', $index['livery'] ) ? $index['livery'] : '';
			return array(
				'pack'  => array(
					'script'   => $script,
					'livery'   => $livery,
					'source'   => $source,
					'time'     => time(),
					'bytes'    => array_sum( array_map( 'strlen', $files ) ),
					'families' => $clean,
				),
				'files' => $files,
			);
		} finally {
			$zip->close();
		}//end try
	}

	/**
	 * Checks one family of a pack and collects its files.
	 *
	 * @param mixed                                $family  Family from pack.json.
	 * @param array                                $allowed Names offered for the script.
	 * @phpstan-param list<string> $allowed
	 * @param string                               $root    Root folder in the zip.
	 * @param callable(string, int): (string|null) $entry   Reads an entry.
	 * @param array<string, string>                $files   Files, added to (path inside the script folder => bytes).
	 * @return PackFamily|WP_Error
	 */
	private static function check_family( $family, array $allowed, string $root, callable $entry, array &$files ) {
		$family = is_array( $family ) ? $family : array();
		$name   = is_string( $family['name'] ?? null ) ? $family['name'] : '';
		$slug   = is_string( $family['slug'] ?? null ) ? $family['slug'] : '';
		if ( ! in_array( $name, $allowed, true ) || sanitize_title( $name ) !== $slug ) {
			/* translators: %s: font family name. */
			return new WP_Error( 'windrose_livery_pack_family', sprintf( __( '%s is not a font Style Mermaid offers for this script.', 'windrose-livery' ), sanitize_text_field( $name ) ) );
		}
		$generic = is_string( $family['generic'] ?? null ) && in_array( $family['generic'], Builder::GENERICS, true ) ? $family['generic'] : 'sans-serif';
		$adjust  = is_string( $family['sizeAdjust'] ?? null ) ? $family['sizeAdjust'] : '';
		if ( '' !== $adjust && ( 1 !== preg_match( '/^(\d{2,3})%$/', $adjust, $m ) || (int) $m[1] < 50 || (int) $m[1] > 200 ) ) {
			$adjust = '';
		}
		$license = is_string( $family['license'] ?? null ) ? $family['license'] : '';
		$bytes   = 1 === preg_match( '/^(OFL|LICENSE|UFL)\.txt$/', $license ) ? $entry( $root . '/' . $slug . '/' . $license, Fonts::MAX_LICENSE ) : null;
		if ( null === $bytes || ! Fonts::check_license( $bytes ) ) {
			/* translators: %s: font family name. */
			return new WP_Error( 'windrose_livery_pack_license', sprintf( __( 'the license text of %s is missing.', 'windrose-livery' ), $name ) );
		}
		$files[ $slug . '/' . $license ] = $bytes;
		$faces                           = array();
		foreach ( is_array( $family['faces'] ?? null ) ? array_slice( $family['faces'], 0, 8 ) : array() as $face ) {
			$face = is_array( $face ) ? $face : array();
			$file = is_string( $face['file'] ?? null ) ? $face['file'] : '';
			$one  = array( 'file' => $file );
			foreach ( array( 'fontWeight', 'fontStyle', 'unicodeRange' ) as $key ) {
				$value = Sanitizer::face_value( $key, $face[ $key ] ?? null );
				if ( null === $value ) {
					/* translators: %s: font family name. */
					return new WP_Error( 'windrose_livery_pack_face', sprintf( __( 'a face of %s is not valid.', 'windrose-livery' ), $name ) );
				}
				$one[ $key ] = $value;
			}
			$font = 1 === preg_match( '/^[a-z0-9-]{1,100}\.woff2$/', $file ) ? $entry( $root . '/' . $slug . '/' . $file, Fonts::MAX_FONT ) : null;
			if ( null === $font || is_wp_error( Fonts::check_font( $file, $font ) ) ) {
				/* translators: %s: file name. */
				return new WP_Error( 'windrose_livery_pack_font', sprintf( __( '%s is missing or is not a WOFF2 font.', 'windrose-livery' ), sanitize_file_name( $file ) ) );
			}
			$files[ $slug . '/' . $file ] = $font;
			/**
			 * Checked above.
			 *
			 * @var PackFace $one
			 */
			$faces[] = $one;
		}//end foreach
		if ( array() === $faces ) {
			/* translators: %s: font family name. */
			return new WP_Error( 'windrose_livery_pack_face', sprintf( __( 'a face of %s is not valid.', 'windrose-livery' ), $name ) );
		}
		return array(
			'name'       => $name,
			'slug'       => $slug,
			'generic'    => $generic,
			'sizeAdjust' => $adjust,
			'license'    => $license,
			'faces'      => $faces,
		);
	}

	/**
	 * What Style Mermaid and Livery's screen show: every script with a pack, whether it is installed, its families
	 * (with font-face rules when installed, for the samples).
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function for_browser(): array {
		$installed = self::installed();
		$out       = array();
		foreach ( self::available() as $script => $info ) {
			$pack     = $installed[ $script ] ?? null;
			$families = array();
			foreach ( null === $pack ? $info['families'] : $pack['families'] as $family ) {
				$families[] = array(
					'name'       => $family['name'],
					'slug'       => $family['slug'],
					'generic'    => $family['generic'],
					'sizeAdjust' => $family['sizeAdjust'],
				);
			}
			$out[] = array(
				'script'    => $script,
				'bytes'     => null === $pack ? $info['bytes'] : $pack['bytes'],
				'installed' => null !== $pack,
				'livery'    => null === $pack ? '' : $pack['livery'],
				'time'      => null === $pack ? 0 : $pack['time'],
				'families'  => $families,
				'css'       => null === $pack ? '' : self::css( $script, $pack ),
			);
		}//end foreach
		return $out;
	}

	/**
	 * Font-face rules of an installed pack (normal faces; for samples).
	 *
	 * @param string $script Script.
	 * @param array  $pack   Pack.
	 * @phpstan-param Installed $pack
	 * @return string
	 */
	private static function css( string $script, array $pack ): string {
		$families = array();
		foreach ( $pack['families'] as $family ) {
			$faces = array();
			foreach ( $family['faces'] as $face ) {
				if ( 'normal' === $face['fontStyle'] ) {
					$faces[] = array(
						'fontFamily'   => $family['name'],
						'fontWeight'   => $face['fontWeight'],
						'fontStyle'    => 'normal',
						'fontDisplay'  => 'swap',
						'unicodeRange' => $face['unicodeRange'],
						'src'          => array( self::url( $script, $family['slug'], $face['file'] ) ),
					);
				}
			}
			$families[] = array( 'fontFace' => $faces );
		}
		return Fonts::css( $families );
	}
}
