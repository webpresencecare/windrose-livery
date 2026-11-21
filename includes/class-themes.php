<?php
/**
 * Block themes a variation can be made for, and the guess of which one a file was made for.
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

use WP_Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installed block themes.
 */
final class Themes {

	/**
	 * Block themes found in this request.
	 *
	 * @var array<string, array{name: string, parent: string}>|null
	 */
	private static ?array $themes = null;

	/**
	 * Forgets the list (tests install themes).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$themes = null;
	}

	/**
	 * Installed block themes: stylesheet => name, with their parent.
	 *
	 * @return array<string, array{name: string, parent: string}>
	 */
	public static function block_themes(): array {
		if ( null !== self::$themes ) {
			return self::$themes;
		}
		/**
		 * Block themes found.
		 *
		 * @var array<string, array{name: string, parent: string}> $list
		 */
		$list = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			if ( $theme instanceof WP_Theme && $theme->is_block_theme() && ! $theme->errors() ) {
				$list[ (string) $stylesheet ] = array(
					'name'   => (string) $theme->get( 'Name' ),
					'parent' => $theme->get_template() !== $theme->get_stylesheet() ? $theme->get_template() : '',
				);
			}
		}
		uasort( $list, static fn( array $a, array $b ): int => strnatcasecmp( $a['name'], $b['name'] ) );
		self::$themes = $list;
		return $list;
	}

	/**
	 * Whether a stylesheet is an installed block theme.
	 *
	 * @param string $stylesheet Stylesheet.
	 * @return bool
	 */
	public static function exists( string $stylesheet ): bool {
		return isset( self::block_themes()[ $stylesheet ] );
	}

	/**
	 * A theme's name, or its stylesheet when it is not installed.
	 *
	 * @param string $stylesheet Stylesheet.
	 * @return string
	 */
	public static function name( string $stylesheet ): string {
		return self::block_themes()[ $stylesheet ]['name'] ?? $stylesheet;
	}

	/**
	 * Preset names of a theme (its theme.json, or its parent's), as `kind:slug` (colors, font sizes, font families,
	 * spacing sizes).
	 *
	 * @param string $stylesheet Stylesheet.
	 * @return list<string>
	 */
	public static function preset_names( string $stylesheet ): array {
		$theme = wp_get_theme( $stylesheet );
		$file  = $theme->exists() ? $theme->get_file_path( 'theme.json' ) : '';
		if ( '' === $file || ! is_readable( $file ) ) {
			return array();
		}
		$json = wp_json_file_decode( $file, array( 'associative' => true ) );
		return is_array( $json ) && is_array( $json['settings'] ?? null ) ? self::defined_names( $json['settings'] ) : array();
	}

	/**
	 * Preset names defined in settings, as `kind:slug`.
	 *
	 * @param array<mixed> $settings Settings.
	 * @return list<string>
	 */
	private static function defined_names( array $settings ): array {
		$lists = array(
			'color'       => $settings['color']['palette'] ?? null,
			'font-size'   => $settings['typography']['fontSizes'] ?? null,
			'font-family' => $settings['typography']['fontFamilies'] ?? null,
			'spacing'     => $settings['spacing']['spacingSizes'] ?? null,
		);
		$names = array();
		foreach ( $lists as $kind => $list ) {
			foreach ( is_array( $list ) ? $list : array() as $preset ) {
				if ( is_array( $preset ) && is_string( $preset['slug'] ?? null ) ) {
					$names[] = $kind . ':' . $preset['slug'];
				}
			}
		}
		return $names;
	}

	/**
	 * Preset names a variation defines or uses (`var:preset|color|base`, `var(--wp--preset--color--base)`), as
	 * `kind:slug`.
	 *
	 * @param array<mixed> $data Variation (settings, styles).
	 * @return list<string>
	 */
	public static function variation_names( array $data ): array {
		$names = is_array( $data['settings'] ?? null ) ? self::defined_names( $data['settings'] ) : array();
		$json  = (string) wp_json_encode( $data['styles'] ?? array(), JSON_UNESCAPED_SLASHES );
		if ( preg_match_all( '/var(?::preset\|(color|font-size|font-family|spacing)\||\(--wp--preset--(color|font-size|font-family|spacing)--)([a-z0-9-]+)/', $json, $found, PREG_SET_ORDER ) ) {
			foreach ( $found as $match ) {
				$names[] = ( '' !== $match[1] ? $match[1] : $match[2] ) . ':' . $match[3];
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * The theme a variation was most likely made for: the most preset names in common (colors, font sizes, font
	 * families, spacing; Ipsum and Twenty Twenty-Five share their color names since core r65224, but not all their
	 * font sizes). On a tie: the library site's active theme's parent, or the active theme; else a parent before its
	 * children (children get it anyway). Without any name in common: the library site's active theme.
	 *
	 * @param array<mixed> $data Variation (settings, styles).
	 * @return string Stylesheet, or empty when no block theme is installed.
	 */
	public static function detect( array $data ): string {
		$names  = self::variation_names( $data );
		$themes = self::block_themes();
		$active = Library::on_library_site( static fn(): string => get_stylesheet() );
		$score  = 0;
		$tied   = array();
		foreach ( $themes as $stylesheet => $theme ) {
			$common = count( array_intersect( $names, self::preset_names( (string) $stylesheet ) ) );
			if ( $common > 0 && $common > $score ) {
				$score = $common;
				$tied  = array();
			}
			if ( $common > 0 && $common === $score ) {
				$tied[] = (string) $stylesheet;
			}
		}
		if ( array() !== $tied ) {
			$parent = $themes[ $active ]['parent'] ?? '';
			foreach ( array( $parent, $active ) as $choice ) {
				if ( '' !== $choice && in_array( $choice, $tied, true ) ) {
					return $choice;
				}
			}
			foreach ( $tied as $stylesheet ) {
				if ( '' === $themes[ $stylesheet ]['parent'] ) {
					return $stylesheet;
				}
			}
			return $tied[0];
		}
		if ( self::exists( $active ) ) {
			return $active;
		}
		return (string) array_key_first( $themes );
	}
}
