<?php
/**
 * WCAG 2.2 contrast checks of a style variation made for Ipsum (Style Mermaid).
 *
 * The same rules as the design kit of the ready styles and as `src/mermaid/contrast.js`, which
 * shows them live; both read the shared fixtures in `tests/fixtures/contrast.json`.
 *
 * | Check          | Pair                                               | Minimum |
 * |----------------|----------------------------------------------------|---------|
 * | text           | text on the page background (Base)                 | 4.5     |
 * | text-soft      | text on Surface (accent-5)                         | 4.5     |
 * | link           | links on the page background                       | 4.5     |
 * | link-hover     | links on hover                                     | 4.5     |
 * | heading        | headings (large text)                              | 3       |
 * | button         | button text on the button (page if transparent)    | 4.5     |
 * | button-hover   | the same on hover                                  | 4.5     |
 * | caption        | captions, when they have their own color           | 4.5     |
 * | accent         | the accent (accent-1) as text on the page          | 4.5     |
 * | accent-surface | Base (base) on Accent (accent-1), for patterns     | 4.5     |
 * | strong         | Base on Strong Contrast (accent-3), for patterns   | 4.5     |
 *
 * Colors with transparency are blended over what is under them. Values left unset fall back to Ipsum's own.
 *
 * A style with light or dark twins (`Schemes`) is checked twice: with its own colours, and with each colour replaced
 * by its twin, as the page shows it in the other scheme.
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contrast checks.
 *
 * @phpstan-type Rgba array{0: float, 1: float, 2: float, 3: float}
 * @phpstan-type Check array{id: string, ratio: float, min: float, pass: bool, warning: bool, fg: string, bg: string}
 */
final class Contrast {

	/**
	 * Ipsum's own values for what a variation leaves unset.
	 */
	private const DEFAULTS = array(
		'background'       => 'base',
		'text'             => 'contrast',
		'link'             => 'contrast',
		'link-hover'       => 'accent-3',
		'button-bg'        => 'contrast',
		'button-text'      => 'base',
		'button-hover-bg'  => 'accent-3',
		'button-hover-txt' => 'base',
	);

	/**
	 * Runs every check.
	 *
	 * @param array $settings Settings (palette).
	 * @phpstan-param array<mixed> $settings
	 * @param array $styles   Styles.
	 * @phpstan-param array<mixed> $styles
	 * @return list<Check>
	 */
	public static function check( array $settings, array $styles ): array {
		$palette = array();
		foreach ( is_array( $settings['color']['palette'] ?? null ) ? $settings['color']['palette'] : array() as $color ) {
			if ( is_array( $color ) && is_string( $color['slug'] ?? null ) && is_string( $color['color'] ?? null ) ) {
				$palette[ $color['slug'] ] = $color['color'];
			}
		}
		$get = static function ( array $path, string $fallback ) use ( $styles, $palette ): string {
			$value = _wp_array_get( $styles, $path, null );
			$value = is_string( $value ) && '' !== $value ? $value : 'var:preset|color|' . $fallback;
			return self::resolve( $value, $palette );
		};

		$white = array( 255.0, 255.0, 255.0, 1.0 );
		$bg    = self::over( self::parse( $get( array( 'color', 'background' ), self::DEFAULTS['background'] ) ), $white );
		$text  = $get( array( 'color', 'text' ), self::DEFAULTS['text'] );
		$soft  = isset( $palette['accent-5'] ) ? self::over( self::parse( self::resolve( $palette['accent-5'], $palette ) ), $bg ) : null;

		$button_set   = is_array( _wp_array_get( $styles, array( 'blocks', 'core/button', 'color' ), null ) );
		$button_path  = $button_set ? array( 'blocks', 'core/button' ) : array( 'elements', 'button' );
		$btn_bg       = $get( array_merge( $button_path, array( 'color', 'background' ) ), self::DEFAULTS['button-bg'] );
		$btn_text     = $get( array_merge( $button_path, array( 'color', 'text' ) ), self::DEFAULTS['button-text'] );
		$btn_hover_bg = $get( array_merge( $button_path, array( ':hover', 'color', 'background' ) ), self::DEFAULTS['button-hover-bg'] );
		$btn_hover_tx = $get( array_merge( $button_path, array( ':hover', 'color', 'text' ) ), self::DEFAULTS['button-hover-txt'] );

		$checks  = array(
			self::pair( 'text', $text, $bg, 4.5 ),
			null === $soft ? null : self::pair( 'text-soft', $text, $soft, 4.5 ),
			self::pair( 'link', $get( array( 'elements', 'link', 'color', 'text' ), self::DEFAULTS['link'] ), $bg, 4.5 ),
			self::pair( 'link-hover', $get( array( 'elements', 'link', ':hover', 'color', 'text' ), self::DEFAULTS['link-hover'] ), $bg, 4.5 ),
			self::pair( 'heading', self::resolve( self::string_at( $styles, array( 'elements', 'heading', 'color', 'text' ) ) ?? $text, $palette ), $bg, 3.0 ),
			self::pair( 'button', $btn_text, self::over( self::parse( $btn_bg ), $bg ), 4.5 ),
			self::pair( 'button-hover', $btn_hover_tx, self::over( self::parse( $btn_hover_bg ), $bg ), 4.5 ),
		);
		$caption = self::string_at( $styles, array( 'elements', 'caption', 'color', 'text' ) );
		if ( null !== $caption ) {
			$checks[] = self::pair( 'caption', self::resolve( $caption, $palette ), $bg, 4.5 );
		}
		if ( isset( $palette['accent-1'] ) ) {
			$checks[] = self::pair( 'accent', self::resolve( $palette['accent-1'], $palette ), $bg, 4.5 );
		}
		// Patterns put Background text on Accent and on Strong sections.
		if ( isset( $palette['base'] ) ) {
			$base = self::over( self::parse( self::resolve( $palette['base'], $palette ) ), $white );
			foreach ( array(
				'accent-surface' => 'accent-1',
				'strong'         => 'accent-3',
			) as $id => $slug ) {
				if ( isset( $palette[ $slug ] ) ) {
					$checks[] = self::pair( $id, self::resolve( $palette[ $slug ], $palette ), $base, 4.5 );
				}
			}
		}
		return array_values( array_filter( $checks ) );
	}

	/**
	 * Settings with each colour that has a twin replaced by its twin (the other scheme), or null without twins.
	 *
	 * @param array<mixed> $settings Settings.
	 * @return array<mixed>|null
	 */
	public static function twin_settings( array $settings ): ?array {
		$palette = Importer::palette( $settings );
		$twins   = Schemes::twins( $palette );
		if ( '' === $twins['scheme'] ) {
			return null;
		}
		$values = array_column( $palette, 'color', 'slug' );
		foreach ( $settings['color']['palette'] as $i => $color ) {
			$slug = is_array( $color ) && is_string( $color['slug'] ?? null ) ? $color['slug'] : '';
			if ( isset( $twins['map'][ $slug ], $values[ $twins['map'][ $slug ] ] ) ) {
				$settings['color']['palette'][ $i ]['color'] = $values[ $twins['map'][ $slug ] ];
			}
		}
		return $settings;
	}

	/**
	 * The checks of the other scheme, or null without twins.
	 *
	 * @param array $settings Settings.
	 * @phpstan-param array<mixed> $settings
	 * @param array $styles   Styles.
	 * @phpstan-param array<mixed> $styles
	 * @return list<Check>|null
	 */
	public static function check_twins( array $settings, array $styles ): ?array {
		$twin = self::twin_settings( $settings );
		return null === $twin ? null : self::check( $twin, $styles );
	}

	/**
	 * Whether the own colours pass, and the twins (null without twins).
	 *
	 * @param array<mixed> $settings Settings.
	 * @param array<mixed> $styles   Styles.
	 * @return array{own: bool, twin: bool|null}
	 */
	public static function summary( array $settings, array $styles ): array {
		$twin = self::check_twins( $settings, $styles );
		return array(
			'own'  => self::passes( self::check( $settings, $styles ) ),
			'twin' => null === $twin ? null : self::passes( $twin ),
		);
	}

	/**
	 * Whether every check that is not a warning passes.
	 *
	 * @param array $checks Checks.
	 * @phpstan-param list<Check> $checks
	 * @return bool
	 */
	public static function passes( array $checks ): bool {
		foreach ( $checks as $check ) {
			if ( ! $check['pass'] && ! $check['warning'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * One check, or null when a color cannot be read.
	 *
	 * @param string     $id      Check.
	 * @param string     $fg      Foreground (CSS color).
	 * @param array|null $bg      Background (opaque, from over()).
	 * @phpstan-param Rgba|null $bg
	 * @param float      $min     Minimum ratio.
	 * @param bool       $warning Whether failing is only a warning.
	 * @return Check|null
	 */
	private static function pair( string $id, string $fg, ?array $bg, float $min, bool $warning = false ): ?array {
		$back = $bg;
		$fore = self::parse( $fg );
		if ( null === $back || null === $fore ) {
			return null;
		}
		$fore  = self::over( $fore, $back ) ?? $fore;
		$ratio = self::ratio( $fore, $back );
		return array(
			'id'      => $id,
			'ratio'   => round( $ratio, 2 ),
			'min'     => $min,
			'pass'    => round( $ratio, 2 ) >= $min,
			'warning' => $warning,
			'fg'      => self::css( $fore ),
			'bg'      => self::css( $back ),
		);
	}

	/**
	 * A string value at a path, or null.
	 *
	 * @param array $styles Styles.
	 * @phpstan-param array<mixed> $styles
	 * @param array $path   Path.
	 * @phpstan-param list<string> $path
	 * @return string|null
	 */
	private static function string_at( array $styles, array $path ): ?string {
		$value = _wp_array_get( $styles, $path, null );
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * A palette reference turned into its color (other values unchanged).
	 *
	 * @param string                $value   Value.
	 * @param array<string, string> $palette Slug => color.
	 * @return string
	 */
	public static function resolve( string $value, array $palette ): string {
		for ( $depth = 0; $depth < 3; $depth++ ) {
			if ( 1 === preg_match( '/^var:preset\|color\|([a-z0-9-]+)$/i', $value, $m ) || 1 === preg_match( '/^var\(\s*--wp--preset--color--([a-z0-9-]+)\s*\)$/i', $value, $m ) ) {
				$value = $palette[ $m[1] ] ?? '';
				continue;
			}
			break;
		}
		return $value;
	}

	/**
	 * Parses a CSS color: #rgb, #rgba, #rrggbb, #rrggbbaa, rgb(), rgba(), transparent.
	 *
	 * @param string|null|array<int, float> $color Color.
	 * @return Rgba|null Channels 0..255, alpha 0..1.
	 */
	public static function parse( $color ): ?array {
		if ( is_array( $color ) ) {
			return 4 === count( $color ) ? array( (float) $color[0], (float) $color[1], (float) $color[2], (float) $color[3] ) : null;
		}
		if ( ! is_string( $color ) ) {
			return null;
		}
		$color = strtolower( trim( $color ) );
		if ( 'transparent' === $color ) {
			return array( 0.0, 0.0, 0.0, 0.0 );
		}
		if ( 1 === preg_match( '/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $color, $m ) ) {
			$hex = $m[1];
			if ( strlen( $hex ) <= 4 ) {
				$hex = implode( '', array_map( static fn( string $c ): string => $c . $c, str_split( $hex ) ) );
			}
			$alpha = strlen( $hex ) === 8 ? hexdec( substr( $hex, 6, 2 ) ) / 255 : 1.0;
			return array( (float) hexdec( substr( $hex, 0, 2 ) ), (float) hexdec( substr( $hex, 2, 2 ) ), (float) hexdec( substr( $hex, 4, 2 ) ), (float) $alpha );
		}
		if ( 1 === preg_match( '/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:\s*[,\/]\s*([\d.]+)(%?))?\s*\)$/', $color, $m ) ) {
			$alpha = isset( $m[4] ) && '' !== $m[4] ? (float) $m[4] / ( '%' === ( $m[5] ?? '' ) ? 100 : 1 ) : 1.0;
			return array( min( 255.0, (float) $m[1] ), min( 255.0, (float) $m[2] ), min( 255.0, (float) $m[3] ), max( 0.0, min( 1.0, $alpha ) ) );
		}
		return null;
	}

	/**
	 * A color drawn over an opaque one.
	 *
	 * @param array $fore Foreground.
	 * @phpstan-param Rgba|null $fore
	 * @param array $back Background (opaque).
	 * @phpstan-param Rgba|null $back
	 * @return Rgba|null
	 */
	private static function over( ?array $fore, ?array $back ): ?array {
		if ( null === $fore || null === $back ) {
			return null;
		}
		$a = $fore[3];
		return array(
			$fore[0] * $a + $back[0] * ( 1 - $a ),
			$fore[1] * $a + $back[1] * ( 1 - $a ),
			$fore[2] * $a + $back[2] * ( 1 - $a ),
			1.0,
		);
	}

	/**
	 * CSS for a parsed color.
	 *
	 * @param array $rgba Color.
	 * @phpstan-param Rgba|null $rgba
	 * @return string
	 */
	private static function css( ?array $rgba ): string {
		if ( null === $rgba ) {
			return '';
		}
		return sprintf( 'rgb(%d,%d,%d)', (int) round( $rgba[0] ), (int) round( $rgba[1] ), (int) round( $rgba[2] ) );
	}

	/**
	 * WCAG contrast ratio of two opaque colors.
	 *
	 * @param array $a One.
	 * @phpstan-param Rgba $a
	 * @param array $b The other.
	 * @phpstan-param Rgba $b
	 * @return float
	 */
	public static function ratio( array $a, array $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * Relative luminance.
	 *
	 * @param array $rgba Color.
	 * @phpstan-param Rgba $rgba
	 * @return float
	 */
	private static function luminance( array $rgba ): float {
		$channel = static function ( float $value ): float {
			$value /= 255;
			return $value <= 0.04045 ? $value / 12.92 : ( ( $value + 0.055 ) / 1.055 ) ** 2.4;
		};
		return 0.2126 * $channel( $rgba[0] ) + 0.7152 * $channel( $rgba[1] ) + 0.0722 * $channel( $rgba[2] );
	}
}
