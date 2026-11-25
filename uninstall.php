<?php
/**
 * Uninstall Windrose Livery: removes the stored variations (posts of type `windrose_livery` and their meta, on the
 * main site and, for sites' own styles, on each site), each site's record of the variation it uses
 * (`windrose_livery_applied`), each site's Style Mermaid switches (`windrose_livery_mermaid`), each site's light and
 * dark options (`windrose_livery_scheme`), the network switch (`windrose_livery_network`), the fonts Style Mermaid downloaded from Google Fonts or Bunny Fonts (the
 * folder `uploads/windrose-livery/cache/` and the option `windrose_livery_font_cache`: variations keep their own
 * copies in the fonts folder), the installed font packs (the folder `uploads/windrose-livery/packs/` and the option
 * `windrose_livery_packs`: variations keep their own copies too) and the cached update check.
 *
 * Kept on purpose: the fonts in each site's Font Library (since 0.4: installed fonts like any other, which sites
 * that applied a variation load; their saved styles are a copy and keep working), the files of versions
 * before 0.4 in `uploads/windrose-livery/fonts/`, and every site's saved styles (twin colours stay as plain colours).
 *
 * @package Windrose\Livery
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$windrose_livery_sites = is_multisite()
	? get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	)
	: array( get_current_blog_id() );
// Core's direct filesystem: the files to remove are Livery's own, in the uploads folder.
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
$windrose_livery_files = new WP_Filesystem_Direct( null );

foreach ( $windrose_livery_sites as $windrose_livery_site ) {
	if ( is_multisite() ) {
		switch_to_blog( (int) $windrose_livery_site );
	}
	delete_option( 'windrose_livery_applied' );
	delete_option( 'windrose_livery_mermaid' );
	delete_option( 'windrose_livery_scheme' );
	// The fonts stay in the Font Library as ordinary installed fonts: only Livery's mark goes.
	delete_post_meta_by_key( '_windrose_livery_font' );
	if ( ( is_multisite() ? get_main_site_id() : get_current_blog_id() ) === get_current_blog_id() ) {
		delete_option( 'windrose_livery_font_cache' );
		$windrose_livery_uploads = wp_upload_dir( null, false );
		$windrose_livery_cache   = trailingslashit( (string) $windrose_livery_uploads['basedir'] ) . 'windrose-livery/cache';
		foreach ( (array) glob( $windrose_livery_cache . '/*' ) as $windrose_livery_file ) {
			if ( is_string( $windrose_livery_file ) && is_file( $windrose_livery_file ) ) {
				wp_delete_file( $windrose_livery_file );
			}
		}
		if ( is_dir( $windrose_livery_cache ) ) {
			$windrose_livery_files->rmdir( $windrose_livery_cache );
		}
		delete_option( 'windrose_livery_packs' );
		$windrose_livery_packs = trailingslashit( (string) $windrose_livery_uploads['basedir'] ) . 'windrose-livery/packs';
		if ( is_dir( $windrose_livery_packs ) ) {
			$windrose_livery_files->rmdir( $windrose_livery_packs, true );
		}
	}
	$windrose_livery_posts = get_posts(
		array(
			'post_type'        => 'windrose_livery',
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
		)
	);
	foreach ( $windrose_livery_posts as $windrose_livery_post ) {
		wp_delete_post( (int) $windrose_livery_post, true );
	}
	if ( is_multisite() ) {
		restore_current_blog();
	}
}//end foreach
delete_site_option( 'windrose_livery_network' );
delete_site_transient( 'windrose_common_release_windrose-livery' );
