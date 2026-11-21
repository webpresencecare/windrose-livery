<?php
/**
 * Light and dark (Livery 0.4): a style's other palette as "twin" colours in its own palette.
 *
 * For each colour of the style (`base`…), a twin with the slug `windrose-livery-dark-<slug>` (or
 * `windrose-livery-light-<slug>` for a dark style) and a name such as "Dark: Background", edited like any colour in
 * Styles, Colors, Palette (core keeps a colour's slug when it is renamed), saved, exported and applied with the
 * style. The style's own colours are the default; the twins show when the visitor picks the other scheme with the
 * switch block, or, when the site follows the browser preference, when the browser prefers it.
 *
 * On the page: `:root[data-windrose-livery-scheme="dark"]` points each colour's variable at its twin's
 * (`--wp--preset--color--base: var(--wp--preset--color--windrose-livery-dark-base)`), so everything that uses
 * the palette follows, gradients built on it too; hand-typed colours, duotone and images stay. A tiny script in the
 * head applies the visitor's choice (browser storage `windrose-livery-scheme`, no cookie, nothing sent) before the
 * page shows: it stays an inline classic script, as it must run before the first paint (a script module runs after
 * the page is parsed). With "Follow the browser preference" the same rules sit in a `prefers-color-scheme` query,
 * which works without JavaScript.
 *
 * Per site: option `windrose_livery_scheme` = `{ switch: bool, follow: bool }`, both off by default.
 *
 * The block `windrose/light-dark-switch` ("Light and dark switch"): a button (`aria-pressed`, "Dark mode"), inside a
 * Navigation block as one of its items (core's Navigation accepts it through its `allowedBlocks`) or anywhere. It
 * shows nothing while the switch option is off or the site's style has no twins. With the option on, Livery also
 * places it after the header's Navigation block (core's Block Hooks: it can be moved or removed in the Site Editor).
 * On the page it is an Interactivity API block (store `windrose-livery/light-dark-switch`, the script module
 * `build/light-dark-switch-view.js`): the server gives the scheme data as config and whether the page shows dark as
 * state, and renders `aria-pressed` from it, so the button is right before any script runs.
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

use WP_Block;
use WP_Block_Template;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Light and dark.
 */
final class Schemes {

	public const OPTION  = 'windrose_livery_scheme';
	public const PREFIX  = 'windrose-livery-';
	public const BLOCK   = 'windrose/light-dark-switch';
	public const HANDLE  = 'windrose-livery-light-dark-switch';
	public const STORE   = 'windrose-livery/light-dark-switch';
	public const STORAGE = 'windrose-livery-scheme';
	public const ATTR    = 'data-windrose-livery-scheme';

	/**
	 * Hooks the page rules, the head script, the block and its placement.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_filter( 'block_type_metadata', array( __CLASS__, 'navigation_metadata' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'page_css' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'head_script' ), 1 );
		add_filter( 'hooked_block_types', array( __CLASS__, 'hook_block' ), 10, 4 );
	}

	/**
	 * The options of a site.
	 *
	 * @param int $site Site (0: the current one).
	 * @return array{switch: bool, follow: bool}
	 */
	public static function settings( int $site = 0 ): array {
		$value = is_multisite() && $site > 0 && get_current_blog_id() !== $site ? get_blog_option( $site, self::OPTION, array() ) : get_option( self::OPTION, array() );
		$value = is_array( $value ) ? $value : array();
		return array(
			'switch' => ! empty( $value['switch'] ),
			'follow' => ! empty( $value['follow'] ),
		);
	}

	/**
	 * Saves the options of a site.
	 *
	 * @param int  $site   Site (the current one on a single installation).
	 * @param bool $on     Show the switch.
	 * @param bool $follow Follow the browser preference.
	 * @return void
	 */
	public static function save_settings( int $site, bool $on, bool $follow ): void {
		$value = array(
			'switch' => $on,
			'follow' => $follow,
		);
		if ( is_multisite() ) {
			update_blog_option( $site, self::OPTION, $value );
		} else {
			update_option( self::OPTION, $value );
		}
	}

	/**
	 * The twins of a palette (a plain list of colours): the other scheme and, for each colour that has a twin, the
	 * twin's slug.
	 *
	 * @param array $palette Colours (slug, color, name).
	 * @phpstan-param list<array{slug: string, color: string, name?: string}> $palette
	 * @return array{scheme: string, map: array<string, string>}
	 */
	public static function twins( array $palette ): array {
		$slugs = array();
		foreach ( $palette as $color ) {
			$slugs[ $color['slug'] ] = true;
		}
		$found = array(
			'dark'  => array(),
			'light' => array(),
		);
		foreach ( array_keys( $slugs ) as $slug ) {
			if ( 1 === preg_match( '/^' . preg_quote( self::PREFIX, '/' ) . '(dark|light)-([a-z0-9-]+)$/', (string) $slug, $m ) && isset( $slugs[ $m[2] ] ) && ! str_starts_with( $m[2], self::PREFIX ) ) {
				$found[ $m[1] ][ $m[2] ] = (string) $slug;
			}
		}
		$scheme = count( $found['dark'] ) >= count( $found['light'] ) ? 'dark' : 'light';
		if ( array() === $found[ $scheme ] ) {
			return array(
				'scheme' => '',
				'map'    => array(),
			);
		}
		return array(
			'scheme' => $scheme,
			'map'    => $found[ $scheme ],
		);
	}

	/**
	 * The current site's palette (every origin, later ones replacing earlier ones by slug), as a plain list.
	 *
	 * @return list<array{slug: string, color: string, name?: string}>
	 */
	public static function current_palette(): array {
		$palette = wp_get_global_settings( array( 'color', 'palette' ) );
		$palette = is_array( $palette ) ? $palette : array();
		$lists   = array_is_list( $palette ) ? array( $palette ) : array( $palette['default'] ?? array(), $palette['theme'] ?? array(), $palette['custom'] ?? array() );
		$out     = array();
		foreach ( $lists as $list ) {
			foreach ( is_array( $list ) ? $list : array() as $color ) {
				if ( is_array( $color ) && is_string( $color['slug'] ?? null ) && is_string( $color['color'] ?? null ) ) {
					$out[ $color['slug'] ] = array(
						'slug'  => $color['slug'],
						'color' => $color['color'],
					);
				}
			}
		}
		return array_values( $out );
	}

	/**
	 * The current site's twins.
	 *
	 * @return array{scheme: string, map: array<string, string>}
	 */
	public static function current(): array {
		return self::twins( self::current_palette() );
	}

	/**
	 * The page rules.
	 *
	 * @param array{scheme: string, map: array<string, string>} $twins  Twins.
	 * @param bool                                              $follow Follow the browser preference.
	 * @return string CSS.
	 */
	public static function css( array $twins, bool $follow ): string {
		if ( '' === $twins['scheme'] || array() === $twins['map'] ) {
			return '';
		}
		$scheme = $twins['scheme'];
		$own    = 'dark' === $scheme ? 'light' : 'dark';
		$decl   = 'color-scheme:' . $scheme . ';--wp--custom--control-scheme:' . $scheme . ';';
		foreach ( $twins['map'] as $base => $twin ) {
			$base  = (string) preg_replace( '/[^a-z0-9-]/', '', (string) $base );
			$twin  = (string) preg_replace( '/[^a-z0-9-]/', '', $twin );
			$decl .= '--wp--preset--color--' . $base . ':var(--wp--preset--color--' . $twin . ');';
		}
		$css = ':root[' . self::ATTR . '="' . $scheme . '"]{' . $decl . '}';
		if ( $follow ) {
			$css .= '@media (prefers-color-scheme:' . $scheme . '){:root:not([' . self::ATTR . '="' . $own . '"]){' . $decl . '}}';
		}
		return $css;
	}

	/**
	 * Whether light and dark is on for the current site: twins, and an option on.
	 *
	 * @return array{scheme: string, map: array<string, string>}|null
	 */
	private static function active(): ?array {
		$settings = self::settings();
		if ( ! $settings['switch'] && ! $settings['follow'] ) {
			return null;
		}
		$twins = self::current();
		return '' === $twins['scheme'] ? null : $twins;
	}

	/**
	 * Prints the page rules (front end).
	 *
	 * @return void
	 */
	public static function page_css(): void {
		$twins = self::active();
		if ( null === $twins ) {
			return;
		}
		wp_register_style( 'windrose-livery-scheme', false, array(), WINDROSE_LIVERY_VERSION );
		wp_add_inline_style( 'windrose-livery-scheme', self::css( $twins, self::settings()['follow'] ) );
		wp_enqueue_style( 'windrose-livery-scheme' );
	}

	/**
	 * The script that applies the visitor's choice before the page shows (switch option on).
	 *
	 * @return string JavaScript.
	 */
	public static function script(): string {
		return '(function(){try{var s=window.localStorage.getItem(' . wp_json_encode( self::STORAGE ) . ');if(s==="dark"||s==="light"){document.documentElement.setAttribute(' . wp_json_encode( self::ATTR ) . ',s);}}catch(e){}})();';
	}

	/**
	 * Prints the script early in the head.
	 *
	 * @return void
	 */
	public static function head_script(): void {
		if ( null === self::active() || ! self::settings()['switch'] ) {
			return;
		}
		wp_print_inline_script_tag( self::script(), array( 'id' => 'windrose-livery-scheme-js' ) );
	}

	/**
	 * Registers the block and its editor script (the visitor side, a script module, comes from block.json).
	 *
	 * @return void
	 */
	public static function register_block(): void {
		$asset_file = WINDROSE_LIVERY_DIR . '/build/light-dark-switch.asset.php';
		if ( is_readable( $asset_file ) ) {
			$asset = require $asset_file;
			$deps  = is_array( $asset ) && is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array();
			$ver   = is_array( $asset ) && is_string( $asset['version'] ?? null ) ? $asset['version'] : WINDROSE_LIVERY_VERSION;
			$src   = plugins_url( 'build/light-dark-switch.js', WINDROSE_LIVERY_FILE );
			wp_register_script( self::HANDLE, '' === $src ? false : $src, $deps, $ver, array( 'in_footer' => false ) );
			wp_set_script_translations( self::HANDLE, 'windrose-livery', WINDROSE_LIVERY_DIR . '/languages' );
			wp_add_inline_script(
				self::HANDLE,
				'window.windroseLiveryScheme = ' . wp_json_encode(
					array(
						'switch' => self::settings()['switch'],
						'scheme' => self::current()['scheme'],
					)
				) . ';',
				'before'
			);
		}
		register_block_type(
			WINDROSE_LIVERY_DIR . '/blocks/light-dark-switch',
			array( 'render_callback' => array( __CLASS__, 'render' ) )
		);
	}

	/**
	 * Lets core's Navigation block hold the switch.
	 *
	 * @param mixed $metadata Block metadata.
	 * @return mixed
	 */
	public static function navigation_metadata( $metadata ) {
		if ( ! is_array( $metadata ) || 'core/navigation' !== ( $metadata['name'] ?? '' ) ) {
			return $metadata;
		}
		$allowed = is_array( $metadata['allowedBlocks'] ?? null ) ? $metadata['allowedBlocks'] : array();
		if ( ! in_array( self::BLOCK, $allowed, true ) ) {
			$allowed[] = self::BLOCK;
		}
		$metadata['allowedBlocks'] = $allowed;
		return $metadata;
	}

	/**
	 * Places the switch after the header's Navigation block while the option is on (core's Block Hooks).
	 *
	 * @param mixed  $hooked   Hooked block types.
	 * @param string $position Position.
	 * @param string $anchor   Anchor block type.
	 * @param mixed  $context  Template, template part, pattern or navigation post.
	 * @return mixed
	 */
	public static function hook_block( $hooked, $position, $anchor, $context ) {
		if ( ! is_array( $hooked ) || 'after' !== $position || 'core/navigation' !== $anchor || ! self::in_header( $context ) ) {
			return $hooked;
		}
		if ( ! self::settings()['switch'] || '' === self::current()['scheme'] ) {
			return $hooked;
		}
		if ( ! in_array( self::BLOCK, $hooked, true ) ) {
			$hooked[] = self::BLOCK;
		}
		return $hooked;
	}

	/**
	 * Whether a Block Hooks context is a header: a header template part, or a pattern for headers.
	 *
	 * @param mixed $context Context.
	 * @return bool
	 */
	public static function in_header( $context ): bool {
		if ( $context instanceof WP_Block_Template ) {
			return 'wp_template_part' === $context->type && 'header' === $context->area;
		}
		if ( is_array( $context ) ) {
			$types = is_array( $context['blockTypes'] ?? null ) ? $context['blockTypes'] : array();
			$cats  = is_array( $context['categories'] ?? null ) ? $context['categories'] : array();
			return in_array( 'core/template-part/header', $types, true ) || in_array( 'header', $cats, true );
		}
		return false;
	}

	/**
	 * The block on a page.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner content (none).
	 * @param WP_Block|null        $block      Block.
	 * @return string
	 */
	public static function render( $attributes = array(), $content = '', $block = null ): string {
		$settings = self::settings();
		$twins    = self::current();
		if ( ! $settings['switch'] || '' === $twins['scheme'] ) {
			return '';
		}
		$attributes = is_array( $attributes ) ? $attributes : array();
		$in_nav     = $block instanceof WP_Block && array_key_exists( 'maxNestingLevel', $block->context );
		$own        = 'dark' === $twins['scheme'] ? 'light' : 'dark';
		$label      = __( 'Dark mode', 'windrose-livery' );
		$show       = ! empty( $attributes['showLabel'] );
		$icon       = '<svg class="windrose-livery-switch__icon" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
			. '<path class="windrose-livery-switch__sun" d="M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10Zm0-5 1 3h-2l1-3Zm0 20-1-3h2l-1 3ZM2 12l3-1v2l-3-1Zm20 0-3 1v-2l3 1ZM4.9 4.9l2.8 1.4-1.4 1.4-1.4-2.8Zm14.2 14.2-2.8-1.4 1.4-1.4 1.4 2.8ZM4.9 19.1l1.4-2.8 1.4 1.4-2.8 1.4ZM19.1 4.9l-1.4 2.8-1.4-1.4 2.8-1.4Z"/>'
			. '<path class="windrose-livery-switch__moon" d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z"/>'
			. '</svg>';
		$text       = $show
			? '<span class="windrose-livery-switch__label">' . esc_html( $label ) . '</span>'
			: '<span class="screen-reader-text">' . esc_html( $label ) . '</span>';
		// The same for every switch on the page: the site's scheme, and its own colours until a script says otherwise.
		wp_interactivity_config(
			self::STORE,
			array(
				'own'       => $own,
				'twin'      => $twins['scheme'],
				'follow'    => $settings['follow'],
				'storage'   => self::STORAGE,
				'attribute' => self::ATTR,
			)
		);
		wp_interactivity_state( self::STORE, array( 'dark' => 'dark' === $own ) );
		$button  = sprintf(
			'<button type="button" class="%1$s" data-windrose-livery-switch data-wp-on--click="actions.toggle" data-wp-init="callbacks.sync" data-wp-bind--aria-pressed="state.dark" aria-pressed="%2$s">%3$s%4$s</button>',
			esc_attr( $in_nav ? 'wp-block-navigation-item__content windrose-livery-switch__button' : 'windrose-livery-switch__button' ),
			'dark' === $own ? 'true' : 'false',
			$icon,
			$text
		);
		$wrapper = get_block_wrapper_attributes(
			array(
				'class'               => ( $in_nav ? 'wp-block-navigation-item ' : '' ) . 'windrose-livery-switch',
				'data-wp-interactive' => self::STORE,
			)
		);
		return $in_nav ? sprintf( '<li %1$s>%2$s</li>', $wrapper, $button ) : sprintf( '<div %1$s>%2$s</div>', $wrapper, $button );
	}
}
