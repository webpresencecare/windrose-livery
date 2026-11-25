<?php
/**
 * Turns Style Mermaid's choices (a "recipe") into a style variation for Ipsum: font families built here from the
 * bundled catalog and the font cache (never from the browser), then the same checks as an import (`Sanitizer`), the
 * contrast checks (`Contrast`), and either a preview stylesheet, the library (`Importer`) or a zip (`Package`).
 *
 * Recipe (JSON, at most 256 KB):
 *
 * ```
 * {
 *   "title": "…", "description": "…",
 *   "settings": { … }, "styles": { … },          theme.json shape; font references by slug
 *   "fonts": {
 *     "heading": { "source": "bundled", "slug": "fraunces" } | { "source": "library", "id": <font family post> }
 *                | { "source": "remote", "key": "<cache key>", "generic": "serif" },
 *     "text":    { … },
 *     "scripts": { "arabic": {
 *       "heading": { "source": "pack", "pack": "arabic", "slug": "beiruti" } | { "source": "remote", "key": "…" },
 *       "text": { … },
 *       "size": 115,                                  size next to Latin text, 80 to 150 (%): the faces' sizeAdjust
 *       "languages": { "fa": { "source": "pack", "pack": "arabic", "slug": "vazirmatn" } }
 *     }, … }
 *   }
 * }
 * ```
 *
 * Style Mermaid's per-block looks (0.5) arrive in `styles` as plain theme.json, checked like everything else.
 *
 * `library`: a font installed in the Font Library of the scope's library site (managers only; Style Mermaid itself
 * no longer gets fonts from Google Fonts or Bunny Fonts for managers: they install them in the Site Editor).
 * `remote` stays for visitors of a public Style Mermaid where the site allows it.
 *
 * Light and dark: the twin colours (`Schemes`) are palette entries like any other; `clean()` and `preview()` also
 * return the contrast checks of the other scheme (`twinChecks`, null without twins).
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WP_Theme_JSON;
use WP_Theme_JSON_Resolver;
use Windrose\Common\Files;

/**
 * Builds variations from Style Mermaid's choices.
 *
 * A language font (the script's `languages` in `scripts.json`) becomes a family of its own, listed in no stack; the
 * style gets a rule for it in `settings.custom.windrose-livery-languages` (`Languages`), written here only, and its
 * slug ends with `-lang-<tag>`, the rule that survives WordPress's filter for site administrators.
 *
 * Every family stack lists, after its own font, the chosen script fonts (headings take the script's heading font,
 * everything else its text font; "Cyrillic and Greek" only where the family lacks them), then the generic family, so
 * the browser takes each letter from the first font that has it and downloads a script's file only when a page holds
 * that script. Chinese, Japanese and Korean use the visitor's system fonts.
 *
 * @phpstan-import-type Clean from Sanitizer
 * @phpstan-import-type Check from Contrast
 * @phpstan-import-type Variation from Library
 * @phpstan-type Source array{family: string, kind: string, name: string, token: string}
 * @phpstan-type Built array{families: list<array<string, mixed>>, sources: array<string, Source>, languages: array<string, array{heading: string, text: string}>}
 * @phpstan-type Pending array<string, array{entry: array{stored: string, name: string, family: string, kind: string}, bytes: string}>
 */
final class Builder {

	public const THEME      = 'ipsum';
	public const MAX_RECIPE = 262144;
	public const TOKEN      = 'mermaid:';
	public const GENERICS   = array( 'serif', 'sans-serif', 'monospace', 'cursive', 'system-ui' );

	/**
	 * Decodes and shapes a recipe.
	 *
	 * @param string $json JSON.
	 * @return array{title: string, description: string, settings: array<string, mixed>, styles: array<string, mixed>, fonts: array<string, mixed>}|WP_Error
	 */
	public static function parse( string $json ) {
		if ( '' === $json || strlen( $json ) > self::MAX_RECIPE ) {
			return new WP_Error( 'windrose_livery_recipe', __( 'The style could not be read (empty or too large).', 'windrose-livery' ), array( 'status' => 400 ) );
		}
		$data = json_decode( $json, true, 64 );
		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			return new WP_Error( 'windrose_livery_recipe', __( 'The style could not be read.', 'windrose-livery' ), array( 'status' => 400 ) );
		}
		$object = static fn( $value ): array => is_array( $value ) && ( array() === $value || ! array_is_list( $value ) ) ? $value : array();
		return array(
			'title'       => is_string( $data['title'] ?? null ) ? $data['title'] : '',
			'description' => is_string( $data['description'] ?? null ) ? $data['description'] : '',
			'settings'    => $object( $data['settings'] ?? null ),
			'styles'      => $object( $data['styles'] ?? null ),
			'fonts'       => $object( $data['fonts'] ?? null ),
		);
	}

	/**
	 * The slug a cached remote family gets in variations (one that is not a bundled family's).
	 *
	 * @param array{slug: string, provider: string} $entry Cache entry.
	 * @return string
	 */
	public static function remote_slug( array $entry ): string {
		return null === Catalog::font( $entry['slug'] ) ? $entry['slug'] : $entry['slug'] . '-' . $entry['provider'];
	}

	/**
	 * One font choice, resolved.
	 *
	 * @param mixed  $ref    Choice.
	 * @param string $role   heading, text or script.
	 * @return array{slug: string, name: string, generic: string, covers: list<string>, faces: list<array<string, string>>, licenses: list<string>, sizeAdjust: string}|WP_Error
	 */
	private static function resolve( $ref, string $role ) {
		$ref = is_array( $ref ) ? $ref : array();
		if ( 'bundled' === ( $ref['source'] ?? '' ) && is_string( $ref['slug'] ?? null ) ) {
			$font = Catalog::font( $ref['slug'] );
			if ( null === $font ) {
				return new WP_Error( 'windrose_livery_font_unknown', __( 'A chosen font is not in this plugin.', 'windrose-livery' ), array( 'status' => 400 ) );
			}
			$faces = array();
			foreach ( $font['faces'] as $face ) {
				$faces[] = array(
					'fontWeight' => $face['fontWeight'],
					'fontStyle'  => $face['fontStyle'],
					'token'      => self::TOKEN . 'b:' . $font['slug'] . '/' . $face['file'],
				);
			}
			return array(
				'slug'       => $font['slug'],
				'name'       => $font['name'],
				'generic'    => $font['generic'],
				'covers'     => $font['covers'],
				'faces'      => $faces,
				'licenses'   => array_map( static fn( string $file ): string => self::TOKEN . 'b:' . $font['slug'] . '/' . $file, Catalog::licenses( $font['slug'] ) ),
				'sizeAdjust' => '',
			);
		}//end if
		if ( 'pack' === ( $ref['source'] ?? '' ) && 'script' === $role && is_string( $ref['pack'] ?? null ) && is_string( $ref['slug'] ?? null ) ) {
			$family = Packs::find_family( $ref['pack'], $ref['slug'] );
			if ( null === $family ) {
				return new WP_Error( 'windrose_livery_pack_missing', __( 'A chosen font pack is not installed on this site.', 'windrose-livery' ), array( 'status' => 409 ) );
			}
			$faces = array();
			foreach ( $family['faces'] as $face ) {
				$faces[] = array(
					'fontWeight'   => $face['fontWeight'],
					'fontStyle'    => $face['fontStyle'],
					'unicodeRange' => $face['unicodeRange'],
					'token'        => self::TOKEN . 'p:' . $ref['pack'] . '/' . $family['slug'] . '/' . $face['file'],
				);
			}
			return array(
				'slug'       => $family['slug'],
				'name'       => $family['name'],
				'generic'    => $family['generic'],
				'covers'     => array(),
				'faces'      => $faces,
				'licenses'   => array( self::TOKEN . 'p:' . $ref['pack'] . '/' . $family['slug'] . '/' . $family['license'] ),
				'sizeAdjust' => $family['sizeAdjust'],
			);
		}//end if
		if ( 'library' === ( $ref['source'] ?? '' ) && 'script' !== $role && is_numeric( $ref['id'] ?? null ) && Library::can_manage() ) {
			$family = Font_Library::installed_family( (int) $ref['id'] );
			if ( null === $family || array() === $family['faces'] ) {
				return new WP_Error( 'windrose_livery_font_gone', __( 'A chosen font is no longer in the Font Library.', 'windrose-livery' ), array( 'status' => 409 ) );
			}
			$faces = array();
			foreach ( $family['faces'] as $face ) {
				$faces[] = array(
					'fontWeight'   => $face['fontWeight'],
					'fontStyle'    => $face['fontStyle'],
					'unicodeRange' => $face['unicodeRange'],
					'token'        => self::TOKEN . 'l:' . $face['id'],
				);
			}
			return array(
				'slug'       => null === Catalog::font( $family['slug'] ) ? $family['slug'] : $family['slug'] . '-library',
				'name'       => $family['name'],
				'generic'    => $family['generic'],
				'covers'     => array(),
				'faces'      => $faces,
				'licenses'   => array(),
				'sizeAdjust' => '',
			);
		}//end if
		if ( 'remote' === ( $ref['source'] ?? '' ) && is_string( $ref['key'] ?? null ) ) {
			$entry = Remote_Fonts::get( $ref['key'] );
			if ( null === $entry ) {
				return new WP_Error( 'windrose_livery_font_gone', __( 'A font from Google Fonts or Bunny Fonts is no longer on this server: get it again.', 'windrose-livery' ), array( 'status' => 409 ) );
			}
			$faces = array();
			foreach ( $entry['faces'] as $face ) {
				$faces[] = array(
					'fontWeight'   => $face['fontWeight'],
					'fontStyle'    => $face['fontStyle'],
					'unicodeRange' => $face['unicodeRange'],
					'token'        => self::TOKEN . 'r:' . $face['stored'],
				);
			}
			$generic = is_string( $ref['generic'] ?? null ) && in_array( $ref['generic'], self::GENERICS, true ) ? $ref['generic'] : 'sans-serif';
			return array(
				'slug'       => 'script' === $role ? $entry['slug'] : self::remote_slug( $entry ),
				'name'       => $entry['name'],
				'generic'    => $generic,
				'covers'     => array_values( array_intersect( array( 'cyrillic', 'greek' ), $entry['subsets'] ) ),
				'faces'      => $faces,
				'licenses'   => array( self::TOKEN . 'r:' . $entry['license'] ),
				'sizeAdjust' => self::measured_size( $entry['slug'] ),
			);
		}//end if
		return new WP_Error( 'windrose_livery_font_missing', __( 'Choose a heading font and a text font.', 'windrose-livery' ), array( 'status' => 400 ) );
	}

	/**
	 * The variation's font families, with sources as tokens.
	 *
	 * @param array<string, mixed> $fonts Font choices.
	 * @return Built|WP_Error
	 */
	public static function font_families( array $fonts ) {
		$latin = array();
		foreach ( array( 'heading', 'text' ) as $role ) {
			$font = self::resolve( $fonts[ $role ] ?? null, $role );
			if ( is_wp_error( $font ) ) {
				return $font;
			}
			$latin[ $role ] = $font;
		}
		foreach ( Catalog::ALWAYS as $slug ) {
			$font = self::resolve(
				array(
					'source' => 'bundled',
					'slug'   => $slug,
				),
				'text'
			);
			if ( ! is_wp_error( $font ) ) {
				$latin[ $slug ] = $font;
			}
		}

		// Script fonts, in the catalog's order.
		$chosen  = is_array( $fonts['scripts'] ?? null ) ? $fonts['scripts'] : array();
		$scripts = array();
		$names   = array_map( static fn( array $f ): string => strtolower( $f['name'] ), $latin );
		$slugs   = array_column( $latin, 'slug' );
		$extra   = array();
		$rules   = array();
		foreach ( Catalog::scripts() as $script ) {
			$pair = $chosen[ $script['slug'] ] ?? null;
			if ( ! empty( $script['system'] ) || ! is_array( $pair ) ) {
				continue;
			}
			$size  = is_numeric( $pair['size'] ?? null ) ? (int) $pair['size'] : null;
			$size  = null === $size ? null : max( 80, min( 150, $size ) );
			$one   = array();
			$shape = static function ( array $font ) use ( $script, $names, $slugs, $size ): array {
				// A script family named like a Latin one (Rubik for Hebrew) gets its own name, or the faces would mix.
				$css = in_array( strtolower( $font['name'] ), $names, true ) ? $font['name'] . ' ' . ucfirst( $script['slug'] ) : $font['name'];
				if ( $css !== $font['name'] || in_array( $font['slug'], $slugs, true ) ) {
					$font['slug'] = $font['slug'] . '-' . $script['slug'];
				}
				$font['name']       = $css;
				$font['sizeAdjust'] = null === $size ? $font['sizeAdjust'] : ( 100 === $size ? '' : $size . '%' );
				return $font;
			};
			foreach ( array( 'heading', 'text' ) as $role ) {
				$font = self::resolve( $pair[ $role ] ?? null, 'script' );
				if ( is_wp_error( $font ) ) {
					return $font;
				}
				$one[ $role ] = $shape( $font );
			}
			$scripts[] = array(
				'script'  => $script,
				'heading' => $one['heading'],
				'text'    => $one['text'],
			);
			// Fonts for one language of the script (Persian, Urdu): own families, and a rule.
			$languages = is_array( $pair['languages'] ?? null ) ? $pair['languages'] : array();
			foreach ( $script['languages'] ?? array() as $language ) {
				if ( ! is_array( $languages[ $language['tag'] ] ?? null ) ) {
					continue;
				}
				$font = self::resolve( $languages[ $language['tag'] ], 'script' );
				if ( is_wp_error( $font ) ) {
					return $font;
				}
				$font = $shape( $font );
				// The language in the family's slug: WordPress keeps font presets for everybody, while it removes
				// settings.custom for anyone without unfiltered_html (site administrators on a network).
				$font['slug']              = $font['slug'] . Languages::SUFFIX . $language['tag'];
				$extra[]                   = $font;
				$rules[ $language['tag'] ] = array(
					'heading' => $font['slug'],
					'text'    => $font['slug'],
				);
			}
		}//end foreach

		$families = array();
		$sources  = array();
		$done     = array();
		$add      = static function ( array $font, string $stack ) use ( &$families, &$sources, &$done ): void {
			if ( isset( $done[ $font['slug'] ] ) ) {
				return;
			}
			$done[ $font['slug'] ] = true;
			$faces                 = array();
			foreach ( $font['faces'] as $face ) {
				$one = array(
					'fontFamily'  => $font['name'],
					'fontStyle'   => $face['fontStyle'],
					'fontWeight'  => $face['fontWeight'],
					'fontDisplay' => 'swap',
					'src'         => array( $face['token'] ),
				);
				if ( '' !== ( $face['unicodeRange'] ?? '' ) ) {
					$one['unicodeRange'] = $face['unicodeRange'];
				}
				if ( '' !== ( $font['sizeAdjust'] ?? '' ) ) {
					$one['sizeAdjust'] = $font['sizeAdjust'];
				}
				$faces[]                     = $one;
				$sources[ $face['token'] ] ??= array(
					'family' => $font['slug'],
					'kind'   => 'font',
					'name'   => '',
					'token'  => $face['token'],
				);
			}//end foreach
			foreach ( $font['licenses'] as $token ) {
				$sources[ $token ] ??= array(
					'family' => $font['slug'],
					'kind'   => 'license',
					'name'   => '',
					'token'  => $token,
				);
			}
			$families[] = array(
				'name'       => $font['name'],
				'slug'       => $font['slug'],
				'fontFamily' => $stack,
				'fontFace'   => $faces,
			);
		};
		$stack    = static function ( array $font, string $role ) use ( $scripts ): string {
			$parts = array( '"' . $font['name'] . '"' );
			foreach ( $scripts as $one ) {
				if ( ! empty( $one['script']['onlyMissing'] ) && in_array( 'cyrillic', $font['covers'], true ) ) {
					continue;
				}
				$name = '"' . $one[ 'heading' === $role ? 'heading' : 'text' ]['name'] . '"';
				if ( ! in_array( $name, $parts, true ) ) {
					$parts[] = $name;
				}
			}
			$parts[] = $font['generic'];
			return implode( ', ', $parts );
		};
		$same     = $latin['heading']['slug'] === $latin['text']['slug'];
		foreach ( $latin as $role => $font ) {
			$add( $font, $stack( $font, 'heading' === $role && ! $same ? 'heading' : 'text' ) );
		}
		foreach ( $scripts as $one ) {
			foreach ( array( 'heading', 'text' ) as $role ) {
				$add( $one[ $role ], '"' . $one[ $role ]['name'] . '", ' . $one[ $role ]['generic'] );
			}
		}
		foreach ( $extra as $font ) {
			$add( $font, '"' . $font['name'] . '", ' . $font['generic'] );
		}
		return array(
			'families'  => $families,
			'sources'   => $sources,
			'languages' => $rules,
		);
	}

	/**
	 * The size a script family gets next to Latin text, as measured for the font packs (empty: 100%).
	 *
	 * @param string $slug Family slug.
	 * @return string
	 */
	private static function measured_size( string $slug ): string {
		foreach ( Packs::available() as $pack ) {
			foreach ( $pack['families'] as $family ) {
				if ( $family['slug'] === $slug ) {
					return $family['sizeAdjust'];
				}
			}
		}
		return '';
	}

	/**
	 * The raw variation: the recipe's settings and styles with this server's font families.
	 *
	 * @param array{title: string, description: string, settings: array<string, mixed>, styles: array<string, mixed>, fonts: array<string, mixed>} $recipe   Recipe.
	 * @param list<array<string, mixed>>                                                                                                           $families Families.
	 * @param array<string, array{heading: string, text: string}>                                                                                  $languages Language rules.
	 * @return array<string, mixed>
	 */
	private static function raw( array $recipe, array $families, array $languages = array() ): array {
		$settings = $recipe['settings'];
		unset( $settings['blocks'] );
		// Language rules come from the font choices only.
		if ( is_array( $settings['custom'] ?? null ) ) {
			unset( $settings['custom'][ Languages::KEY ] );
			if ( array() === $settings['custom'] ) {
				unset( $settings['custom'] );
			}
		}
		if ( array() !== $languages ) {
			$settings['custom']                   = is_array( $settings['custom'] ?? null ) ? $settings['custom'] : array();
			$settings['custom'][ Languages::KEY ] = $languages;
		}
		if ( isset( $settings['typography'] ) && is_array( $settings['typography'] ) ) {
			unset( $settings['typography']['fontFamilies'] );
		}
		$settings['typography']                 = is_array( $settings['typography'] ?? null ) ? $settings['typography'] : array();
		$settings['typography']['fontFamilies'] = $families;
		return array(
			'version'     => WP_Theme_JSON::LATEST_SCHEMA,
			'title'       => $recipe['title'],
			'description' => $recipe['description'],
			'settings'    => $settings,
			'styles'      => $recipe['styles'],
		);
	}

	/**
	 * The clean variation and its files, as an import would store them; plus the contrast checks.
	 *
	 * @param array{title: string, description: string, settings: array<string, mixed>, styles: array<string, mixed>, fonts: array<string, mixed>} $recipe Recipe.
	 * @return array{clean: Clean, pending: Pending, checks: list<Check>, twinChecks: list<Check>|null}|WP_Error
	 */
	public static function clean( array $recipe ) {
		$built = self::font_families( $recipe['fonts'] );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		$pending = array();
		$errors  = array();
		$mapper  = static function ( string $src ) use ( $built, &$pending, &$errors ) {
			$source = $built['sources'][ $src ] ?? null;
			if ( null === $source ) {
				return null;
			}
			if ( count( array_filter( $pending, static fn( array $p ): bool => 'font' === $p['entry']['kind'] ) ) >= Importer::MAX_FILES ) {
				$errors[] = true;
				return null;
			}
			$stored = self::add_file( $pending, $source );
			if ( null === $stored ) {
				return null;
			}
			foreach ( $built['sources'] as $other ) {
				if ( 'license' === $other['kind'] && $other['family'] === $source['family'] ) {
					self::add_file( $pending, $other );
				}
			}
			return Fonts::PREFIX . $stored;
		};
		$clean   = Sanitizer::clean( self::raw( $recipe, $built['families'], $built['languages'] ), __( 'My style', 'windrose-livery' ), false, $mapper );
		if ( is_wp_error( $clean ) ) {
			$clean->add_data( array( 'status' => 400 ) );
			return $clean;
		}
		if ( array() !== $errors ) {
			return new WP_Error( 'windrose_livery_files', __( 'This style needs more than 60 font files: choose fewer weights or scripts.', 'windrose-livery' ), array( 'status' => 400 ) );
		}
		return array(
			'clean'      => $clean,
			'pending'    => $pending,
			'checks'     => Contrast::check( $clean['data']['settings'], $clean['data']['styles'] ),
			'twinChecks' => Contrast::check_twins( $clean['data']['settings'], $clean['data']['styles'] ),
		);
	}

	/**
	 * Adds a bundled or cached file to the pending files.
	 *
	 * @param array $pending Files, added to.
	 * @phpstan-param Pending $pending
	 * @param array $source  Source.
	 * @phpstan-param Source $source
	 * @return string|null Stored name.
	 */
	private static function add_file( array &$pending, array $source ): ?string {
		$token = substr( $source['token'], strlen( self::TOKEN ) );
		$bytes = null;
		$name  = '';
		if ( str_starts_with( $token, 'b:' ) ) {
			[ $slug, $file ] = array_pad( explode( '/', substr( $token, 2 ), 2 ), 2, '' );
			$path            = Catalog::path( $slug, $file );
			$bytes           = '' === $path ? null : Files::read( $path );
			$name            = $file;
		} elseif ( str_starts_with( $token, 'r:' ) ) {
			$bytes = Remote_Fonts::read( substr( $token, 2 ) );
			$name  = (string) preg_replace( '/^[a-f0-9]{16}-/', '', substr( $token, 2 ) );
		} elseif ( str_starts_with( $token, 'p:' ) ) {
			[ $pack, $slug, $file ] = array_pad( explode( '/', substr( $token, 2 ), 3 ), 3, '' );
			$bytes                  = Packs::read( $pack, $slug, $file );
			$name                   = $file;
		} elseif ( str_starts_with( $token, 'l:' ) ) {
			$path  = Font_Library::face_path( (int) substr( $token, 2 ) );
			$bytes = '' === $path ? null : Files::read( $path );
			$name  = (string) preg_replace( '/^[a-f0-9]{16}-/', '', wp_basename( $path ) );
		}
		if ( null === $bytes || '' === $bytes ) {
			return null;
		}
		$stored = Fonts::stored_name( $name, $bytes );
		if ( ! isset( $pending[ $stored ] ) ) {
			$pending[ $stored ] = array(
				'entry' => array(
					'stored' => $stored,
					'name'   => sanitize_file_name( $name ),
					'family' => sanitize_title( $source['family'] ),
					'kind'   => $source['kind'],
				),
				'bytes' => $bytes,
			);
		}
		return $stored;
	}

	/**
	 * An unsaved style's theme.json data with its font families (addresses on this website), after the import checks:
	 * what the sample page and the patterns (`Patterns`) show.
	 *
	 * @param array{title: string, description: string, settings: array<string, mixed>, styles: array<string, mixed>, fonts: array<string, mixed>} $recipe Recipe.
	 * @return array{settings: array<string, mixed>, styles: array<string, mixed>, families: list<array<string, mixed>>, languages: array<string, array{heading: string, text: string}>, notes: list<string>}|WP_Error
	 */
	public static function style_data( array $recipe ) {
		$built = self::font_families( $recipe['fonts'] );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		$families = $built['families'];
		foreach ( $families as $i => $family ) {
			foreach ( $family['fontFace'] as $j => $face ) {
				$families[ $i ]['fontFace'][ $j ]['src'] = array( self::url( $face['src'][0] ) );
			}
		}
		// The theme.json data through the import checks, then the families with addresses (made here, not sent).
		$raw   = self::raw( $recipe, array(), $built['languages'] );
		$clean = Sanitizer::clean( $raw, 'Preview', false );
		if ( is_wp_error( $clean ) ) {
			$clean->add_data( array( 'status' => 400 ) );
			return $clean;
		}
		$data                           = $clean['data'];
		$data['settings']['typography'] = is_array( $data['settings']['typography'] ?? null ) ? $data['settings']['typography'] : array();
		$data['settings']['typography']['fontFamilies'] = $families;
		return array(
			'settings'  => $data['settings'],
			'styles'    => $data['styles'],
			'families'  => $families,
			'languages' => $built['languages'],
			'notes'     => $clean['notes'],
		);
	}

	/**
	 * Preview: the stylesheet WordPress would make for Ipsum with this variation, the font faces (addresses on this
	 * website) and the contrast checks.
	 *
	 * @param array{title: string, description: string, settings: array<string, mixed>, styles: array<string, mixed>, fonts: array<string, mixed>} $recipe Recipe.
	 * @return array{css: string, fonts: string, checks: list<Check>, twinChecks: list<Check>|null, notes: list<string>}|WP_Error
	 */
	public static function preview( array $recipe ) {
		$ipsum = self::ipsum_data();
		if ( is_wp_error( $ipsum ) ) {
			return $ipsum;
		}
		$data = self::style_data( $recipe );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$merged = clone WP_Theme_JSON_Resolver::get_core_data();
		$merged->merge( new WP_Theme_JSON( $ipsum, 'theme' ) );
		$merged->merge(
			new WP_Theme_JSON(
				array(
					'version'  => WP_Theme_JSON::LATEST_SCHEMA,
					'settings' => $data['settings'],
					'styles'   => $data['styles'],
				),
				'custom'
			)
		);
		$merged_styles = $merged->get_raw_data()['styles'] ?? array();
		return array(
			'css'        => $merged->get_stylesheet( array( 'variables', 'styles', 'presets' ) ) . Languages::css( $data['languages'], $data['families'], is_array( $merged_styles ) ? $merged_styles : array() ) . Schemes::css( Schemes::twins( Importer::palette( $data['settings'] ) ), false ),
			'fonts'      => Fonts::css( $data['families'] ),
			'checks'     => Contrast::check( $data['settings'], $data['styles'] ),
			'twinChecks' => Contrast::check_twins( $data['settings'], $data['styles'] ),
			'notes'      => $data['notes'],
		);
	}

	/**
	 * The address of a token's file.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function url( string $token ): string {
		$token = substr( $token, strlen( self::TOKEN ) );
		if ( str_starts_with( $token, 'b:' ) ) {
			[ $slug, $file ] = array_pad( explode( '/', substr( $token, 2 ), 2 ), 2, '' );
			return Catalog::url( $slug, $file );
		}
		if ( str_starts_with( $token, 'p:' ) ) {
			[ $pack, $slug, $file ] = array_pad( explode( '/', substr( $token, 2 ), 3 ), 3, '' );
			return Packs::url( $pack, $slug, $file );
		}
		if ( str_starts_with( $token, 'l:' ) ) {
			return Font_Library::face_url( (int) substr( $token, 2 ) );
		}
		return Remote_Fonts::url( substr( $token, 2 ) );
	}

	/**
	 * Ipsum's theme.json data (the variation's base).
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function ipsum_data() {
		$theme = wp_get_theme( self::THEME );
		$file  = $theme->exists() ? $theme->get_file_path( 'theme.json' ) : '';
		$data  = '' !== $file && is_readable( $file ) ? wp_json_file_decode( $file, array( 'associative' => true ) ) : null;
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'windrose_livery_ipsum', __( 'Style Mermaid makes styles for the Ipsum theme: install Ipsum first (it does not need to be active).', 'windrose-livery' ), array( 'status' => 409 ) );
		}
		/**
		 * Decoded and checked above.
		 *
		 * @var array<string, mixed> $data
		 */
		return $data;
	}

	/**
	 * A zip package of a built variation (nothing stored): the caller sends and deletes it.
	 *
	 * @param array $clean   Clean variation.
	 * @phpstan-param Clean $clean
	 * @param array $pending Its files.
	 * @phpstan-param Pending $pending
	 * @return string|WP_Error Path.
	 */
	public static function zip( array $clean, array $pending ) {
		$item = array(
			'id'          => 0,
			'scope'       => 0,
			'title'       => $clean['title'],
			'slug'        => sanitize_title( $clean['title'] ),
			'description' => $clean['description'],
			'theme'       => self::THEME,
			'version'     => 1,
			'data'        => $clean['data'],
			'files'       => array_values( array_map( static fn( array $file ): array => $file['entry'], $pending ) ),
			'modified'    => '',
		);
		return Package::export( $item, static fn( string $stored ): ?string => $pending[ $stored ]['bytes'] ?? null );
	}
}
