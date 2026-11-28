<?php
/**
 * Fonts from Google Fonts or Bunny Fonts, downloaded once by this server on an explicit click and kept as local files
 * (Style Mermaid). Visitors' browsers never contact those services: previews and variations use the local copies.
 *
 * - Who may: library managers always (their click is the owner's consent); visitors of a public Style Mermaid block
 *   only where the site's second switch ("Fonts from Google Fonts and Bunny Fonts") is on, within the rate limit.
 * - Requests: the provider's CSS (`https://fonts.googleapis.com/css`, `https://fonts.bunny.net/css`) with a current
 *   browser's user agent (so the answer lists WOFF2 files split by script), then each WOFF2 file, only from the
 *   provider's own file host, over HTTPS, without redirects, through `wp_safe_remote_get()` (so Helm's blocked hosts
 *   apply). Every file is checked by its first bytes and size.
 * - Kept: only the scripts asked for (Latin with its extensions, Vietnamese, Cyrillic and Greek for a Latin family;
 *   one script's subsets for a script family), at most 40 files per family.
 * - Cache: `windrose-livery/cache/` in the library site's uploads, indexed by the option `windrose_livery_font_cache`
 *   on the library site; at most 150 MB, the oldest families go first. Each family carries a LICENSE.txt naming its
 *   source and license page (the services send no license text).
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
 * Remote fonts.
 *
 * @phpstan-type RemoteFace array{fontFamily: string, fontStyle: string, fontWeight: string, unicodeRange: string, subset: string, stored: string}
 * @phpstan-type Entry array{key: string, provider: string, query: string, name: string, slug: string, weights: list<int>, italic: bool, subsets: list<string>, faces: list<RemoteFace>, license: string, bytes: int, time: int}
 */
final class Remote_Fonts {

	public const OPTION    = 'windrose_livery_font_cache';
	public const FOLDER    = 'windrose-livery/cache';
	public const MAX_FILES = 40;
	public const MAX_FILE  = 5242880;
	public const MAX_CACHE = 157286400;
	public const MAX_CSS   = 524288;

	/**
	 * Subsets kept for a Latin family.
	 */
	public const LATIN = array( 'latin', 'latin-ext', 'vietnamese', 'cyrillic', 'cyrillic-ext', 'greek', 'greek-ext' );

	/**
	 * A current browser, so the services answer with WOFF2 files and unicode ranges.
	 */
	private const AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36';

	/**
	 * Providers: CSS address, file host, specimen page.
	 */
	private const PROVIDERS = array(
		'google' => array(
			'css'   => 'https://fonts.googleapis.com/css',
			'hosts' => array( 'fonts.googleapis.com' ),
			'files' => 'fonts.gstatic.com',
			'page'  => 'https://fonts.google.com/specimen/%s/license',
		),
		'bunny'  => array(
			'css'   => 'https://fonts.bunny.net/css',
			'hosts' => array( 'fonts.bunny.net' ),
			'files' => 'fonts.bunny.net',
			'page'  => 'https://fonts.bunny.net/family/%s',
		),
	);

	/**
	 * Understands a family name, or an address copied from either service.
	 *
	 * @param string $input    Name or address.
	 * @param string $provider Provider when a name is given.
	 * @return array{provider: string, family: string}|WP_Error
	 */
	public static function understand( string $input, string $provider ) {
		$input = trim( $input );
		if ( 1 === preg_match( '#^(https?:)?//|^[a-z0-9.-]+\.(com|net)/#i', $input ) ) {
			$found = self::from_address( str_starts_with( strtolower( $input ), 'http' ) ? $input : 'https://' . ltrim( $input, '/' ) );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			$provider = $found['provider'];
			$input    = $found['family'];
		}
		if ( ! isset( self::PROVIDERS[ $provider ] ) ) {
			return new WP_Error( 'windrose_livery_font_provider', __( 'Choose Google Fonts or Bunny Fonts.', 'windrose-livery' ), array( 'status' => 400 ) );
		}
		$family = trim( (string) preg_replace( '/\s+/', ' ', $input ) );
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9 ]{0,59}$/', $family ) ) {
			return new WP_Error( 'windrose_livery_font_name', __( 'A font name has letters, digits and spaces only, for example “Noto Sans Arabic”.', 'windrose-livery' ), array( 'status' => 400 ) );
		}
		return array(
			'provider' => $provider,
			'family'   => $family,
		);
	}

	/**
	 * The family named by an address copied from either service: a font's page or a CSS address.
	 *
	 * | Address                                                   | Service      |
	 * |-----------------------------------------------------------|--------------|
	 * | `https://fonts.google.com/specimen/Noto+Sans+Arabic`      | Google Fonts |
	 * | `https://fonts.google.com/share?selection.family=Lora:…`  | Google Fonts |
	 * | `https://fonts.googleapis.com/css2?family=Lora:wght@400`  | Google Fonts |
	 * | `https://fonts.bunny.net/family/unica-one`                | Bunny Fonts  |
	 * | `https://fonts.bunny.net/css?family=unica-one:400`        | Bunny Fonts  |
	 *
	 * @param string $url Address.
	 * @return array{provider: string, family: string}|WP_Error
	 */
	private static function from_address( string $url ) {
		$parts = wp_parse_url( $url );
		$host  = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
		$path  = is_array( $parts ) ? rawurldecode( (string) ( $parts['path'] ?? '' ) ) : '';
		$query = is_array( $parts ) ? (string) ( $parts['query'] ?? '' ) : '';
		$wrong = new WP_Error( 'windrose_livery_font_address', __( 'Paste the address of a font’s page on Google Fonts (fonts.google.com/specimen/…) or Bunny Fonts (fonts.bunny.net/family/…), or simply type the font’s name.', 'windrose-livery' ), array( 'status' => 400 ) );
		if ( ! is_array( $parts ) ) {
			return $wrong;
		}
		// Only the name is read from the address; the address itself is never requested.
		$family   = '';
		$provider = '';
		if ( 'fonts.google.com' === $host ) {
			$provider = 'google';
			if ( 1 === preg_match( '#^/specimen/([^/]+)#', $path, $m ) ) {
				$family = $m[1];
			} elseif ( 1 === preg_match( '/(?:^|&)selection\.family=([^&]+)/', $query, $m ) ) {
				$family = $m[1];
			}
		} elseif ( 'fonts.bunny.net' === $host && 1 === preg_match( '#^/family/([a-z0-9-]+)#i', $path, $m ) ) {
			$provider = 'bunny';
			$family   = ucwords( str_replace( '-', ' ', $m[1] ) );
		} elseif ( in_array( $host, array( 'fonts.googleapis.com', 'fonts.bunny.net' ), true ) ) {
			$provider = 'fonts.googleapis.com' === $host ? 'google' : 'bunny';
			// "family" can appear several times (css2) or hold several families separated by "|" (css): the first one.
			if ( 1 === preg_match( '/(?:^|&)family=([^&]+)/', $query, $m ) ) {
				$family = $m[1];
				if ( 'bunny' === $provider ) {
					$family = ucwords( str_replace( '-', ' ', explode( ':', rawurldecode( $family ) )[0] ) );
				}
			}
		} else {
			return $wrong;
		}//end if
		$family = explode( '|', rawurldecode( str_replace( '+', ' ', $family ) ) )[0];
		$family = trim( explode( ':', $family )[0] );
		if ( '' === $family ) {
			return new WP_Error( 'windrose_livery_font_address', __( 'This address names no font family. Paste the address of the font’s own page, or type its name.', 'windrose-livery' ), array( 'status' => 400 ) );
		}
		return array(
			'provider' => $provider,
			'family'   => $family,
		);
	}

	/**
	 * A family, from the cache or downloaded now.
	 *
	 * @param string $provider google or bunny.
	 * @param string $family   Family name.
	 * @param array  $weights  Weights.
	 * @phpstan-param list<int> $weights
	 * @param bool   $italic   Also italic faces.
	 * @param array  $subsets Subsets to keep.
	 * @phpstan-param list<string> $subsets
	 * @return Entry|WP_Error
	 */
	public static function fetch( string $provider, string $family, array $weights, bool $italic, array $subsets ) {
		$weights = self::weights( $weights );
		$subsets = self::subsets( $subsets );
		$key     = self::key( $provider, $family, $weights, $italic, $subsets );

		$cached = self::get( $key );
		if ( null !== $cached ) {
			return $cached;
		}
		$info = self::PROVIDERS[ $provider ] ?? null;
		if ( null === $info ) {
			return new WP_Error( 'windrose_livery_font_provider', __( 'Choose Google Fonts or Bunny Fonts.', 'windrose-livery' ), array( 'status' => 400 ) );
		}
		$variants = array();
		foreach ( $weights as $weight ) {
			$variants[] = (string) $weight;
			if ( $italic ) {
				$variants[] = $weight . 'i';
			}
		}
		$name = 'bunny' === $provider ? strtolower( str_replace( ' ', '-', $family ) ) : str_replace( ' ', '+', $family );
		$url  = $info['css'] . '?family=' . $name . ':' . implode( ',', $variants ) . '&display=swap';
		$css  = self::get_body( $url, self::MAX_CSS );
		if ( is_wp_error( $css ) ) {
			return $css;
		}
		$faces = self::parse_css( $css, $info['files'] );
		$faces = array_values( array_filter( $faces, static fn( array $face ): bool => in_array( $face['subset'], $subsets, true ) ) );
		if ( array() === $faces ) {
			/* translators: %s: font family name. */
			return new WP_Error( 'windrose_livery_font_empty', sprintf( __( '%s has no font files for these letters there.', 'windrose-livery' ), $family ), array( 'status' => 404 ) );
		}
		if ( count( $faces ) > self::MAX_FILES ) {
			return new WP_Error( 'windrose_livery_font_many', __( 'This font would need more than 40 files: choose fewer weights.', 'windrose-livery' ), array( 'status' => 400 ) );
		}

		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir['path'] ) ) {
			return new WP_Error( 'windrose_livery_folder', __( 'The fonts folder could not be created in the uploads folder.', 'windrose-livery' ), array( 'status' => 500 ) );
		}
		$slug   = sanitize_title( $faces[0]['fontFamily'] );
		$stored = array();
		$bytes  = 0;
		$out    = array();
		foreach ( $faces as $face ) {
			$body = self::get_body( $face['url'], self::MAX_FILE );
			if ( is_wp_error( $body ) ) {
				self::delete_files( $stored );
				return $body;
			}
			$file  = $slug . '-' . $face['subset'] . '-' . $face['fontWeight'] . '-' . $face['fontStyle'] . '.woff2';
			$check = Fonts::check_font( $file, $body );
			if ( is_wp_error( $check ) ) {
				self::delete_files( $stored );
				$check->add_data( array( 'status' => 502 ) );
				return $check;
			}
			$name = Fonts::stored_name( $file, $body );
			if ( ! self::write( $name, $body ) ) {
				self::delete_files( $stored );
				return new WP_Error( 'windrose_livery_write', __( 'A font file could not be written in the uploads folder.', 'windrose-livery' ), array( 'status' => 500 ) );
			}
			$stored[] = $name;
			$bytes   += strlen( $body );
			unset( $face['url'] );
			$face['stored'] = $name;
			$out[]          = $face;
		}//end foreach
		$label    = 'google' === $provider ? 'Google Fonts' : 'Bunny Fonts';
		$page     = sprintf( $info['page'], 'google' === $provider ? str_replace( ' ', '+', $out[0]['fontFamily'] ) : $slug );
		$license  = sprintf(
			"%1\$s\n\nDownloaded from %2\$s on %3\$s for a style variation made with Windrose Livery.\nFonts on Google Fonts and Bunny Fonts are published under open-source licenses, most of them the SIL Open Font License 1.1 (https://openfontlicense.org).\nThe license of this family: %4\$s\n",
			$out[0]['fontFamily'],
			$label,
			gmdate( 'Y-m-d' ),
			$page
		);
		$lic_name = Fonts::stored_name( 'LICENSE.txt', $license );
		self::write( $lic_name, $license );

		$entry = array(
			'key'      => $key,
			'provider' => $provider,
			'query'    => $family,
			'name'     => $out[0]['fontFamily'],
			'slug'     => $slug,
			'weights'  => $weights,
			'italic'   => $italic,
			'subsets'  => array_values( array_unique( array_column( $out, 'subset' ) ) ),
			'faces'    => $out,
			'license'  => $lic_name,
			'bytes'    => $bytes,
			'time'     => time(),
		);
		self::save( $entry );
		return $entry;
	}

	/**
	 * The cache key of a request.
	 *
	 * @param string $provider Provider.
	 * @param string $family   Family name.
	 * @param array  $weights  Weights.
	 * @phpstan-param list<int> $weights
	 * @param bool   $italic   Italic too.
	 * @param array  $subsets  Subsets.
	 * @phpstan-param list<string> $subsets
	 * @return string
	 */
	public static function key( string $provider, string $family, array $weights, bool $italic, array $subsets ): string {
		return substr( hash( 'sha256', implode( '|', array( $provider, strtolower( $family ), implode( ',', self::weights( $weights ) ), $italic ? 'i' : '', implode( ',', self::subsets( $subsets ) ) ) ) ), 0, 20 );
	}

	/**
	 * Weights 100 to 900, sorted (default 400 and 700).
	 *
	 * @param array $weights Weights.
	 * @phpstan-param list<int> $weights
	 * @return list<int>
	 */
	private static function weights( array $weights ): array {
		$weights = array_values( array_unique( array_filter( array_map( 'intval', $weights ), static fn( int $w ): bool => $w >= 100 && $w <= 900 && 0 === $w % 100 ) ) );
		sort( $weights );
		return array() === $weights ? array( 400, 700 ) : $weights;
	}

	/**
	 * Subset names, sorted.
	 *
	 * @param array $subsets Subsets.
	 * @phpstan-param list<string> $subsets
	 * @return list<string>
	 */
	private static function subsets( array $subsets ): array {
		$subsets = array_values( array_unique( array_filter( $subsets, static fn( $s ): bool => is_string( $s ) && 1 === preg_match( '/^[a-z-]{2,30}$/', $s ) ) ) );
		sort( $subsets );
		return $subsets;
	}

	/**
	 * Font faces of a provider's CSS, with their subset (from the comment before each rule).
	 *
	 * @param string $css   CSS.
	 * @param string $files The provider's file host.
	 * @return list<array{fontFamily: string, fontStyle: string, fontWeight: string, unicodeRange: string, subset: string, url: string}>
	 */
	public static function parse_css( string $css, string $files ): array {
		$out = array();
		if ( false === preg_match_all( '#/\*\s*([a-z0-9-]+)\s*\*/\s*@font-face\s*\{([^}]*)\}#i', $css, $rules, PREG_SET_ORDER ) ) {
			return $out;
		}
		foreach ( $rules as $rule ) {
			$body   = $rule[2];
			$get    = static function ( string $prop ) use ( $body ): string {
				return 1 === preg_match( '/(?:^|[;\s])' . $prop . '\s*:\s*([^;]+);/i', $body, $m ) ? trim( $m[1] ) : '';
			};
			$family = trim( $get( 'font-family' ), "'\" " );
			$style  = $get( 'font-style' );
			$weight = $get( 'font-weight' );
			$range  = (string) preg_replace( '/\s*,\s*/', ', ', $get( 'unicode-range' ) );
			if ( 1 !== preg_match( '/src\s*:\s*url\(\s*[\'"]?(https:\/\/[^)\'"\s]+\.woff2)[\'"]?\s*\)\s*format\(\s*[\'"]woff2[\'"]\s*\)/i', $body, $src ) ) {
				continue;
			}
			$host = strtolower( (string) wp_parse_url( $src[1], PHP_URL_HOST ) );
			if ( $host !== $files
				|| 1 !== preg_match( '/^[A-Za-z0-9 ]{1,60}$/', $family )
				|| ! in_array( $style, array( 'normal', 'italic' ), true )
				|| 1 !== preg_match( '/^\d{3}( \d{3})?$/', $weight )
				|| ( '' !== $range && 1 !== preg_match( '/^[Uu]\+[0-9A-Fa-f?]{1,6}(-[0-9A-Fa-f]{1,6})?(, [Uu]\+[0-9A-Fa-f?]{1,6}(-[0-9A-Fa-f]{1,6})?)*$/', $range ) ) ) {
				continue;
			}
			$out[] = array(
				'fontFamily'   => $family,
				'fontStyle'    => $style,
				'fontWeight'   => $weight,
				'unicodeRange' => $range,
				'subset'       => strtolower( $rule[1] ),
				'url'          => $src[1],
			);
		}//end foreach
		return $out;
	}

	/**
	 * A cached family.
	 *
	 * @param string $key Key.
	 * @return Entry|null
	 */
	public static function get( string $key ): ?array {
		$index = self::index();
		if ( ! isset( $index[ $key ] ) ) {
			return null;
		}
		$entry = $index[ $key ];
		foreach ( $entry['faces'] as $face ) {
			if ( ! is_file( self::path( $face['stored'] ) ) ) {
				return null;
			}
		}
		return $entry;
	}

	/**
	 * Path of a cached file ('' for a bad name).
	 *
	 * @param string $stored Stored name.
	 * @return string
	 */
	public static function path( string $stored ): string {
		return Fonts::valid_name( $stored ) ? self::dir()['path'] . '/' . $stored : '';
	}

	/**
	 * Address of a cached file.
	 *
	 * @param string $stored Stored name.
	 * @return string
	 */
	public static function url( string $stored ): string {
		return Fonts::valid_name( $stored ) ? self::dir()['url'] . '/' . rawurlencode( $stored ) : '';
	}

	/**
	 * Content of a cached file.
	 *
	 * @param string $stored Stored name.
	 * @return string|null
	 */
	public static function read( string $stored ): ?string {
		$path = self::path( $stored );
		return '' === $path ? null : Files::read( $path );
	}

	/**
	 * The cache folder (next to the library's fonts folder).
	 *
	 * @return array{path: string, url: string}
	 */
	public static function dir(): array {
		$base = Fonts::network_dir();
		return array(
			'path' => $base['path'] . '/cache',
			'url'  => $base['url'] . '/cache',
		);
	}

	/**
	 * Deletes one cached family (files another family uses stay).
	 *
	 * @param string $key Key.
	 * @return void
	 */
	public static function forget( string $key ): void {
		$index = self::index();
		if ( ! isset( $index[ $key ] ) ) {
			return;
		}
		$gone = $index[ $key ];
		unset( $index[ $key ] );
		$used = array();
		foreach ( $index as $one ) {
			foreach ( $one['faces'] as $face ) {
				$used[ $face['stored'] ] = true;
			}
			$used[ $one['license'] ] = true;
		}
		$files = array_merge( array_column( $gone['faces'], 'stored' ), array( $gone['license'] ) );
		self::delete_files( array_values( array_filter( $files, static fn( string $f ): bool => ! isset( $used[ $f ] ) ) ) );
		Library::on_network_site( static fn() => update_option( self::OPTION, $index, false ) );
	}

	/**
	 * Keys of the cached families.
	 *
	 * @return list<string>
	 */
	public static function keys(): array {
		return array_keys( self::index() );
	}

	/**
	 * Deletes every cached family (uninstall, tests).
	 *
	 * @return void
	 */
	public static function clear(): void {
		foreach ( self::index() as $entry ) {
			self::delete_files( array_merge( array_column( $entry['faces'], 'stored' ), array( $entry['license'] ) ) );
		}
		Library::on_network_site( static fn() => delete_option( self::OPTION ) );
	}

	/**
	 * The cache index.
	 *
	 * @return array<string, Entry>
	 */
	private static function index(): array {
		$index = Library::on_network_site( static fn() => get_option( self::OPTION, array() ) );
		/**
		 * Decoded and checked above.
		 *
		 * @var array<string, Entry>
		 */
		return is_array( $index ) ? $index : array();
	}

	/**
	 * Adds an entry, then removes the oldest ones beyond the size limit (files another entry uses stay).
	 *
	 * @param array $entry Entry.
	 * @phpstan-param Entry $entry
	 * @return void
	 */
	private static function save( array $entry ): void {
		$index                  = self::index();
		$index[ $entry['key'] ] = $entry;
		uasort( $index, static fn( array $a, array $b ): int => $b['time'] <=> $a['time'] );
		$total = 0;
		$keep  = array();
		$drop  = array();
		foreach ( $index as $key => $one ) {
			$total += $one['bytes'];
			if ( $total > self::MAX_CACHE && $key !== $entry['key'] ) {
				$drop[] = $one;
			} else {
				$keep[ $key ] = $one;
			}
		}
		$used = array();
		foreach ( $keep as $one ) {
			foreach ( $one['faces'] as $face ) {
				$used[ $face['stored'] ] = true;
			}
			$used[ $one['license'] ] = true;
		}
		foreach ( $drop as $one ) {
			$files = array_merge( array_column( $one['faces'], 'stored' ), array( $one['license'] ) );
			self::delete_files( array_values( array_filter( $files, static fn( string $f ): bool => ! isset( $used[ $f ] ) ) ) );
		}
		// Files no entry names (two fetches at once, or a request stopped halfway), once no fetch can still be writing them.
		foreach ( (array) glob( self::dir()['path'] . '/*' ) as $path ) {
			if ( is_string( $path ) && is_file( $path ) && ! isset( $used[ wp_basename( $path ) ] ) && filemtime( $path ) < time() - HOUR_IN_SECONDS ) {
				self::delete_files( array( wp_basename( $path ) ) );
			}
		}
		Library::on_network_site( static fn() => update_option( self::OPTION, $keep, false ) );
	}

	/**
	 * Writes a file into the cache (same name = same content: kept).
	 *
	 * @param string $name  Stored name.
	 * @param string $bytes Content.
	 * @return bool
	 */
	private static function write( string $name, string $bytes ): bool {
		$path = self::path( $name );
		if ( '' === $path ) {
			return false;
		}
		if ( is_file( $path ) ) {
			return true;
		}
		return Files::write( $path, $bytes );
	}

	/**
	 * Deletes cached files.
	 *
	 * @param array $names Stored names.
	 * @phpstan-param list<string> $names
	 * @return void
	 */
	private static function delete_files( array $names ): void {
		foreach ( $names as $name ) {
			$path = self::path( $name );
			if ( '' !== $path && is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * GET over HTTPS, no redirects, size limit, through WordPress's safe HTTP API.
	 *
	 * @param string $url Address.
	 * @param int    $max Size limit.
	 * @return string|WP_Error
	 */
	private static function get_body( string $url, int $max ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'user-agent'          => self::AGENT,
				'limit_response_size' => $max + 1,
				'headers'             => array( 'Accept' => 'text/css,font/woff2,*/*;q=0.1' ),
			)
		);
		$host     = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( is_wp_error( $response ) ) {
			/* translators: 1: host name, 2: error message. */
			return new WP_Error( 'windrose_livery_font_http', sprintf( __( '%1$s could not be reached: %2$s', 'windrose-livery' ), $host, $response->get_error_message() ), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( 400 === $code || 404 === $code ) {
			return new WP_Error( 'windrose_livery_font_unknown', __( 'This font was not found there. Check its name as the service writes it.', 'windrose-livery' ), array( 'status' => 404 ) );
		}
		if ( 200 !== $code || '' === $body || strlen( $body ) > $max ) {
			/* translators: 1: host name, 2: HTTP status code. */
			return new WP_Error( 'windrose_livery_font_http', sprintf( __( '%1$s answered with an error (%2$d).', 'windrose-livery' ), $host, $code ), array( 'status' => 502 ) );
		}
		return $body;
	}
}
