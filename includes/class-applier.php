<?php
/**
 * Applies a stored variation to sites, as picking it in the Site Editor does: its settings and styles
 * become the site's saved styles for its active theme. The previous styles stay in the Site Editor's Styles
 * revisions (core keeps revisions of `wp_global_styles`).
 *
 * Checked for each site, on that site: it belongs to this network, the user may edit its styles
 * (`edit_theme_options` there), its active theme is the variation's theme or a child of it. When the user may not add
 * unfiltered HTML there, WordPress's own filter cleans the saved styles; the result then says what was removed.
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

use WP_Theme_JSON;
use WP_Theme_JSON_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Apply and the sites list.
 *
 * @phpstan-import-type Variation from Library
 * @phpstan-type Result array{site: int, name: string, status: string, message: string}
 */
final class Applier {

	public const MAX_SITES = 500;

	/**
	 * Applies a variation to sites.
	 *
	 * @param array $item  Variation.
	 * @phpstan-param Variation $item
	 * @param array $sites Site IDs (ignored on a single installation: the site itself).
	 * @phpstan-param list<int> $sites
	 * @return list<Result>
	 */
	public static function apply( array $item, array $sites ): array {
		$sites   = is_multisite() ? array_slice( array_values( array_unique( array_map( 'intval', $sites ) ) ), 0, self::MAX_SITES ) : array( get_current_blog_id() );
		$results = array();
		foreach ( $sites as $site_id ) {
			$results[] = self::apply_one( $item, $site_id );
		}
		return $results;
	}

	/**
	 * Applies a variation to one site.
	 *
	 * @param array $item    Variation.
	 * @phpstan-param Variation $item
	 * @param int   $site_id Site.
	 * @return Result
	 */
	private static function apply_one( array $item, int $site_id ): array {
		$result = array(
			'site'    => $site_id,
			'name'    => '',
			'status'  => 'skipped',
			'message' => '',
		);
		if ( is_multisite() ) {
			$site = get_site( $site_id );
			if ( ! $site || get_current_network_id() !== (int) $site->network_id ) {
				$result['message'] = __( 'This site is not part of this network.', 'windrose-livery' );
				return $result;
			}
			if ( ! current_user_can_for_site( $site_id, 'edit_theme_options' ) ) {
				$result['message'] = __( 'You may not change this site’s styles.', 'windrose-livery' );
				return $result;
			}
		} elseif ( ! current_user_can( 'edit_theme_options' ) ) {
			$result['message'] = __( 'You may not change this site’s styles.', 'windrose-livery' );
			return $result;
		}

		$switch = is_multisite() && get_current_blog_id() !== $site_id;
		if ( $switch ) {
			switch_to_blog( $site_id );
		}
		WP_Theme_JSON_Resolver::clean_cached_data();
		try {
			$result['name'] = (string) get_bloginfo( 'name' );
			if ( ! Library::fits( $item, get_stylesheet(), get_template() ) ) {
				/* translators: 1: theme the site uses, 2: theme the variation was made for. */
				$result['message'] = sprintf( __( 'This site uses %1$s; the variation is for %2$s and its child themes.', 'windrose-livery' ), Themes::name( get_stylesheet() ), Themes::name( $item['theme'] ) );
				return $result;
			}
			$data = Library::resolved( $item );
			$post = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
			$id   = (int) ( $post['ID'] ?? 0 );
			if ( $id <= 0 ) {
				$result['status']  = 'failed';
				$result['message'] = __( 'The site’s styles could not be created.', 'windrose-livery' );
				return $result;
			}
			$content = array_merge(
				array(
					'version'                     => WP_Theme_JSON::LATEST_SCHEMA,
					'isGlobalStylesUserThemeJSON' => true,
				),
				Library::objects( $data )
			);
			$json    = (string) wp_json_encode( $content, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
			$updated = wp_update_post(
				wp_slash(
					array(
						'ID'           => $id,
						'post_content' => $json,
					)
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				$result['status']  = 'failed';
				$result['message'] = $updated->get_error_message();
				return $result;
			}
			Tracker::record( $item, 'livery' );
			wp_clean_theme_json_cache();

			$saved = json_decode( (string) get_post_field( 'post_content', $id, 'raw' ), true );
			if ( ! is_array( $saved ) || Library::hash( $saved ) !== Library::hash( $data ) ) {
				$record = Tracker::get();
				if ( null !== $record ) {
					$record['changed'] = true;
					update_option( Tracker::OPTION, $record, false );
				}
				$result['status']  = 'filtered';
				$result['message'] = __( 'Applied, but WordPress removed some styles: you may not add unfiltered HTML on this site.', 'windrose-livery' );
				return $result;
			}
			$result['status'] = 'applied';
			/* translators: %d: version number. */
			$result['message'] = sprintf( __( 'Applied (version %d). The previous styles are in the Site Editor, Styles, Revisions.', 'windrose-livery' ), $item['version'] );
			return $result;
		} finally {
			WP_Theme_JSON_Resolver::clean_cached_data();
			if ( $switch ) {
				restore_current_blog();
				WP_Theme_JSON_Resolver::clean_cached_data();
			}
		}//end try
	}

	/**
	 * Sites of the network (or the site itself) with their theme, what Livery knows they use, and whether the current
	 * user may change their styles.
	 *
	 * @param list<int>|null $only These sites only (a site's own screen), or null for the network's.
	 * @return list<array{id: int, name: string, url: string, stylesheet: string, template: string, themeName: string, canEdit: bool, applied: array<string, mixed>|null, render: string, rest: string}>
	 */
	public static function sites( ?array $only = null ): array {
		$ids  = $only ?? ( is_multisite()
			? get_sites(
				array(
					'network_id' => get_current_network_id(),
					'fields'     => 'ids',
					'number'     => self::MAX_SITES,
					'deleted'    => 0,
					'archived'   => 0,
					'spam'       => 0,
				)
			)
			: array( get_current_blog_id() ) );
		$list = array();
		foreach ( $ids as $site_id ) {
			$site_id = (int) $site_id;
			$switch  = is_multisite() && get_current_blog_id() !== $site_id;
			if ( $switch ) {
				switch_to_blog( $site_id );
			}
			$applied = Tracker::get();
			$list[]  = array(
				'id'         => $site_id,
				'name'       => (string) get_bloginfo( 'name' ),
				'url'        => home_url( '/' ),
				'stylesheet' => get_stylesheet(),
				'template'   => get_template(),
				'themeName'  => Themes::name( get_stylesheet() ),
				'canEdit'    => is_multisite() ? current_user_can_for_site( $site_id, 'edit_theme_options' ) : current_user_can( 'edit_theme_options' ),
				'applied'    => $applied,
				// The pattern browser renders and lists patterns on the site itself (0.6).
				'render'     => Patterns::render_url(),
				'rest'       => get_rest_url( null, Rest::NAMESPACE . '/patterns' ),
			);
			if ( $switch ) {
				restore_current_blog();
			}
		}//end foreach
		return $list;
	}
}
