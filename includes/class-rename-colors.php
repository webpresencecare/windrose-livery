<?php
/**
 * Renames Ipsum's old color names in what sites stored before WordPress 7.2-beta4. Core r65224
 * (2026-11-03) gave Ipsum's palette Twenty Twenty-Five's slugs, same values: `theme-1` base, `theme-2` contrast,
 * `theme-3` accent-1, `theme-4` accent-6, `theme-5` accent-5, `theme-6` accent-3.
 *
 * On each site (the whole network with `--network`):
 * - Livery's stored styles (the shared library, a site's own styles) made for Ipsum or a child of it, palette put in
 *   Ipsum's order (`Library::encode()`, as Livery stores them);
 * - the saved styles (`wp_global_styles`) of Ipsum and its child themes, as text: a site's record (`Tracker`) that
 *   matched its saved styles keeps matching them;
 * - block content of every post type (posts, pages, templates, template parts, synced patterns, menus, library
 *   patterns) on sites whose active theme is Ipsum or a child of it: `has-theme-N-…` classes, color attributes
 *   (`"textColor":"theme-N"`), `var:preset|color|theme-N`, `--wp--preset--color--theme-N` and the light and dark
 *   twins (`windrose-livery-dark-theme-N`). Words in the text are left alone. A Windrose Knotboard library pattern
 *   that matched its import keeps matching it (the import's fingerprint follows).
 * Revisions stay as they are (history). A palette that already has one of the new names next to an old one is left
 * alone and reported. Rows are written directly (no revision, no content filter, dates kept), then caches cleaned.
 *
 * WP-CLI only, a dry run by default: `wp windrose-livery rename-colors [--network] [--run]`. Back up the database
 * first. A one-off for sites made before 7.2-beta4; it will be removed in a later version.
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ipsum's color names, old to new.
 *
 * @phpstan-type SiteReport array{site: int, url: string, theme: string, styles: int, saved: int, content: int, names: int, clashes: list<string>, skipped: int}
 */
final class Rename_Colors {

	/**
	 * Old number => new slug.
	 *
	 * @var array<int, string>
	 */
	public const MAP = array(
		1 => 'base',
		2 => 'contrast',
		3 => 'accent-1',
		4 => 'accent-6',
		5 => 'accent-5',
		6 => 'accent-3',
	);

	/**
	 * Ipsum's order.
	 *
	 * @var list<string>
	 */
	public const ORDER = array( 'base', 'contrast', 'accent-1', 'accent-3', 'accent-5', 'accent-6' );

	/**
	 * Windrose Knotboard's fingerprint of an imported pattern (`Import::META_SAVED`).
	 */
	private const KNOTBOARD_SAVED = '_windrose_knotboard_saved';

	/**
	 * Any old name (theme.json data, where every one is a color).
	 */
	private const ANY = '/theme-([1-6])(?![0-9])/';

	/**
	 * Old names in block content, only where they name a color.
	 */
	private const IN_CONTENT = '/(has-|preset\|color\||preset--color--|preset\\\\u002d\\\\u002dcolor\\\\u002d\\\\u002d|"[A-Za-z]*[Cc]olor":"|windrose-livery-(?:dark|light)-)theme-([1-6])(?![0-9])/';

	/**
	 * Hooks the WP-CLI command.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'windrose-livery rename-colors', array( __CLASS__, 'cli' ) );
		}
	}

	/**
	 * Theme.json text with the new names.
	 *
	 * @param string $text  Text.
	 * @param int    $count Names renamed (added to).
	 * @return string
	 */
	public static function rename_data( string $text, int &$count = 0 ): string {
		$out    = (string) preg_replace_callback( self::ANY, static fn( array $m ): string => self::MAP[ (int) $m[1] ], $text, -1, $found );
		$count += $found;
		return $out;
	}

	/**
	 * Block content with the new names where they name a color.
	 *
	 * @param string $text  Content.
	 * @param int    $count Names renamed (added to).
	 * @return string
	 */
	public static function rename_content( string $text, int &$count = 0 ): string {
		$out    = (string) preg_replace_callback( self::IN_CONTENT, static fn( array $m ): string => $m[1] . self::MAP[ (int) $m[2] ], $text, -1, $found );
		$count += $found;
		return $out;
	}

	/**
	 * Whether a palette in the data has old and new names side by side (renaming would give two colors one name).
	 *
	 * @param mixed $node Data.
	 * @return bool
	 */
	public static function clashes( $node ): bool {
		if ( ! is_array( $node ) ) {
			return false;
		}
		foreach ( $node as $key => $value ) {
			if ( 'palette' === $key && is_array( $value ) ) {
				$lists = array_is_list( $value ) ? array( $value ) : array_filter( $value, 'is_array' );
				foreach ( $lists as $colors ) {
					$slugs = array_map( static fn( $c ): string => is_array( $c ) && is_string( $c['slug'] ?? null ) ? $c['slug'] : '', $colors );
					$old   = preg_grep( '/^(?:windrose-livery-(?:dark|light)-)?theme-[1-6]$/', $slugs );
					$new   = preg_grep( '/^(?:windrose-livery-(?:dark|light)-)?(?:base|contrast|accent-[1356])$/', $slugs );
					if ( $old && $new ) {
						return true;
					}
				}
			} elseif ( self::clashes( $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Palettes in Ipsum's order: its six colors, the others as they were, then the twins in the same order.
	 *
	 * @param mixed $node Data.
	 * @return mixed
	 */
	public static function reorder( $node ) {
		if ( ! is_array( $node ) ) {
			return $node;
		}
		foreach ( $node as $key => $value ) {
			if ( 'palette' === $key && is_array( $value ) && array_is_list( $value ) ) {
				$node[ $key ] = self::sorted( $value );
			} elseif ( 'palette' === $key && is_array( $value ) ) {
				$node[ $key ] = array_map( static fn( $colors ) => is_array( $colors ) && array_is_list( $colors ) ? self::sorted( $colors ) : $colors, $value );
			} else {
				$node[ $key ] = self::reorder( $value );
			}
		}
		return $node;
	}

	/**
	 * One palette list in Ipsum's order (stable).
	 *
	 * @param array $colors Colors.
	 * @phpstan-param list<mixed> $colors
	 * @return list<mixed>
	 */
	private static function sorted( array $colors ): array {
		$rank  = static function ( $color ): int {
			$slug = is_array( $color ) && is_string( $color['slug'] ?? null ) ? $color['slug'] : '';
			$twin = 1 === preg_match( '/^windrose-livery-(?:dark|light)-(.+)$/', $slug, $m );
			$own  = $twin ? $m[1] : $slug;
			$pos  = array_search( $own, self::ORDER, true );
			return ( $twin ? 100 : 0 ) + ( false === $pos ? count( self::ORDER ) : (int) $pos );
		};
		$keyed = array();
		foreach ( $colors as $i => $color ) {
			$keyed[] = array( $rank( $color ), $i, $color );
		}
		usort( $keyed, static fn( array $a, array $b ): int => array( $a[0], $a[1] ) <=> array( $b[0], $b[1] ) );
		return array_column( $keyed, 2 );
	}

	/**
	 * Whether a theme is Ipsum or a child of it.
	 *
	 * @param string $stylesheet Stylesheet.
	 * @return bool
	 */
	public static function is_ipsum( string $stylesheet ): bool {
		if ( 'ipsum' === $stylesheet ) {
			return true;
		}
		$theme = wp_get_theme( $stylesheet );
		return $theme->exists() && 'ipsum' === $theme->get_template();
	}

	/**
	 * Renames on the given sites (the current site when none).
	 *
	 * @param array $sites   Site IDs (multisite), empty for the current site.
	 * @phpstan-param list<int> $sites
	 * @param bool  $dry_run Count only.
	 * @return list<SiteReport>
	 */
	public static function run( array $sites, bool $dry_run = true ): array {
		if ( ! is_multisite() || array() === $sites ) {
			return array( self::site( $dry_run ) );
		}
		$out = array();
		foreach ( $sites as $site ) {
			switch_to_blog( $site );
			try {
				$out[] = self::site( $dry_run );
			} finally {
				restore_current_blog();
			}
		}
		return $out;
	}

	/**
	 * Renames on the current site.
	 *
	 * @param bool $dry_run Count only.
	 * @return SiteReport
	 */
	private static function site( bool $dry_run ): array {
		$report = array(
			'site'    => get_current_blog_id(),
			'url'     => home_url( '/' ),
			'theme'   => get_stylesheet(),
			'styles'  => 0,
			'saved'   => 0,
			'content' => 0,
			'names'   => 0,
			'clashes' => array(),
			'skipped' => 0,
		);
		self::stored_styles( $report, $dry_run );
		self::saved_styles( $report, $dry_run );
		self::content( $report, $dry_run );
		if ( ! $dry_run && ( $report['styles'] || $report['saved'] || $report['content'] ) ) {
			wp_clean_theme_json_cache();
			delete_transient( 'wp_styles_for_blocks' );
		}
		return $report;
	}

	/**
	 * Livery's stored styles on the current site.
	 *
	 * @param array $report  Report (changed).
	 * @phpstan-param SiteReport $report
	 * @param bool  $dry_run Count only.
	 * @return void
	 */
	private static function stored_styles( array &$report, bool $dry_run ): void {
		$ids = get_posts(
			array(
				'post_type'   => Library::POST_TYPE,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$json = (string) get_post_meta( $id, Library::META_DATA, true );
			if ( ! self::is_ipsum( (string) get_post_meta( $id, Library::META_THEME, true ) ) || 1 !== preg_match( self::ANY, $json ) ) {
				continue;
			}
			$data = json_decode( $json, true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			if ( self::clashes( $data ) ) {
				$report['clashes'][] = sprintf( 'style %d (%s)', $id, get_the_title( $id ) );
				continue;
			}
			$names   = 0;
			$renamed = json_decode( self::rename_data( $json, $names ), true );
			if ( ! is_array( $renamed ) ) {
				continue;
			}
			++$report['styles'];
			$report['names'] += $names;
			if ( ! $dry_run ) {
				update_post_meta( $id, Library::META_DATA, wp_slash( Library::encode( (array) self::reorder( $renamed ) ) ) );
			}
		}//end foreach
	}

	/**
	 * The saved styles of Ipsum and its child themes on the current site.
	 *
	 * @param array $report  Report (changed).
	 * @phpstan-param SiteReport $report
	 * @param bool  $dry_run Count only.
	 * @return void
	 */
	private static function saved_styles( array &$report, bool $dry_run ): void {
		$posts = get_posts(
			array(
				'post_type'   => 'wp_global_styles',
				'post_status' => 'any',
				'numberposts' => -1,
			)
		);
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post || 1 !== preg_match( self::ANY, $post->post_content ) ) {
				continue;
			}
			$terms = wp_get_object_terms( $post->ID, 'wp_theme', array( 'fields' => 'names' ) );
			$theme = is_array( $terms ) && isset( $terms[0] ) && is_string( $terms[0] ) ? $terms[0] : '';
			if ( ! self::is_ipsum( $theme ) ) {
				continue;
			}
			$before = json_decode( $post->post_content, true );
			if ( self::clashes( $before ) ) {
				$report['clashes'][] = sprintf( 'saved styles of %s (%d)', $theme, $post->ID );
				continue;
			}
			$names   = 0;
			$content = self::rename_data( $post->post_content, $names );
			++$report['saved'];
			$report['names'] += $names;
			if ( $dry_run ) {
				continue;
			}
			$record = Tracker::get();
			$match  = null !== $record && ! $record['changed'] && get_stylesheet() === $theme && is_array( $before ) && Library::hash( $before ) === $record['hash'];
			// Straight to the row: a post update would add a revision and run the save hooks.
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post->ID ) );
			clean_post_cache( $post->ID );
			$after = json_decode( $content, true );
			if ( $match && is_array( $after ) ) {
				$record['hash'] = Library::hash( $after );
				update_option( Tracker::OPTION, $record, false );
			}
		}//end foreach
	}

	/**
	 * Block content on the current site, when its theme is Ipsum or a child of it.
	 *
	 * @param array $report  Report (changed).
	 * @phpstan-param SiteReport $report
	 * @param bool  $dry_run Count only.
	 * @return void
	 */
	private static function content( array &$report, bool $dry_run ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision', 'wp_global_styles', %s) AND post_content LIKE %s",
				Library::POST_TYPE,
				'%' . $wpdb->esc_like( 'theme-' ) . '%'
			)
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->ID, $row->post_content ) ) {
				continue;
			}
			$names   = 0;
			$content = self::rename_content( (string) $row->post_content, $names );
			if ( 0 === $names ) {
				continue;
			}
			if ( ! self::is_ipsum( get_stylesheet() ) ) {
				++$report['skipped'];
				continue;
			}
			++$report['content'];
			$report['names'] += $names;
			if ( ! $dry_run ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => (int) $row->ID ) );
				clean_post_cache( (int) $row->ID );
				// A Windrose Knotboard library pattern keeps the fingerprint of what its import saved, so a later import
				// does not take the rename for an edit made on the site.
				if ( md5( (string) $row->post_content ) === get_post_meta( (int) $row->ID, self::KNOTBOARD_SAVED, true ) ) {
					update_post_meta( (int) $row->ID, self::KNOTBOARD_SAVED, md5( $content ) );
				}
			}
		}//end foreach
	}

	/**
	 * Renames Ipsum's old color names (theme-1 to theme-6) in stored styles, saved styles and block content.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : Every site of the network. Without it, the site given with --url (or the main site).
	 *
	 * [--run]
	 * : Rename. Without it, a dry run (counts only). Back up the database first.
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public static function cli( $args, $assoc_args ): void {
		$dry_run = ! isset( $assoc_args['run'] );
		$sites   = isset( $assoc_args['network'] ) && is_multisite()
			? array_values(
				array_map(
					'intval',
					get_sites(
						array(
							'number'     => 0,
							'fields'     => 'ids',
							'network_id' => get_current_network_id(),
						)
					)
				)
			)
			: array();
		$totals  = array(
			'styles'  => 0,
			'saved'   => 0,
			'content' => 0,
			'names'   => 0,
			'skipped' => 0,
		);
		foreach ( self::run( $sites, $dry_run ) as $report ) {
			foreach ( array_keys( $totals ) as $key ) {
				$totals[ $key ] += $report[ $key ];
			}
			\WP_CLI::line(
				sprintf(
					'%s (site %d, %s): stored styles %d, saved styles %d, posts %d, names %d%s%s',
					$report['url'],
					$report['site'],
					$report['theme'],
					$report['styles'],
					$report['saved'],
					$report['content'],
					$report['names'],
					$report['skipped'] ? sprintf( ', posts skipped (theme not Ipsum) %d', $report['skipped'] ) : '',
					$report['clashes'] ? ', left alone (old and new names together): ' . implode( '; ', $report['clashes'] ) : ''
				)
			);
		}
		\WP_CLI::line( sprintf( 'Total: stored styles %d, saved styles %d, posts %d, names %d, posts skipped %d', $totals['styles'], $totals['saved'], $totals['content'], $totals['names'], $totals['skipped'] ) );
		\WP_CLI::success( $dry_run ? 'Dry run: nothing written. Add --run to rename (back up the database first).' : 'Renamed.' );
	}
}
