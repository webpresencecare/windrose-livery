<?php
/**
 * Font rules per language: a style may name a font for a language that shares its script with others (Persian and
 * Urdu next to Arabic; Japanese, Korean and the two Chinese next to each other, as their Han characters differ). The
 * page in that language then lists that font right after its Latin font, in every font stack that holds other script
 * fonts; other pages never download it.
 *
 * Stored in the style itself, so it goes wherever the style goes (applied, picked in the Site Editor, exported):
 *
 * ```
 * "settings": { "custom": { "windrose-livery-languages": {
 *     "fa": { "heading": "vazirmatn", "text": "vazirmatn" },        family slugs of the style's own font families
 *     "ja": { "heading": "noto-sans-jp", "text": "noto-sans-jp" }
 * } } }
 * ```
 *
 * Since 0.9.1 the language is also in the language font's family slug (`vazirmatn-lang-fa`, SUFFIX):
 * WordPress removes settings.custom from the saves of anyone without unfiltered_html, but keeps font presets.
 *
 * WordPress also prints these values as `--wp--custom--windrose-livery-languages--*` variables (harmless). Livery
 * prints, from the site's current global settings and styles (front end and editor canvas):
 *
 * - `:root:lang(fa)`: the font family variables with the language's font moved up (the whole page in that language);
 * - elements with their own `lang` (Windrose Sabir's language item, a quotation): the same variables, and the text
 *   font (headings: the heading font). Such an element in a menu so shows the text font, not the menu's label font.
 *
 * Tags: BCP 47, lowercase; `zh-hant` also covers zh-TW, zh-HK and zh-MO, `zh-hans` covers zh-CN and zh-SG. Works with
 * or without Windrose Sabir: WordPress puts the site's language on every page.
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-language font rules.
 *
 * @phpstan-type Rules array<string, array{heading: string, text: string}>
 * @phpstan-import-type Data from Library
 */
final class Languages {

	public const KEY    = 'windrose-livery-languages';
	public const HANDLE = 'windrose-livery-languages';

	/** Marks a language font's family slug: `vazirmatn-lang-fa` is the font of Persian. */
	public const SUFFIX = '-lang-';

	/**
	 * Other tags a tag covers.
	 */
	private const ALIASES = array(
		'zh-hant' => array( 'zh-hant', 'zh-tw', 'zh-hk', 'zh-mo' ),
		'zh-hans' => array( 'zh-hans', 'zh-cn', 'zh-sg' ),
	);

	/**
	 * Hooks the stylesheet (front end and editor canvas).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'enqueue_block_assets', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Adds the rules of the site's current styles, when there are any.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		$css = self::site_css();
		if ( '' === $css ) {
			return;
		}
		wp_register_style( self::HANDLE, false, array(), WINDROSE_LIVERY_VERSION );
		wp_add_inline_style( self::HANDLE, $css );
		wp_enqueue_style( self::HANDLE );
	}

	/**
	 * The rules for the site's current global settings and styles.
	 *
	 * @return string
	 */
	public static function site_css(): string {
		$families = wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
		$families = Library::font_families( array( 'typography' => array( 'fontFamilies' => is_array( $families ) ? $families : array() ) ) );
		// From the language fonts' slugs; styles made before 0.9.1 only have settings.custom (kept for super admins).
		$rules = self::rules( wp_get_global_settings( array( 'custom', self::KEY ) ) ) + self::from_families( $families );
		if ( array() === $rules ) {
			return '';
		}
		$styles = array(
			'typography' => array( 'fontFamily' => wp_get_global_styles( array( 'typography', 'fontFamily' ) ) ),
			'elements'   => array( 'heading' => array( 'typography' => array( 'fontFamily' => wp_get_global_styles( array( 'elements', 'heading', 'typography', 'fontFamily' ) ) ) ) ),
		);
		return self::css( $rules, $families, $styles );
	}

	/**
	 * Imported styles (the design kit's `ja` and `ko` rules): a rule whose font serves only that rule gets the language
	 * in its family slug, as Style Mermaid writes it, so the rule survives WordPress's filter for site administrators.
	 * A font the style names anywhere else (a style, a block, another rule) keeps its slug.
	 *
	 * @param array $data Clean settings and styles.
	 * @phpstan-param Data $data
	 * @return array
	 * @phpstan-return Data
	 */
	public static function into_slugs( array $data ): array {
		$rules    = self::rules( $data['settings']['custom'][ self::KEY ] ?? null );
		$families = $data['settings']['typography']['fontFamilies'] ?? null;
		if ( array() === $rules || ! is_array( $families ) ) {
			return $data;
		}
		$json = (string) wp_json_encode( $data );
		foreach ( $rules as $tag => $rule ) {
			$slug = $rule['text'];
			if ( $rule['heading'] !== $slug || str_contains( $slug, self::SUFFIX ) ) {
				continue;
			}
			// Three times exactly: its family, and the rule's heading and text (a font of two rules appears five times).
			// No preset reference anywhere.
			if ( 3 !== substr_count( $json, '"' . $slug . '"' ) || 1 === preg_match( '/font-family(?:\||--)' . preg_quote( $slug, '/' ) . '(?![a-z0-9-])/', $json ) ) {
				continue;
			}
			$new = $slug . self::SUFFIX . $tag;
			foreach ( $families as $i => $family ) {
				if ( is_array( $family ) && ( $family['slug'] ?? null ) === $slug ) {
					$data['settings']['typography']['fontFamilies'][ $i ]['slug'] = $new;
					$data['settings']['custom'][ self::KEY ][ $tag ]              = array(
						'heading' => $new,
						'text'    => $new,
					);
				}
			}
		}//end foreach
		return $data;
	}

	/**
	 * Rules from the language fonts' family slugs (`<font>-lang-<tag>`, see SUFFIX).
	 *
	 * @param array<mixed> $families Font families.
	 * @return Rules
	 */
	public static function from_families( array $families ): array {
		$rules = array();
		foreach ( $families as $family ) {
			$slug = is_array( $family ) && is_string( $family['slug'] ?? null ) ? $family['slug'] : '';
			if ( 1 === preg_match( '/' . self::SUFFIX . '([a-z]{2,3}(?:-[a-z0-9]{2,8}){0,2})$/', $slug, $m ) ) {
				$rules[ $m[1] ] = array(
					'heading' => $slug,
					'text'    => $slug,
				);
			}
		}
		return self::rules( $rules );
	}

	/**
	 * Checked rules.
	 *
	 * @param mixed $value `settings.custom.windrose-livery-languages`.
	 * @return Rules
	 */
	public static function rules( $value ): array {
		$out = array();
		if ( ! is_array( $value ) ) {
			return $out;
		}
		foreach ( $value as $tag => $rule ) {
			$tag = strtolower( (string) $tag );
			if ( 1 !== preg_match( '/^[a-z]{2,3}(-[a-z0-9]{2,8}){0,2}$/', $tag ) || ! is_array( $rule ) ) {
				continue;
			}
			$heading = is_string( $rule['heading'] ?? null ) ? $rule['heading'] : '';
			$text    = is_string( $rule['text'] ?? null ) ? $rule['text'] : '';
			if ( 1 === preg_match( '/^[a-z0-9-]{1,100}$/', $heading ) && 1 === preg_match( '/^[a-z0-9-]{1,100}$/', $text ) ) {
				$out[ $tag ] = array(
					'heading' => $heading,
					'text'    => $text,
				);
			}
		}
		// Shorter tags first, so a more precise one (zh-hant after zh) wins.
		uksort( $out, static fn( string $a, string $b ): int => 0 !== ( strlen( $a ) <=> strlen( $b ) ) ? strlen( $a ) <=> strlen( $b ) : strcmp( $a, $b ) );
		return $out;
	}

	/**
	 * The CSS for rules, font families (theme.json shape: slug, fontFamily) and styles (text and heading font).
	 *
	 * @param array        $rules    Rules.
	 * @phpstan-param Rules $rules
	 * @param array<mixed> $families Font families.
	 * @param array<mixed> $styles   Styles (`typography.fontFamily`, `elements.heading.typography.fontFamily`).
	 * @return string
	 */
	public static function css( array $rules, array $families, array $styles ): string {
		$stacks = array();
		foreach ( $families as $family ) {
			if ( is_array( $family ) && is_string( $family['slug'] ?? null ) && is_string( $family['fontFamily'] ?? null ) && 1 === preg_match( '/^[a-z0-9-]{1,100}$/', $family['slug'] ) ) {
				$names = self::names( $family['fontFamily'] );
				if ( null !== $names ) {
					$stacks[ $family['slug'] ] = $names;
				}
			}
		}
		$text    = self::slug_of( $styles['typography']['fontFamily'] ?? null );
		$heading = self::slug_of( $styles['elements']['heading']['typography']['fontFamily'] ?? null );
		$css     = '';
		foreach ( self::rules( $rules ) as $tag => $rule ) {
			$fonts = array();
			foreach ( array( 'heading', 'text' ) as $role ) {
				$fonts[ $role ] = $stacks[ $rule[ $role ] ]['names'][0] ?? '';
			}
			if ( '' === $fonts['text'] ) {
				continue;
			}
			$vars = '';
			foreach ( $stacks as $slug => $stack ) {
				// Only stacks that already continue with other fonts (script fonts), never a language font's own.
				if ( count( $stack['names'] ) < 2 || in_array( $stack['names'][0], $fonts, true ) ) {
					continue;
				}
				$font  = $slug === $heading && '' !== $fonts['heading'] ? $fonts['heading'] : $fonts['text'];
				$names = array_values( array_diff( $stack['names'], array( $font ) ) );
				array_splice( $names, 1, 0, array( $font ) );
				$list  = implode( ', ', array_map( static fn( string $n ): string => '"' . $n . '"', $names ) ) . ( '' !== $stack['generic'] ? ', ' . $stack['generic'] : '' );
				$vars .= self::var_name( $slug ) . ':' . $list . ';';
			}
			if ( '' === $vars ) {
				continue;
			}
			$lang = ':is(' . implode( ',', array_map( static fn( string $t ): string => ':lang(' . $t . ')', self::ALIASES[ $tag ] ?? array( $tag ) ) ) . ')';
			$css .= ':root' . $lang . '{' . $vars . '}';
			$css .= ':not(html)[lang]' . $lang . '{' . $vars . ( null !== $text ? 'font-family:var(' . self::var_name( $text ) . ');' : '' ) . '}';
			if ( null !== $heading ) {
				$css .= ':is(h1,h2,h3,h4,h5,h6)[lang]' . $lang . ',:is(h1,h2,h3,h4,h5,h6) :not(html)[lang]' . $lang . '{font-family:var(' . self::var_name( $heading ) . ');}';
			}
		}//end foreach
		return $css;
	}

	/**
	 * The quoted names and the generic family of a font-family list, or null when it holds anything else.
	 *
	 * @param string $value Font-family list.
	 * @return array{names: list<string>, generic: string}|null
	 */
	private static function names( string $value ): ?array {
		$names   = array();
		$generic = '';
		foreach ( explode( ',', $value ) as $part ) {
			$part = trim( $part );
			$name = trim( $part, "\"' " );
			if ( '' === $name ) {
				continue;
			}
			if ( in_array( strtolower( $name ), array( 'serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-serif', 'ui-sans-serif', 'ui-monospace', 'ui-rounded', 'math', 'emoji' ), true ) ) {
				$generic = strtolower( $name );
				continue;
			}
			if ( 1 !== preg_match( '/^[\p{L}\p{N} _-]{1,80}$/u', $name ) ) {
				return null;
			}
			$names[] = $name;
		}
		return array() === $names ? null : array(
			'names'   => $names,
			'generic' => $generic,
		);
	}

	/**
	 * The font family slug a style value refers to (`var:preset|font-family|x` or `var(--wp--preset--font-family--x)`).
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function slug_of( $value ): ?string {
		if ( is_string( $value ) && 1 === preg_match( '/^(?:var:preset\|font-family\||var\(--wp--preset--font-family--)([a-z0-9-]{1,100})\)?$/', $value, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * The variable WordPress prints for a font family preset.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	private static function var_name( string $slug ): string {
		return '--wp--preset--font-family--' . _wp_to_kebab_case( $slug );
	}
}
