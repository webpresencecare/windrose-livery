<?php
/**
 * Light and dark: twin colours, the page rules, the head script, the switch block and its place, contrast of
 * the other scheme.
 *
 * @package Windrose\Livery
 */

use Windrose\Livery\Applier;
use Windrose\Livery\Builder;
use Windrose\Livery\Contrast;
use Windrose\Livery\Importer;
use Windrose\Livery\Library;
use Windrose\Livery\Mermaid;
use Windrose\Livery\Schemes;

/**
 * @covers \Windrose\Livery\Schemes
 * @covers \Windrose\Livery\Contrast
 */
class SchemesTest extends Livery_TestCase {

	/**
	 * A palette with dark twins.
	 *
	 * @return list<array{slug: string, color: string, name: string}>
	 */
	private function palette(): array {
		return array(
			array(
				'slug'  => 'base',
				'color' => '#ffffff',
				'name'  => 'Background',
			),
			array(
				'slug'  => 'contrast',
				'color' => '#111111',
				'name'  => 'Text',
			),
			array(
				'slug'  => 'windrose-livery-dark-base',
				'color' => '#121212',
				'name'  => 'Dark: Background',
			),
			array(
				'slug'  => 'windrose-livery-dark-contrast',
				'color' => '#eeeeee',
				'name'  => 'Dark: Text',
			),
			array(
				'slug'  => 'windrose-livery-dark-gone',
				'color' => '#000000',
				'name'  => 'Twin of nothing',
			),
		);
	}

	/**
	 * Applies a variation with these colours to the Ipsum site.
	 */
	private function site_with_twins(): void {
		$brand                                 = $this->brand();
		$brand['settings']['color']['palette'] = $this->palette();
		$saved                                 = Importer::import( $this->brand_zip( $brand ), 'h.zip', array( 'dry_run' => false ) );
		Applier::apply( Library::get( $saved['id'] ), array( self::$ipsum_site ) );
	}

	public function test_twins_are_found_by_slug_when_their_colour_exists(): void {
		$this->assertSame(
			array(
				'scheme' => 'dark',
				'map'    => array(
					'base'     => 'windrose-livery-dark-base',
					'contrast' => 'windrose-livery-dark-contrast',
				),
			),
			Schemes::twins( $this->palette() )
		);
		$this->assertSame( '', Schemes::twins( array_slice( $this->palette(), 0, 2 ) )['scheme'] );
	}

	public function test_applied_twins_are_custom_colours_apart_from_the_style_s_own(): void {
		$this->site_with_twins();
		$palette = $this->site_styles( self::$ipsum_site )['settings']['color']['palette'];
		$this->assertSame( array( 'base', 'contrast' ), array_column( $palette['theme'], 'slug' ), 'The style’s own colours: Theme.' );
		$this->assertSame(
			array( 'windrose-livery-dark-base', 'windrose-livery-dark-contrast', 'windrose-livery-dark-gone' ),
			array_column( $palette['custom'], 'slug' ),
			'Twins: Custom, after any custom colours.'
		);
		switch_to_blog( self::$ipsum_site );
		WP_Theme_JSON_Resolver::clean_cached_data();
		$this->assertSame( 'dark', Schemes::current()['scheme'], 'Found across origins.' );
		restore_current_blog();
		WP_Theme_JSON_Resolver::clean_cached_data();
	}

	public function test_the_page_rules_point_colours_at_their_twins(): void {
		$twins = Schemes::twins( $this->palette() );
		$css   = Schemes::css( $twins, false );
		$this->assertSame(
			':root[data-windrose-livery-scheme="dark"]{color-scheme:dark;--wp--custom--control-scheme:dark;--wp--preset--color--base:var(--wp--preset--color--windrose-livery-dark-base);--wp--preset--color--contrast:var(--wp--preset--color--windrose-livery-dark-contrast);}',
			$css
		);
		$this->assertStringContainsString( '@media (prefers-color-scheme:dark){:root:not([data-windrose-livery-scheme="light"]){', Schemes::css( $twins, true ), 'The visitor’s "light" choice wins over the browser.' );
		$this->assertSame(
			'',
			Schemes::css(
				array(
					'scheme' => '',
					'map'    => array(),
				),
				true
			)
		);
	}

	public function test_nothing_is_printed_until_an_option_is_on(): void {
		$this->site_with_twins();
		switch_to_blog( self::$ipsum_site );
		WP_Theme_JSON_Resolver::clean_cached_data();
		$this->assertSame( 'dark', Schemes::current()['scheme'] );

		ob_start();
		Schemes::head_script();
		$this->assertSame( '', ob_get_clean() );
		$this->assertSame( '', Schemes::render( array() ) );

		Schemes::save_settings( self::$ipsum_site, true, false );
		ob_start();
		Schemes::head_script();
		$head = (string) ob_get_clean();
		$this->assertStringContainsString( 'localStorage.getItem("windrose-livery-scheme")', $head );
		$this->assertStringContainsString( 'setAttribute("data-windrose-livery-scheme",s)', $head );

		$html = Schemes::render( array( 'showLabel' => true ) );
		$this->assertStringContainsString( '<button type="button"', $html );
		$this->assertStringContainsString( 'data-wp-interactive="' . Schemes::STORE . '"', $html );
		$this->assertStringContainsString( 'data-wp-on--click="actions.toggle"', $html );
		$this->assertStringContainsString( 'data-wp-bind--aria-pressed="state.dark"', $html );
		$config = wp_interactivity_config( Schemes::STORE );
		$this->assertSame( 'light', $config['own'] ?? null );
		$this->assertSame( 'dark', $config['twin'] ?? null );
		$this->assertFalse( $config['follow'] ?? null );
		$this->assertSame( Schemes::STORAGE, $config['storage'] ?? null );
		$this->assertSame( Schemes::ATTR, $config['attribute'] ?? null );
		$this->assertFalse( wp_interactivity_state( Schemes::STORE )['dark'] ?? null );
		$this->assertStringContainsString( 'aria-pressed="false"', $html );
		// With the directives processed, as on a page: aria-pressed comes from the state (it would go without it).
		$this->assertStringContainsString( 'aria-pressed="false"', wp_interactivity_process_directives( $html ) );
		$this->assertStringContainsString( '>Dark mode</span>', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html, 'The icon is decoration.' );

		Schemes::save_settings( self::$ipsum_site, false, true );
		ob_start();
		Schemes::head_script();
		$this->assertSame( '', ob_get_clean(), 'Following the browser alone needs no script.' );
		$this->assertSame( '', Schemes::render( array() ), 'No switch.' );
		restore_current_blog();
		WP_Theme_JSON_Resolver::clean_cached_data();
	}

	public function test_inside_a_navigation_the_switch_is_a_menu_item(): void {
		$this->site_with_twins();
		switch_to_blog( self::$ipsum_site );
		WP_Theme_JSON_Resolver::clean_cached_data();
		Schemes::save_settings( self::$ipsum_site, true, false );
		// Core's Navigation gives its items the context maxNestingLevel.
		$block = new WP_Block(
			array(
				'blockName'    => Schemes::BLOCK,
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			array( 'maxNestingLevel' => 5 )
		);
		$html  = $block->render();
		restore_current_blog();
		WP_Theme_JSON_Resolver::clean_cached_data();
		$this->assertMatchesRegularExpression( '#<li [^>]*class="[^"]*wp-block-navigation-item[^"]*windrose-livery-switch#', $html );
		$this->assertStringContainsString( 'wp-block-navigation-item__content windrose-livery-switch__button', $html );
		$this->assertContains( Schemes::BLOCK, WP_Block_Type_Registry::get_instance()->get_registered( 'core/navigation' )->allowed_blocks ?? array() );
	}

	public function test_the_switch_is_hooked_after_the_header_navigation_only(): void {
		$this->site_with_twins();
		switch_to_blog( self::$ipsum_site );
		WP_Theme_JSON_Resolver::clean_cached_data();
		$header       = new WP_Block_Template();
		$header->type = 'wp_template_part';
		$header->area = 'header';
		$footer       = new WP_Block_Template();
		$footer->type = 'wp_template_part';
		$footer->area = 'footer';

		$this->assertSame( array(), Schemes::hook_block( array(), 'after', 'core/navigation', $header ), 'Option off.' );
		Schemes::save_settings( self::$ipsum_site, true, false );
		$this->assertSame( array( Schemes::BLOCK ), Schemes::hook_block( array(), 'after', 'core/navigation', $header ) );
		$this->assertSame( array( Schemes::BLOCK ), Schemes::hook_block( array(), 'after', 'core/navigation', array( 'categories' => array( 'header' ) ) ), 'Ipsum’s header pattern.' );
		$this->assertSame( array(), Schemes::hook_block( array(), 'after', 'core/navigation', $footer ) );
		$this->assertSame( array(), Schemes::hook_block( array(), 'before', 'core/navigation', $header ) );
		$this->assertSame( array(), Schemes::hook_block( array(), 'after', 'core/site-title', $header ) );
		restore_current_blog();
		WP_Theme_JSON_Resolver::clean_cached_data();
	}

	public function test_the_other_scheme_is_checked_for_contrast(): void {
		$settings = array( 'color' => array( 'palette' => $this->palette() ) );
		$styles   = array(
			'color' => array(
				'background' => 'var:preset|color|base',
				'text'       => 'var:preset|color|contrast',
			),
		);
		$twin     = Contrast::check_twins( $settings, $styles );
		$text     = array_values( array_filter( $twin, static fn( array $c ): bool => 'text' === $c['id'] ) )[0];
		$this->assertSame( 'rgb(238,238,238)', $text['fg'] );
		$this->assertSame( 'rgb(18,18,18)', $text['bg'] );
		$this->assertTrue( $text['pass'] );
		$this->assertNull( Contrast::check_twins( array( 'color' => array( 'palette' => array_slice( $this->palette(), 0, 2 ) ) ), $styles ) );

		// A twin text colour too close to its twin background fails, and the summary says so.
		$bad                                 = $settings;
		$bad['color']['palette'][3]['color'] = '#333333';
		$this->assertFalse( Contrast::summary( $bad, $styles )['twin'] );
		$this->assertTrue( Contrast::summary( $bad, $styles )['own'] );
	}

	public function test_visitors_cannot_download_a_style_whose_other_scheme_fails(): void {
		update_option( Mermaid::OPTION, array( 'public' => true ) );
		wp_set_current_user( 0 );
		$recipe = array(
			'title'    => 'Night',
			'settings' => array( 'color' => array( 'palette' => $this->palette() ) ),
			'styles'   => array(),
			'fonts'    => array(
				'heading' => array(
					'source' => 'bundled',
					'slug'   => 'inter',
				),
				'text'    => array(
					'source' => 'bundled',
					'slug'   => 'inter',
				),
			),
		);
		$recipe['settings']['color']['palette'][3]['color'] = '#222222';
		$refused = Mermaid::prepare_download( (string) wp_json_encode( $recipe ) );
		$this->assertWPError( $refused );
		$this->assertSame( 'windrose_livery_contrast', $refused->get_error_code() );
		$this->assertNotEmpty( $refused->get_error_data()['twinChecks'] );

		$recipe['settings']['color']['palette'][3]['color'] = '#eeeeee';
		$built = Builder::clean( Builder::parse( (string) wp_json_encode( $recipe ) ) );
		$this->assertIsArray( $built['twinChecks'] );
		$this->assertTrue( Contrast::passes( $built['twinChecks'] ) );
	}
}
