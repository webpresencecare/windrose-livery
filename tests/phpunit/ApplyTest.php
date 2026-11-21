<?php
/**
 * Applying to sites, and the record of what each site uses.
 *
 * @package Windrose\Livery
 */

use Windrose\Livery\Applier;
use Windrose\Livery\Importer;
use Windrose\Livery\Library;
use Windrose\Livery\Tracker;

/**
 * @covers \Windrose\Livery\Applier
 * @covers \Windrose\Livery\Tracker
 */
class ApplyTest extends Livery_TestCase {

	/**
	 * Imports the brand and returns the stored variation.
	 *
	 * @return array<string, mixed>
	 */
	private function stored( array $extra = array(), bool $replace = false ): array {
		$saved = Importer::import(
			$this->brand_zip( array(), $extra ),
			'harbour.zip',
			array(
				'dry_run' => false,
				'replace' => $replace,
			)
		);
		return Library::get( $saved['id'] );
	}

	public function test_apply_writes_the_sites_styles_and_records_it(): void {
		$item    = $this->stored();
		$results = Applier::apply( $item, array( self::$ipsum_site ) );

		$this->assertSame( 'applied', $results[0]['status'], $results[0]['message'] );
		$this->assertSame( 'Ipsum site', $results[0]['name'] );
		$styles = $this->site_styles( self::$ipsum_site );
		$this->assertTrue( $styles['isGlobalStylesUserThemeJSON'] );
		$this->assertSame( Library::hash( Library::resolved( $item ) ), Library::hash( $styles ) );
		$this->assertStringStartsWith( $this->main_fonts()['url'] . '/', $styles['settings']['typography']['fontFamilies']['theme'][0]['fontFace'][0]['src'][0] );

		switch_to_blog( self::$ipsum_site );
		$record = Tracker::get();
		WP_Theme_JSON_Resolver::clean_cached_data();
		$merged = wp_get_global_settings( array( 'color', 'palette' ) );
		restore_current_blog();
		$this->assertSame( $item['id'], $record['id'] );
		$this->assertSame( 1, $record['version'] );
		$this->assertSame( 'livery', $record['how'] );
		$this->assertFalse( $record['changed'] );
		$this->assertContains( '#032649', array_column( (array) ( $merged['theme'] ?? array() ), 'color' ), 'WordPress reads the palette from the saved styles, as the theme’s.' );
	}

	public function test_previous_styles_stay_in_the_revisions(): void {
		$item = $this->stored();
		Applier::apply( $item, array( self::$ipsum_site ) );
		Applier::apply( $item, array( self::$ipsum_site ) );
		switch_to_blog( self::$ipsum_site );
		$post      = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() );
		$revisions = wp_get_post_revisions( (int) $post['ID'] );
		restore_current_blog();
		$this->assertNotEmpty( $revisions );
	}

	public function test_sites_on_another_theme_or_another_network_are_skipped(): void {
		$item    = $this->stored();
		$results = Applier::apply( $item, array( self::$tt5_site, 999999 ) );

		$this->assertSame( 'skipped', $results[0]['status'] );
		$this->assertStringContainsString( 'Twenty Twenty-Five', $results[0]['message'] );
		$this->assertSame( 'skipped', $results[1]['status'] );
		$this->assertSame( array(), $this->site_styles( self::$tt5_site )['settings'] ?? array() );
	}

	public function test_the_right_is_checked_on_each_site(): void {
		$item  = $this->stored();
		$local = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		add_user_to_blog( self::$ipsum_site, $local, 'subscriber' );
		wp_set_current_user( $local );

		$results = Applier::apply( $item, array( self::$ipsum_site ) );
		$this->assertSame( 'skipped', $results[0]['status'] );
		$this->assertSame( 'You may not change this site’s styles.', $results[0]['message'] );
		$this->assertArrayNotHasKey( 'settings', $this->site_styles( self::$ipsum_site ) );
	}

	public function test_a_new_version_is_seen_as_newer_and_can_be_applied(): void {
		$item = $this->stored();
		Applier::apply( $item, array( self::$ipsum_site ) );
		$v2 = $this->stored( array( 'harbour/fonts/cabin/cabin-400-normal.woff2' => $this->woff2( 'cabin v2' ) ), true );
		$this->assertSame( 2, $v2['version'] );

		$rows = array_column( Applier::sites(), null, 'id' );
		$this->assertSame( 1, $rows[ self::$ipsum_site ]['applied']['version'] );
		$this->assertNull( $rows[ self::$tt5_site ]['applied'] );

		Applier::apply( $v2, array( self::$ipsum_site ) );
		$rows = array_column( Applier::sites(), null, 'id' );
		$this->assertSame( 2, $rows[ self::$ipsum_site ]['applied']['version'] );
		$this->assertTrue( $rows[ self::$ipsum_site ]['canEdit'] );
		$this->assertSame( 'ipsum', $rows[ self::$ipsum_site ]['stylesheet'] );
	}

	public function test_picking_it_in_the_site_editor_is_recorded_and_later_changes_are_marked(): void {
		$item = $this->stored();
		switch_to_blog( self::$ipsum_site );
		$post = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
		// What the Site Editor saves after picking the variation: its settings and styles.
		$content = array_merge(
			array(
				'version'                     => 3,
				'isGlobalStylesUserThemeJSON' => true,
			),
			Library::resolved( $item )
		);
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $post['ID'],
					'post_content' => wp_json_encode( $content ),
				)
			)
		);
		$record = Tracker::get();
		$this->assertSame( $item['id'], $record['id'] );
		$this->assertSame( 'editor', $record['how'] );

		$content['styles']['color'] = array( 'background' => '#000000' );
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $post['ID'],
					'post_content' => wp_json_encode( $content ),
				)
			)
		);
		$this->assertTrue( Tracker::get()['changed'] );
		restore_current_blog();
	}
}
