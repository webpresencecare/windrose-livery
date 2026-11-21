<?php
/**
 * Which stored variation each site uses, so Livery can offer "apply the new version" (a site keeps a copy;
 * later changes to the variation do not reach it by themselves).
 *
 * Site option `windrose_livery_applied` (not autoloaded): the variation's ID and scope (0: shared library; the site's
 * ID: its own styles; records before 0.4 have none and mean the shared library), version and fingerprint, how it got
 * there (`livery`: applied from Livery's screen; `editor`: picked in the Site Editor and saved), when, and whether the
 * site's styles were changed since. Written when Livery applies a variation, and whenever a site saves its styles:
 * saved styles equal to a stored variation are recorded as it; other changes mark the record as changed.
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records what each site uses.
 *
 * @phpstan-import-type Variation from Library
 * @phpstan-type Record array{id: int, scope: int, version: int, hash: string, how: string, time: int, changed: bool}
 */
final class Tracker {

	public const OPTION = 'windrose_livery_applied';

	/**
	 * Hooks the save of a site's styles.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'save_post_wp_global_styles', array( __CLASS__, 'saved' ), 20, 2 );
	}

	/**
	 * The current site's record.
	 *
	 * @return Record|null
	 */
	public static function get(): ?array {
		$record = get_option( self::OPTION );
		if ( ! is_array( $record ) || ! isset( $record['id'], $record['version'], $record['hash'] ) ) {
			return null;
		}
		return array(
			'id'      => (int) $record['id'],
			'scope'   => (int) ( $record['scope'] ?? 0 ),
			'version' => (int) $record['version'],
			'hash'    => (string) $record['hash'],
			'how'     => 'editor' === ( $record['how'] ?? '' ) ? 'editor' : 'livery',
			'time'    => (int) ( $record['time'] ?? 0 ),
			'changed' => ! empty( $record['changed'] ),
		);
	}

	/**
	 * Records that the current site now uses a variation.
	 *
	 * @param array  $item Variation.
	 * @phpstan-param Variation $item
	 * @param string $how  livery or editor.
	 * @return void
	 */
	public static function record( array $item, string $how ): void {
		update_option(
			self::OPTION,
			array(
				'id'      => $item['id'],
				'scope'   => $item['scope'],
				'version' => $item['version'],
				'hash'    => Library::hash( Library::resolved( $item ) ),
				'how'     => $how,
				'time'    => time(),
				'changed' => false,
			),
			false
		);
	}

	/**
	 * A site saved its styles: record the variation they equal, or mark the record as changed.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public static function saved( $post_id, $post ): void {
		if ( ! $post instanceof WP_Post || 'wp_global_styles' !== $post->post_type || wp_is_post_revision( $post ) ) {
			return;
		}
		$terms = wp_get_object_terms( (int) $post_id, 'wp_theme', array( 'fields' => 'names' ) );
		if ( ! is_array( $terms ) || ! in_array( get_stylesheet(), $terms, true ) ) {
			return;
		}
		$saved = json_decode( $post->post_content, true );
		if ( ! is_array( $saved ) ) {
			return;
		}
		$hash = Library::hash( $saved );
		foreach ( Library::offered( get_current_blog_id(), get_stylesheet(), get_template() ) as $item ) {
			if ( Library::hash( Library::resolved( $item ) ) === $hash ) {
				$current = self::get();
				if ( null === $current || $current['id'] !== $item['id'] || $current['scope'] !== $item['scope'] || $current['version'] !== $item['version'] || $current['changed'] ) {
					self::record( $item, 'editor' );
				}
				return;
			}
		}
		$current = self::get();
		if ( null !== $current && $current['hash'] !== $hash && ! $current['changed'] ) {
			$current['changed'] = true;
			update_option( self::OPTION, $current, false );
		}
	}
}
