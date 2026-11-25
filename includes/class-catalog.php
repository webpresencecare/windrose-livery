<?php
/**
 * Style Mermaid's bundled data (built by `bin/mermaid-data.py`, `scripts.json` edited by hand):
 *
 * | File                          | Holds                                                                     |
 * |-------------------------------|---------------------------------------------------------------------------|
 * | `assets/fonts/<slug>/`        | Latin font files (WOFF2, SIL Open Font License) and their license texts   |
 * | `assets/mermaid/fonts.json`   | Those families: name, category, generic family, coverage, faces           |
 * | `assets/mermaid/styles.json`  | Ready styles (the design kit's variations, and Ipsum), fonts by role      |
 * | `assets/mermaid/palettes.json`| Ipsum's own color presets                                                 |
 * | `assets/mermaid/scripts.json` | Font pairs for other scripts, and fonts for one language of a script      |
 * | `assets/mermaid/packs.json`   | What each font pack holds (`Packs`; written by `bin/font-packs.py`)       |
 * | `assets/mermaid/ready-recipes.json` | Ready styles as recipes, for other plugins (written by `bin/ready-recipes.mjs`) |
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bundled fonts, ready styles, palettes and script pairs.
 *
 * @phpstan-type Face array{file: string, fontWeight: string, fontStyle: string}
 * @phpstan-type Font array{slug: string, name: string, category: string, generic: string, covers: list<string>, faces: list<Face>}
 * @phpstan-type Script array{slug: string, lang: string, subsets?: list<string>, dir: string, sample: string, system?: bool, onlyMissing?: bool, pairs?: list<array{heading: string, text: string}>, languages?: list<array{tag: string, fonts: list<string>, sample: string}>}
 */
final class Catalog {

	/**
	 * Families every variation lists, as Ipsum's own typesets do (its blocks and patterns name them).
	 */
	public const ALWAYS = array( 'manrope', 'jetbrains-mono' );

	/**
	 * Decoded files.
	 *
	 * @var array<string, array<mixed>>
	 */
	private static array $data = array();

	/**
	 * A decoded data file.
	 *
	 * @param string $name File name in assets/mermaid.
	 * @return array<mixed>
	 */
	private static function load( string $name ): array {
		if ( ! isset( self::$data[ $name ] ) ) {
			$path                = WINDROSE_LIVERY_DIR . '/assets/mermaid/' . $name . '.json';
			$decoded             = is_readable( $path ) ? wp_json_file_decode( $path, array( 'associative' => true ) ) : null;
			self::$data[ $name ] = is_array( $decoded ) ? $decoded : array();
		}
		return self::$data[ $name ];
	}

	/**
	 * The bundled font families.
	 *
	 * @return list<Font>
	 */
	public static function fonts(): array {
		/**
		 * Decoded and checked above.
		 *
		 * @var list<Font>
		 */
		return array_values( self::load( 'fonts' ) );
	}

	/**
	 * One bundled family.
	 *
	 * @param string $slug Slug.
	 * @return Font|null
	 */
	public static function font( string $slug ): ?array {
		foreach ( self::fonts() as $font ) {
			if ( $font['slug'] === $slug ) {
				return $font;
			}
		}
		return null;
	}

	/**
	 * Ready styles.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function styles(): array {
		/**
		 * Decoded and checked above.
		 *
		 * @var list<array<string, mixed>>
		 */
		return array_values( self::load( 'styles' ) );
	}

	/**
	 * The ready styles as the recipes Style Mermaid sends when one is picked unchanged (0.7): for other plugins, such
	 * as Windrose Knotboard, which preview patterns in a ready style through `Patterns` without Style Mermaid's code.
	 *
	 * @return list<array{slug: string, title: string, description: string, recipe: array<string, mixed>}>
	 */
	public static function ready_recipes(): array {
		$list = array();
		foreach ( self::load( 'ready-recipes' ) as $item ) {
			if ( is_array( $item ) && is_string( $item['slug'] ?? null ) && is_string( $item['title'] ?? null ) && is_array( $item['recipe'] ?? null ) ) {
				$list[] = array(
					'slug'        => $item['slug'],
					'title'       => $item['title'],
					'description' => is_string( $item['description'] ?? null ) ? $item['description'] : '',
					'recipe'      => $item['recipe'],
				);
			}
		}
		return $list;
	}

	/**
	 * Ipsum's color presets.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function palettes(): array {
		/**
		 * Decoded and checked above.
		 *
		 * @var list<array<string, mixed>>
		 */
		return array_values( self::load( 'palettes' ) );
	}

	/**
	 * Scripts and their font pairs.
	 *
	 * @return list<Script>
	 */
	public static function scripts(): array {
		/**
		 * Decoded and checked above.
		 *
		 * @var list<Script>
		 */
		return array_values( self::load( 'scripts' ) );
	}

	/**
	 * One script.
	 *
	 * @param string $slug Slug.
	 * @return Script|null
	 */
	public static function script( string $slug ): ?array {
		foreach ( self::scripts() as $script ) {
			if ( $script['slug'] === $slug ) {
				return $script;
			}
		}
		return null;
	}

	/**
	 * Path of a bundled file, or empty when the name is not one of the family's files.
	 *
	 * @param string $slug Family slug.
	 * @param string $file File name.
	 * @return string
	 */
	public static function path( string $slug, string $file ): string {
		$font = self::font( $slug );
		if ( null === $font || ! in_array( $file, array_merge( array_column( $font['faces'], 'file' ), self::licenses( $slug ) ), true ) ) {
			return '';
		}
		return WINDROSE_LIVERY_DIR . '/assets/fonts/' . $slug . '/' . $file;
	}

	/**
	 * Address of a bundled file.
	 *
	 * @param string $slug Family slug.
	 * @param string $file File name.
	 * @return string
	 */
	public static function url( string $slug, string $file ): string {
		return plugins_url( 'assets/fonts/' . rawurlencode( $slug ) . '/' . rawurlencode( $file ), WINDROSE_LIVERY_FILE );
	}

	/**
	 * License files of a bundled family.
	 *
	 * @param string $slug Family slug.
	 * @return list<string>
	 */
	public static function licenses( string $slug ): array {
		$dir = WINDROSE_LIVERY_DIR . '/assets/fonts/' . $slug;
		if ( 1 !== preg_match( '/^[a-z0-9-]+$/', $slug ) || ! is_dir( $dir ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( is_string( $name ) && 1 === preg_match( '/^(OFL|LICEN[CS]E)[A-Za-z0-9._-]*$/i', $name ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Font-face rules for every bundled family (samples in the font picker; normal faces only).
	 *
	 * @return string
	 */
	public static function sample_css(): string {
		$families = array();
		foreach ( self::fonts() as $font ) {
			$faces = array();
			foreach ( $font['faces'] as $face ) {
				if ( 'normal' === $face['fontStyle'] ) {
					$faces[] = array(
						'fontFamily'  => $font['name'],
						'fontWeight'  => $face['fontWeight'],
						'fontStyle'   => 'normal',
						'fontDisplay' => 'swap',
						'src'         => array( self::url( $font['slug'], $face['file'] ) ),
					);
				}
			}
			$families[] = array( 'fontFace' => $faces );
		}
		return Fonts::css( $families );
	}

	/**
	 * What the browser gets: families with addresses, styles, palettes, scripts.
	 *
	 * @return array<string, mixed>
	 */
	public static function for_browser(): array {
		$fonts = array();
		foreach ( self::fonts() as $font ) {
			$fonts[] = array(
				'slug'     => $font['slug'],
				'name'     => $font['name'],
				'category' => $font['category'],
				'generic'  => $font['generic'],
				'covers'   => $font['covers'],
				'italic'   => in_array( 'italic', array_column( $font['faces'], 'fontStyle' ), true ),
				'weights'  => implode( ', ', array_unique( array_column( $font['faces'], 'fontWeight' ) ) ),
			);
		}
		// The scope's Font Library (installed in the Site Editor), for managers only.
		$library = Library::can_manage() ? Font_Library::installed() : array();
		$faces   = array();
		foreach ( $library as $family ) {
			foreach ( $family['faces'] as $face ) {
				if ( 'normal' === $face['fontStyle'] ) {
					$faces[] = array(
						'fontFamily'   => $family['name'],
						'fontWeight'   => $face['fontWeight'],
						'fontStyle'    => 'normal',
						'fontDisplay'  => 'swap',
						'unicodeRange' => $face['unicodeRange'],
						'src'          => array( $face['src'] ),
					);
				}
			}
		}
		return array(
			'fonts'    => $fonts,
			'library'  => array_map( static fn( array $family ): array => array_diff_key( $family, array( 'faces' => true ) ), $library ),
			'css'      => self::sample_css() . Fonts::css( array( array( 'fontFace' => $faces ) ) ),
			'styles'   => self::styles(),
			'palettes' => self::palettes(),
			'scripts'  => self::scripts(),
			'packs'    => Packs::for_browser(),
		);
	}
}
