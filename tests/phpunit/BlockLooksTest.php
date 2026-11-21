<?php
/**
 * Style Mermaid's per-block looks (0.5): the import checks keep every look the browser writes (shared fixture made by
 * tests/js/blocks.test.mjs), no look is custom CSS, and the result reaches the zip, the preview, a site
 * administrator's own styles, their site and the Site Editor as plain theme.json, with nothing left out.
 *
 * @package Windrose\Livery
 */

use Windrose\Livery\Applier;
use Windrose\Livery\Builder;
use Windrose\Livery\Contrast;
use Windrose\Livery\Importer;
use Windrose\Livery\Library;
use Windrose\Livery\Mermaid;
use Windrose\Livery\Network;

/**
 * @covers \Windrose\Livery\Builder
 * @covers \Windrose\Livery\Importer
 * @covers \Windrose\Livery\Applier
 */
class BlockLooksTest extends Livery_TestCase {

	/**
	 * The fixture's recipes, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fixtures(): array {
		$list = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/block-looks.json' ), true );
		$this->assertIsArray( $list );
		return array_column( $list, 'recipe', 'name' );
	}

	/**
	 * Parses a recipe as the routes do.
	 *
	 * @param array<string, mixed> $recipe Recipe.
	 * @return array<string, mixed>
	 */
	private function parsed( array $recipe ): array {
		$parsed = Builder::parse( (string) wp_json_encode( $recipe ) );
		$this->assertIsArray( $parsed );
		return $parsed;
	}

	/**
	 * A visitor's download of a fixture recipe, as a zip path.
	 *
	 * @param string $name Fixture name.
	 */
	private function visitor_zip( string $name ): string {
		update_option( Mermaid::OPTION, array( 'public' => true ) );
		$user = get_current_user_id();
		wp_set_current_user( 0 );
		$result = Mermaid::prepare_download( (string) wp_json_encode( $this->fixtures()[ $name ] ) );
		wp_set_current_user( $user );
		$this->assertIsArray( $result );
		$this->temp[] = $result['zip'];
		return $result['zip'];
	}

	public function test_every_look_passes_the_import_checks_without_notes_or_css(): void {
		foreach ( $this->fixtures() as $name => $recipe ) {
			$built = Builder::clean( $this->parsed( $recipe ) );
			$this->assertIsArray( $built, $name );
			$this->assertSame( array(), $built['clean']['notes'], $name );
			$this->assertFalse( $built['clean']['css'], "$name: no custom CSS" );
			$this->assertTrue( Contrast::passes( $built['checks'] ), $name );
			$blocks = $built['clean']['data']['styles']['blocks'];
			foreach ( array( 'core/button', 'core/image', 'core/post-featured-image', 'core/cover', 'core/quote', 'core/pullquote', 'core/separator', 'core/navigation', 'core/code' ) as $block ) {
				$this->assertArrayHasKey( $block, $blocks, "$name: $block" );
			}
		}
	}

	public function test_preview_shows_each_look(): void {
		$preview = Builder::preview( $this->parsed( $this->fixtures()['first'] ) );
		$this->assertIsArray( $preview );
		$css = $preview['css'];
		$this->assertSame( array(), $preview['notes'] );
		$this->assertStringContainsString( ':root :where(.wp-block-image img, .wp-block-image .wp-block-image__crop-area, .wp-block-image .components-placeholder){border-top-left-radius: 16px;', $css );
		$this->assertStringContainsString( 'box-shadow: var(--wp--preset--shadow--soft)', $css );
		$this->assertStringContainsString( ':root :where(.wp-block-quote){background-color: transparent;', $css );
		$this->assertStringContainsString( 'border-top-color: var(--wp--preset--color--accent-6);border-top-width: 1px;', $css );
		$this->assertStringContainsString( 'text-transform: uppercase', $css );
		$this->assertStringContainsString( ':root :where(.wp-block-navigation-is-layout-flex){gap: var(--wp--preset--spacing--50);}', $css );
		$this->assertStringContainsString( 'border-width: 0 0 3px 0', $css );
		$this->assertStringContainsString( ':root :where(.wp-element-button:focus, .wp-block-button__link:focus){background-color: var(--wp--preset--color--contrast);', $css, 'Focus takes the hover color (Text color)' );
	}

	public function test_css_from_the_browser_is_still_left_out(): void {
		$recipe = $this->fixtures()['first'];
		$recipe['styles']['blocks']['core/list']['css'] = 'body{display:none}';
		$recipe['styles']['blocks']['core/code']['css'] = '& {color:red}';
		$built = Builder::clean( $this->parsed( $recipe ) );
		$this->assertArrayNotHasKey( 'css', $built['clean']['data']['styles']['blocks']['core/list'] ?? array() );
		$this->assertArrayNotHasKey( 'css', $built['clean']['data']['styles']['blocks']['core/code'] );
		$this->assertFalse( $built['clean']['css'] );
	}

	public function test_a_site_administrator_gets_everything_a_visitor_made(): void {
		$zip  = $this->visitor_zip( 'second' );
		$data = null;
		$open = new ZipArchive();
		$open->open( $zip );
		for ( $i = 0; $i < $open->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( str_ends_with( (string) $open->getNameIndex( $i ), '/e2e-looks-second.json' ) ) {
				$data = json_decode( (string) $open->getFromIndex( $i ), true );
			}
		}
		$open->close();
		$this->assertIsArray( $data );
		$this->assertSame( 'var:preset|color|accent-3', $data['styles']['blocks']['core/button']['color']['background'] );

		// A site administrator, not a network administrator, without the right to add CSS.
		update_site_option( Network::OPTION, array( 'site_admins' => true ) );
		revoke_super_admin( self::$admin );
		$this->assertFalse( current_user_can_for_site( self::$ipsum_site, 'edit_css' ) );
		$this->assertTrue( current_user_can_for_site( self::$ipsum_site, 'edit_theme_options' ) );

		$preview = Library::with_scope( self::$ipsum_site, static fn() => Importer::import( $zip, 'looks.zip' ) );
		$this->assertIsArray( $preview );
		$this->assertSame( array(), $preview['notes'], 'Nothing left out' );
		$saved = Library::with_scope( self::$ipsum_site, static fn() => Importer::import( $zip, 'looks.zip', array( 'dry_run' => false ) ) );
		$this->assertIsArray( $saved );
		$item = Library::with_scope( self::$ipsum_site, static fn() => Library::get( $saved['id'] ) );
		$this->assertSame( $data['styles']['blocks'], $item['data']['styles']['blocks'], 'Every look stored as made' );

		// Used on their site: applied in full, and WordPress's own saved styles hold the looks.
		$results = Library::with_scope( self::$ipsum_site, static fn() => Applier::apply( $item, array( self::$ipsum_site ) ) );
		$this->assertSame( 'applied', $results[0]['status'] );
		switch_to_blog( self::$ipsum_site );
		wp_clean_theme_json_cache();
		$user = WP_Theme_JSON_Resolver::get_user_data()->get_raw_data();
		$css  = wp_get_global_stylesheet();
		restore_current_blog();
		$this->assertSame( 'var(--wp--preset--color--accent-6)', $user['styles']['blocks']['core/image']['border']['color'], 'WordPress writes presets in their CSS form' );
		$this->assertSame( 'none', $user['styles']['blocks']['core/quote']['border']['top']['style'] );
		$this->assertStringContainsString( 'border-width: 0 0 1px 0', $css );
	}

	public function test_the_site_editor_offers_it_as_theme_json(): void {
		$zip = $this->visitor_zip( 'first' );
		$this->assertIsArray( Importer::import( $zip, 'looks.zip', array( 'dry_run' => false ) ) );
		switch_theme( 'ipsum' );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/global-styles/themes/ipsum/variations' ) );
		$found    = array_values( array_filter( $response->get_data(), static fn( $v ): bool => 'E2E Looks first' === ( $v['title'] ?? '' ) ) );
		$this->assertCount( 1, $found );
		$this->assertSame( 'var(--wp--preset--shadow--soft)', $found[0]['styles']['blocks']['core/image']['shadow'] );
		$this->assertSame( 'uppercase', $found[0]['styles']['blocks']['core/navigation']['typography']['textTransform'] );
	}
}
