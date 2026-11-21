<?php
/**
 * Validation of a variation's data (theme.json shape), whatever its source: an imported file or a site's styles.
 *
 * Rebuilds a clean copy and lists what it left out, so the preview can say so:
 *
 * - only `title`, `description`, `settings` and `styles` are kept; block style partials (`blockTypes`) are refused;
 * - settings and styles go through core's own checks (`WP_Theme_JSON::remove_insecure_properties()`: unknown keys
 *   and unsafe CSS values out). Core expects presets grouped by origin, as the Site Editor saves them, while variation
 *   files list them directly: they are grouped as "theme" for the check, then ungrouped again;
 * - nothing that loads a file: any value with `url(`, background images, custom CSS with `url(` or `@import`;
 * - custom CSS (`css`) only for users who may edit CSS and add unfiltered HTML, never with `<`;
 * - font families rebuilt field by field; every font source must be a file of the package (see Package), never an
 *   address on another host.
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

use WP_Error;
use WP_Theme_JSON;
use WP_Theme_JSON_Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cleans variation data.
 *
 * @phpstan-import-type Data from Library
 * @phpstan-type Clean array{title: string, description: string, data: Data, notes: list<string>, css: bool}
 */
final class Sanitizer {

	/**
	 * Font face properties kept, with their rules. `sizeAdjust` and the three overrides (WordPress prints them as
	 * `size-adjust`, `ascent-override`... since 6.4) are percentages from 1 to 999.
	 */
	public const FACE_RULES = array(
		'fontStyle'       => '/^(normal|italic|oblique( -?\d{1,3}deg){0,2})$/',
		'fontWeight'      => '/^(normal|bold|\d{1,4}( \d{1,4})?)$/',
		'fontDisplay'     => '/^(auto|block|swap|fallback|optional)$/',
		'fontStretch'     => '/^(normal|(ultra-|extra-|semi-)?(condensed|expanded)|\d{1,3}%( \d{1,3}%)?)$/',
		'unicodeRange'    => '/^[Uu]\+[0-9A-Fa-f?]{1,6}(-[0-9A-Fa-f]{1,6})?(,\s*[Uu]\+[0-9A-Fa-f?]{1,6}(-[0-9A-Fa-f]{1,6})?)*$/',
		'sizeAdjust'      => '/^\d{1,3}(\.\d{1,2})?%$/',
		'ascentOverride'  => '/^(normal|\d{1,3}(\.\d{1,2})?%)$/',
		'descentOverride' => '/^(normal|\d{1,3}(\.\d{1,2})?%)$/',
		'lineGapOverride' => '/^(normal|\d{1,3}(\.\d{1,2})?%)$/',
	);

	public const CSS_MAX = 51200;

	/**
	 * Cleans a decoded variation.
	 *
	 * @param array<mixed>                                          $raw       Decoded JSON.
	 * @param string                                                $fallback  Title when the file has none.
	 * @param bool                                                  $allow_css Whether custom CSS may be kept.
	 * @param callable(string, string): (string|WP_Error|null)|null $font_src Maps a font source and its family slug to
	 *                                                            a stored source, an error (with a note) or null (left
	 *                                                            out). Null: every source is kept only if it is
	 *                                                            already a `windrose-livery:` file.
	 * @return Clean|WP_Error
	 */
	public static function clean( array $raw, string $fallback, bool $allow_css, ?callable $font_src = null ) {
		if ( isset( $raw['blockTypes'] ) ) {
			return new WP_Error( 'windrose_livery_partial', __( 'This file is a block style for some blocks only, not a full style variation.', 'windrose-livery' ) );
		}
		$version = $raw['version'] ?? WP_Theme_JSON::LATEST_SCHEMA;
		if ( ! is_int( $version ) || $version < 1 || $version > WP_Theme_JSON::LATEST_SCHEMA ) {
			/* translators: %d: highest theme.json version this WordPress knows. */
			return new WP_Error( 'windrose_livery_version', sprintf( __( 'This file uses a theme.json version this WordPress does not know (it knows up to %d).', 'windrose-livery' ), WP_Theme_JSON::LATEST_SCHEMA ) );
		}
		foreach ( array( 'settings', 'styles' ) as $key ) {
			if ( isset( $raw[ $key ] ) && ( ! is_array( $raw[ $key ] ) || ( array() !== $raw[ $key ] && array_is_list( $raw[ $key ] ) ) ) ) {
				return new WP_Error( 'windrose_livery_shape', __( 'This file is not a style variation: its settings or styles have the wrong shape.', 'windrose-livery' ) );
			}
		}
		if ( ! is_array( $raw['settings'] ?? null ) && ! is_array( $raw['styles'] ?? null ) ) {
			return new WP_Error( 'windrose_livery_empty', __( 'This file is not a style variation: it has no settings and no styles.', 'windrose-livery' ) );
		}

		$data  = array(
			'version'  => $version,
			'settings' => is_array( $raw['settings'] ?? null ) ? $raw['settings'] : array(),
			'styles'   => is_array( $raw['styles'] ?? null ) ? $raw['styles'] : array(),
		);
		$data  = WP_Theme_JSON_Schema::migrate( $data, 'theme' );
		$notes = array();
		foreach ( array_keys( $raw ) as $key ) {
			if ( ! in_array( $key, array( '$schema', 'version', 'title', 'description', 'settings', 'styles' ), true ) ) {
				/* translators: %s: a key of the file. */
				$notes[] = sprintf( __( 'Left out: %s (not part of a style variation).', 'windrose-livery' ), (string) $key );
			}
		}

		$settings = is_array( $data['settings'] ?? null ) ? $data['settings'] : array();
		$families = $settings['typography']['fontFamilies'] ?? null;
		if ( is_array( $settings['typography'] ?? null ) ) {
			unset( $settings['typography']['fontFamilies'] );
			if ( array() === $settings['typography'] ) {
				unset( $settings['typography'] );
			}
		}
		$settings = self::drop_files( $settings, 'settings', $notes );
		$settings = self::clean_settings( is_array( $settings ) ? $settings : array(), $notes );
		if ( is_array( $families ) ) {
			$families = self::clean_families( $families, $font_src, $notes );
			if ( array() !== $families ) {
				$settings['typography']['fontFamilies'] = $families;
			}
		}

		$styles = is_array( $data['styles'] ?? null ) ? $data['styles'] : array();
		$css    = false;
		$styles = self::clean_styles( $styles, $allow_css, $notes, $css );

		$title = is_string( $raw['title'] ?? null ) ? $raw['title'] : $fallback;
		$title = trim( sanitize_text_field( $title ) );
		$title = '' === $title ? trim( sanitize_text_field( $fallback ) ) : $title;
		if ( '' === $title || '' === sanitize_title( $title ) ) {
			return new WP_Error( 'windrose_livery_title', __( 'The style variation needs a title.', 'windrose-livery' ) );
		}
		$description = is_string( $raw['description'] ?? null ) ? mb_substr( sanitize_text_field( $raw['description'] ), 0, 200 ) : '';

		return array(
			'title'       => mb_substr( $title, 0, Library::TITLE_MAX ),
			'description' => $description,
			'data'        => array(
				'settings' => $settings,
				'styles'   => $styles,
			),
			'notes'       => array_values( array_unique( $notes ) ),
			'css'         => $css,
		);
	}

	/**
	 * Settings through core's checks.
	 *
	 * @param array<mixed> $settings Settings (font families already taken out).
	 * @param array        $notes    Notes, added to.
	 * @phpstan-param list<string> $notes
	 * @return array<string, mixed>
	 */
	private static function clean_settings( array $settings, array &$notes ): array {
		if ( array() === $settings ) {
			return array();
		}
		$custom = $settings['custom'] ?? null;
		unset( $settings['custom'] );
		$wrapped = self::group_presets( $settings );
		$secured = WP_Theme_JSON::remove_insecure_properties(
			array(
				'version'  => WP_Theme_JSON::LATEST_SCHEMA,
				'settings' => $wrapped,
			),
			'theme'
		);
		$kept    = is_array( $secured['settings'] ?? null ) ? self::ungroup_presets( $secured['settings'] ) : array();
		$result  = self::keep_paths( $settings, self::leaf_paths( $kept, 'settings' ), 'settings', $notes );
		$result  = is_array( $result ) ? $result : array();
		if ( is_array( $custom ) ) {
			$custom = self::clean_custom( $custom, 'settings.custom', $notes );
			if ( array() !== $custom ) {
				$result['custom'] = $custom;
			}
		}
		return $result;
	}

	/**
	 * Custom properties (`settings.custom`, printed as `--wp--custom--*` variables): names of letters, digits and
	 * hyphens, values that cannot leave their declaration.
	 *
	 * @param array<mixed> $node  Node.
	 * @param string       $path  Dotted path.
	 * @param array        $notes Notes, added to.
	 * @phpstan-param list<string> $notes
	 * @return array<string, mixed>
	 */
	private static function clean_custom( array $node, string $path, array &$notes ): array {
		$out = array();
		foreach ( $node as $key => $value ) {
			$key = (string) $key;
			if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $key ) ) {
				/* translators: %s: setting. */
				$notes[] = sprintf( __( 'Left out: %s (unknown, or not a safe value).', 'windrose-livery' ), str_replace( '.', ' › ', $path . '.' . $key ) );
				continue;
			}
			if ( is_array( $value ) && ! array_is_list( $value ) ) {
				$child = self::clean_custom( $value, $path . '.' . $key, $notes );
				if ( array() !== $child ) {
					$out[ $key ] = $child;
				}
			} elseif ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && strlen( $value ) <= 500 && 1 === preg_match( '/^[^;{}<>\\\\]*$/', $value ) ) ) {
				$out[ $key ] = $value;
			} else {
				/* translators: %s: setting. */
				$notes[] = sprintf( __( 'Left out: %s (unknown, or not a safe value).', 'windrose-livery' ), str_replace( '.', ' › ', $path . '.' . $key ) );
			}
		}
		return $out;
	}

	/**
	 * Styles through core's checks, without anything that loads a file; custom CSS only when allowed.
	 *
	 * @param array<mixed> $styles    Styles.
	 * @param bool         $allow_css Whether custom CSS may be kept.
	 * @param array        $notes     Notes, added to.
	 * @phpstan-param list<string> $notes
	 * @param bool         $css       Set to true when custom CSS was kept.
	 * @return array<string, mixed>
	 */
	private static function clean_styles( array $styles, bool $allow_css, array &$notes, bool &$css ): array {
		$custom = array();
		$styles = self::take_css( $styles, array( 'styles' ), $custom );
		$styles = self::drop_files( $styles, 'styles', $notes );
		$styles = is_array( $styles ) ? $styles : array();

		$secured = WP_Theme_JSON::remove_insecure_properties(
			array(
				'version' => WP_Theme_JSON::LATEST_SCHEMA,
				'styles'  => $styles,
			),
			'theme'
		);
		$kept    = is_array( $secured['styles'] ?? null ) ? $secured['styles'] : array();
		$result  = self::keep_paths( $styles, self::leaf_paths( $kept, 'styles' ), 'styles', $notes );
		$result  = is_array( $result ) ? $result : array();

		foreach ( $custom as $path => $value ) {
			$label = str_replace( '.', ' › ', $path );
			if ( ! $allow_css ) {
				/* translators: %s: where the CSS was. */
				$notes[] = sprintf( __( 'Left out: custom CSS (%s). Only users who may edit CSS and add unfiltered HTML can bring custom CSS.', 'windrose-livery' ), $label );
				continue;
			}
			if ( strlen( $value ) > self::CSS_MAX || 1 === preg_match( '/<|@import|url\s*\(|expression\s*\(|javascript:/i', $value ) ) {
				/* translators: %s: where the CSS was. */
				$notes[] = sprintf( __( 'Left out: custom CSS (%s), because it loads files, holds HTML or is longer than 50 KB.', 'windrose-livery' ), $label );
				continue;
			}
			$parts = array_slice( explode( '.', $path ), 1 );
			$node  = &$result;
			foreach ( $parts as $part ) {
				if ( ! isset( $node[ $part ] ) || ! is_array( $node[ $part ] ) ) {
					$node[ $part ] = array();
				}
				$node = &$node[ $part ];
			}
			$node['css'] = $value;
			unset( $node );
			$css = true;
		}//end foreach
		return $result;
	}

	/**
	 * Takes custom CSS (`css` at the root of styles and of each block or element) out of the styles.
	 *
	 * @param array<mixed>          $node   Node.
	 * @param array                 $path   Path of the node.
	 * @phpstan-param list<string> $path
	 * @param array<string, string> $custom Collected CSS by dotted path of its node.
	 * @return array<mixed>
	 */
	private static function take_css( array $node, array $path, array &$custom ): array {
		foreach ( $node as $key => $value ) {
			if ( 'css' === $key ) {
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$custom[ implode( '.', $path ) ] = $value;
				}
				unset( $node[ $key ] );
			} elseif ( is_array( $value ) ) {
				$node[ $key ] = self::take_css( $value, array_merge( $path, array( (string) $key ) ), $custom );
			}
		}
		return $node;
	}

	/**
	 * Removes values that would load a file: any string with `url(`, and background images.
	 *
	 * @param mixed  $value Value.
	 * @param string $path  Dotted path.
	 * @param array  $notes Notes, added to.
	 * @phpstan-param list<string> $notes
	 * @return mixed Null when removed.
	 */
	private static function drop_files( $value, string $path, array &$notes ) {
		if ( is_string( $value ) ) {
			if ( 1 === preg_match( '/url\s*\(|@import/i', $value ) ) {
				/* translators: %s: setting or style. */
				$notes[] = sprintf( __( 'Left out: %s (it would load a file).', 'windrose-livery' ), str_replace( '.', ' › ', $path ) );
				return null;
			}
			return $value;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $child ) {
			if ( 'backgroundImage' === $key ) {
				/* translators: %s: setting or style. */
				$notes[] = sprintf( __( 'Left out: %s (images are not part of a style variation here).', 'windrose-livery' ), str_replace( '.', ' › ', $path . '.' . $key ) );
				unset( $value[ $key ] );
				continue;
			}
			$child = self::drop_files( $child, $path . '.' . $key, $notes );
			if ( null === $child || ( array() === $child && is_array( $value[ $key ] ) && array() !== $value[ $key ] ) ) {
				unset( $value[ $key ] );
			} else {
				$value[ $key ] = $child;
			}
		}
		return $value;
	}

	/**
	 * Keeps the leaves of `$value` whose dotted path is in `$allowed`; notes the others.
	 *
	 * @param mixed               $value   Original value.
	 * @param array<string, true> $allowed Dotted leaf paths core kept (lists count as leaves).
	 * @param string              $path    Dotted path of `$value`.
	 * @param array               $notes   Notes, added to.
	 * @phpstan-param list<string> $notes
	 * @return mixed Null when nothing is kept.
	 */
	private static function keep_paths( $value, array $allowed, string $path, array &$notes ) {
		if ( array() === $value ) {
			return null;
		}
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			if ( isset( $allowed[ $path ] ) ) {
				return $value;
			}
			/* translators: %s: setting or style. */
			$notes[] = sprintf( __( 'Left out: %s (unknown, or not a safe value).', 'windrose-livery' ), str_replace( '.', ' › ', $path ) );
			return null;
		}
		$out = array();
		foreach ( $value as $key => $child ) {
			$kept = self::keep_paths( $child, $allowed, $path . '.' . $key, $notes );
			if ( null !== $kept ) {
				$out[ $key ] = $kept;
			}
		}
		return array() === $out ? null : $out;
	}

	/**
	 * Dotted leaf paths of a structure (lists are leaves).
	 *
	 * @param array<mixed> $value  Structure.
	 * @param string       $prefix Dotted path of the structure.
	 * @return array<string, true>
	 */
	private static function leaf_paths( array $value, string $prefix ): array {
		$paths = array();
		foreach ( $value as $key => $child ) {
			$path = $prefix . '.' . $key;
			if ( is_array( $child ) && array() !== $child && ! array_is_list( $child ) ) {
				$paths += self::leaf_paths( $child, $path );
			} else {
				$paths[ $path ] = true;
			}
		}
		return $paths;
	}

	/**
	 * Groups preset lists under "theme", at the root of settings and of each block's settings.
	 *
	 * @param array<mixed> $settings Settings.
	 * @return array<mixed>
	 */
	private static function group_presets( array $settings ): array {
		$settings = self::map_presets( $settings, static fn( array $presets ): array => array( 'theme' => $presets ) );
		if ( is_array( $settings['blocks'] ?? null ) ) {
			foreach ( $settings['blocks'] as $name => $block ) {
				if ( is_array( $block ) ) {
					$settings['blocks'][ $name ] = self::map_presets( $block, static fn( array $presets ): array => array( 'theme' => $presets ) );
				}
			}
		}
		return $settings;
	}

	/**
	 * Undoes group_presets().
	 *
	 * @param array<mixed> $settings Settings.
	 * @return array<mixed>
	 */
	private static function ungroup_presets( array $settings ): array {
		$unwrap   = static fn( array $grouped ): array => is_array( $grouped['theme'] ?? null ) ? $grouped['theme'] : $grouped;
		$settings = self::map_presets( $settings, $unwrap, false );
		if ( is_array( $settings['blocks'] ?? null ) ) {
			foreach ( $settings['blocks'] as $name => $block ) {
				if ( is_array( $block ) ) {
					$settings['blocks'][ $name ] = self::map_presets( $block, $unwrap, false );
				}
			}
		}
		return $settings;
	}

	/**
	 * Applies a callback to each preset of a settings node.
	 *
	 * @param array<mixed> $node     Settings node.
	 * @param callable     $callback Callback.
	 * @phpstan-param callable(array<mixed>): array<mixed> $callback
	 * @param bool         $lists    True: only lists are mapped; false: only non-lists.
	 * @return array<mixed>
	 */
	private static function map_presets( array $node, callable $callback, bool $lists = true ): array {
		foreach ( WP_Theme_JSON::PRESETS_METADATA as $meta ) {
			$path  = $meta['path'];
			$value = _wp_array_get( $node, $path, null );
			if ( ! is_array( $value ) || array() === $value || array_is_list( $value ) !== $lists ) {
				continue;
			}
			_wp_array_set( $node, $path, $callback( $value ) );
		}
		return $node;
	}

	/**
	 * Rebuilds font families field by field.
	 *
	 * @param array<mixed>                                          $families Font families.
	 * @param callable(string, string): (string|WP_Error|null)|null $font_src Source mapper (see clean()).
	 * @param array                                                 $notes    Notes, added to.
	 * @phpstan-param list<string> $notes
	 * @return list<array<string, mixed>>
	 */
	public static function clean_families( array $families, ?callable $font_src, array &$notes ): array {
		$out   = array();
		$slugs = array();
		foreach ( $families as $family ) {
			if ( ! is_array( $family ) ) {
				continue;
			}
			$name  = is_string( $family['name'] ?? null ) ? trim( sanitize_text_field( $family['name'] ) ) : '';
			$slug  = is_string( $family['slug'] ?? null ) ? sanitize_title( $family['slug'] ) : '';
			$stack = is_string( $family['fontFamily'] ?? null ) ? trim( $family['fontFamily'] ) : '';
			if ( '' === $slug || '' === $stack || ! self::is_family_list( $stack ) || isset( $slugs[ $slug ] ) ) {
				/* translators: %s: font family name or slug. */
				$notes[] = sprintf( __( 'Left out: the font family %s (missing or unsafe name, or listed twice).', 'windrose-livery' ), '' !== $name ? $name : ( '' !== $slug ? $slug : '?' ) );
				continue;
			}
			$clean = array(
				'name'       => '' !== $name ? $name : $slug,
				'slug'       => $slug,
				'fontFamily' => $stack,
			);
			$faces = array();
			foreach ( is_array( $family['fontFace'] ?? null ) ? $family['fontFace'] : array() as $face ) {
				$face = is_array( $face ) ? self::clean_face( $face, $slug, $font_src, $notes ) : null;
				if ( null !== $face ) {
					$faces[] = $face;
				}
			}
			if ( array() !== $faces ) {
				$clean['fontFace'] = $faces;
			} elseif ( is_array( $family['fontFace'] ?? null ) && array() !== $family['fontFace'] ) {
				/* translators: %s: font family name. */
				$notes[] = sprintf( __( 'The font family %s has no usable font file: visitors see the next font of its list.', 'windrose-livery' ), $clean['name'] );
			}
			$slugs[ $slug ] = true;
			$out[]          = $clean;
		}//end foreach
		return $out;
	}

	/**
	 * A font face property, checked, or null.
	 *
	 * @param string $key   Property (camelCase, as in theme.json).
	 * @param mixed  $value Value.
	 * @return string|null
	 */
	public static function face_value( string $key, $value ): ?string {
		if ( is_int( $value ) ) {
			$value = (string) $value;
		}
		$pattern = self::FACE_RULES[ $key ] ?? null;
		return null !== $pattern && is_string( $value ) && strlen( $value ) <= 4000 && 1 === preg_match( $pattern, trim( $value ) ) ? trim( $value ) : null;
	}

	/**
	 * One font face, or null.
	 *
	 * @param array<mixed>                                          $face     Font face.
	 * @param string                                                $family   Family slug.
	 * @param callable(string, string): (string|WP_Error|null)|null $font_src Source mapper.
	 * @param array                                                 $notes    Notes, added to.
	 * @phpstan-param list<string> $notes
	 * @return array<string, mixed>|null
	 */
	private static function clean_face( array $face, string $family, ?callable $font_src, array &$notes ): ?array {
		$name = is_string( $face['fontFamily'] ?? null ) ? trim( $face['fontFamily'] ) : '';
		if ( '' === $name || ! self::is_family_list( $name ) || str_contains( $name, ',' ) ) {
			return null;
		}
		$clean = array( 'fontFamily' => $name );
		foreach ( array_keys( self::FACE_RULES ) as $key ) {
			$value = self::face_value( $key, $face[ $key ] ?? null );
			if ( null !== $value ) {
				$clean[ $key ] = $value;
			}
		}
		$src = array();
		foreach ( (array) ( $face['src'] ?? array() ) as $one ) {
			if ( ! is_string( $one ) || '' === trim( $one ) ) {
				continue;
			}
			$one    = trim( $one );
			$mapped = null === $font_src
				? ( str_starts_with( $one, Fonts::PREFIX ) && Fonts::valid_name( substr( $one, strlen( Fonts::PREFIX ) ) ) ? $one : null )
				: $font_src( $one, $family );
			if ( $mapped instanceof WP_Error ) {
				$notes[] = $mapped->get_error_message();
			} elseif ( is_string( $mapped ) ) {
				$src[] = $mapped;
			} elseif ( 1 === preg_match( '#^([a-z]+:)?//#i', $one ) || str_starts_with( $one, 'data:' ) ) {
				/* translators: %s: font address. */
				$notes[] = sprintf( __( 'Left out: the font %s, because fonts are never loaded from another host.', 'windrose-livery' ), esc_url_raw( $one ) );
			} else {
				/* translators: %s: font file. */
				$notes[] = sprintf( __( 'Left out: the font file %s (not found in the package).', 'windrose-livery' ), sanitize_text_field( $one ) );
			}
		}
		if ( array() === $src ) {
			return null;
		}
		$clean['src'] = $src;
		return $clean;
	}

	/**
	 * Whether a CSS font-family list holds only names, quotes, commas and spaces.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function is_family_list( string $value ): bool {
		return strlen( $value ) <= 600 && 1 === preg_match( '/^[\p{L}\p{N}\s,\'"_-]+$/u', $value );
	}
}
