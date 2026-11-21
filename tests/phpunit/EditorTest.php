<?php
/**
 * Stored variations in the Site Editor's variation list and their fonts in the editor.
 *
 * @package Windrose\Livery
 */

use Windrose\Livery\Editor;
use Windrose\Livery\Importer;

/**
 * @covers \Windrose\Livery\Editor
 * @covers \Windrose\Livery\Fonts
 */
class EditorTest extends Livery_TestCase {

	/**
	 * Titles from the variations route of a site.
	 *
	 * @return array{0: int, 1: list<array<string, mixed>>}
	 */
	private function variations( int $site, string $stylesheet = '' ): array {
		switch_to_blog( $site );
		$stylesheet = '' === $stylesheet ? get_stylesheet() : $stylesheet;
		$response   = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/global-styles/themes/' . $stylesheet . '/variations' ) );
		$data       = rest_get_server()->response_to_data( $response, false );
		restore_current_blog();
		return array( $response->get_status(), is_array( $data ) ? $data : array() );
	}

	public function test_a_site_on_the_theme_lists_the_stored_variation_after_the_themes_own(): void {
		Importer::import( $this->brand_zip(), 'harbour.zip', array( 'dry_run' => false ) );
		[ $status, $data ] = $this->variations( self::$ipsum_site );

		$this->assertSame( 200, $status );
		$titles = array_column( $data, 'title' );
		$this->assertContains( 'Blue Hour', $titles, 'Ipsum’s own presets are still there.' );
		$this->assertSame( 'Harbour Brand', end( $titles ) );
		$ours = end( $data );
		$this->assertSame( '#032649', $ours['settings']['color']['palette']['theme'][1]['color'], 'Presets grouped by origin, as core gives theme variation files.' );
		$src = $ours['settings']['typography']['fontFamilies']['theme'][0]['fontFace'][0]['src'][0];
		$this->assertStringStartsWith( $this->main_fonts()['url'] . '/', $src, 'The main site’s address on every site, never /ipsum-site/wp-content.' );
		$this->assertStringNotContainsString( 'ipsum-site', $src );
	}

	public function test_it_has_the_shape_of_a_theme_variation_file(): void {
		$file = $this->brand( 'Same Shape' );
		unset( $file['$schema'] );
		Importer::import( $this->json_file( $file ), 'same.json', array( 'dry_run' => false ) );
		[ , $data ] = $this->variations( self::$ipsum_site );
		$ours       = end( $data );
		$core       = ( new WP_Theme_JSON( $file ) )->get_raw_data();
		$this->assertSame( $core['settings'], $ours['settings'] );
		$this->assertSame( $core['styles'], $ours['styles'] );
	}

	public function test_font_addresses_keep_the_sites_own_scheme_whatever_the_request(): void {
		update_option( 'siteurl', 'https://example.org' );
		\Windrose\Livery\Fonts::flush();
		$this->assertFalse( is_ssl() );
		$this->assertStringStartsWith( set_url_scheme( $this->main_fonts()['url'], 'https' ), \Windrose\Livery\Fonts::dir()['url'] );
	}

	public function test_the_menu_items_load_in_the_site_editor_for_style_editors(): void {
		set_current_screen( 'site-editor' );
		Editor::menu_item();
		$this->assertTrue( wp_script_is( Editor::SCRIPT, 'enqueued' ) );
		$this->assertStringContainsString( '"siteId":' . get_current_blog_id(), implode( '', (array) wp_scripts()->get_data( Editor::SCRIPT, 'before' ) ) );
		wp_dequeue_script( Editor::SCRIPT );
		wp_deregister_script( Editor::SCRIPT );

		// A site administrator gets the light and dark items, but not the network library (nor, while the network does
		// not allow it, their own styles).
		revoke_super_admin( self::$admin );
		Editor::menu_item();
		$this->assertTrue( wp_script_is( Editor::SCRIPT, 'enqueued' ) );
		$before = implode( '', (array) wp_scripts()->get_data( Editor::SCRIPT, 'before' ) );
		$this->assertStringContainsString( '"shared":false', $before );
		$this->assertStringContainsString( '"own":false', $before );
		wp_dequeue_script( Editor::SCRIPT );
		wp_deregister_script( Editor::SCRIPT );
		grant_super_admin( self::$admin );
		set_current_screen( 'post' );
		Editor::menu_item();
		$this->assertFalse( wp_script_is( Editor::SCRIPT, 'enqueued' ), 'Site Editor only.' );
		set_current_screen( 'front' );
	}

	public function test_a_site_on_another_theme_does_not_list_it(): void {
		Importer::import( $this->brand_zip(), 'harbour.zip', array( 'dry_run' => false ) );
		[ $status, $data ] = $this->variations( self::$tt5_site );
		$this->assertSame( 200, $status );
		$this->assertNotContains( 'Harbour Brand', array_column( $data, 'title' ) );
	}

	public function test_a_child_theme_lists_its_parents_variations(): void {
		Importer::import( $this->brand_zip(), 'harbour.zip', array( 'dry_run' => false ) );
		$this->theme_on( self::$tt5_site, 'ipsum' );
		$fits = \Windrose\Livery\Library::for_theme( 'windrose-prow-ipsum', 'ipsum' );
		$this->assertSame( array( 'Harbour Brand' ), array_column( $fits, 'title' ) );
		$this->assertSame( array(), \Windrose\Livery\Library::for_theme( 'twentytwentyfive', 'twentytwentyfive' ) );
	}

	public function test_other_answers_are_left_alone(): void {
		Importer::import( $this->brand_zip(), 'harbour.zip', array( 'dry_run' => false ) );
		[ $status, $data ] = $this->variations( self::$ipsum_site, 'twentytwentyfive' );
		$this->assertSame( 404, $status, 'Core answers only for the active theme.' );
		$this->assertNotContains( 'Harbour Brand', array_column( $data, 'title' ) );
	}

	public function test_the_editor_gets_font_face_rules_for_the_previews(): void {
		Importer::import( $this->brand_zip(), 'harbour.zip', array( 'dry_run' => false ) );
		set_current_screen( 'site-editor' );
		Editor::editor_fonts();
		$css = (string) wp_styles()->get_data( Editor::HANDLE, 'after' )[0];
		set_current_screen( 'front' );
		wp_deregister_style( Editor::HANDLE );

		$this->assertStringContainsString( '@font-face{font-family:"Baloo";font-style:normal;font-weight:400;', $css );
		$fonts = preg_quote( (string) wp_parse_url( $this->main_fonts()['url'], PHP_URL_PATH ), '#' );
		$this->assertMatchesRegularExpression( "#src:url\\('https?://[^']+" . $fonts . "/[a-f0-9]{16}-baloo-400-normal\\.woff2'\\) format\\('woff2'\\)#", $css );
	}
}
