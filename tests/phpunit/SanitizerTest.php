<?php
/**
 * Validation of variation data.
 *
 * @package Windrose\Livery
 */

use Windrose\Livery\Fonts;
use Windrose\Livery\Sanitizer;

/**
 * @covers \Windrose\Livery\Sanitizer
 */
class SanitizerTest extends Livery_TestCase {

	private function map(): callable {
		return static fn( string $src ): string => Fonts::PREFIX . '0123456789abcdef-' . basename( $src );
	}

	public function test_a_brand_variation_is_kept_whole(): void {
		$raw = $this->brand();
		$raw['settings']['typography']['fontFamilies'] = $this->brand_fonts()['fontFamilies'];
		$raw['settings']['layout']                     = array(
			'contentSize' => '700px',
			'wideSize'    => '1200px',
		);
		$raw['settings']['custom']                     = array( 'line-height' => array( 'body' => '1.6' ) );
		$clean = Sanitizer::clean( $raw, 'Fallback', false, $this->map() );

		$this->assertIsArray( $clean );
		$this->assertSame( 'Harbour Brand', $clean['title'] );
		$this->assertSame( array(), $clean['notes'] );
		$this->assertSame( $raw['settings']['color'], $clean['data']['settings']['color'] );
		$this->assertSame( $raw['settings']['layout'], $clean['data']['settings']['layout'] );
		$this->assertSame( $raw['settings']['custom'], $clean['data']['settings']['custom'] );
		$this->assertSame( $raw['styles'], $clean['data']['styles'], 'Preset references stay as written (var:preset|...).' );
		$families = $clean['data']['settings']['typography']['fontFamilies'];
		$this->assertSame( 'windrose-livery:0123456789abcdef-baloo-400-normal.woff2', $families[0]['fontFace'][0]['src'][0] );
		$this->assertSame( 'Cabin, system-ui, sans-serif', $families[1]['fontFamily'] );
	}

	public function test_unknown_keys_files_and_unsafe_values_are_left_out_and_reported(): void {
		$raw                                  = $this->brand();
		$raw['templateParts']                 = array( array( 'name' => 'header' ) );
		$raw['settings']['bogus']             = true;
		$raw['settings']['custom']['bad']     = 'red; } body { display: none';
		$raw['styles']['color']['background'] = 'url(https://evil.example/x.png)';
		$raw['styles']['blocks']['core/group']['background']['backgroundImage'] = array( 'url' => 'https://evil.example/x.png' );
		$raw['styles']['blocks']['core/paragraph']['typography']['fontSize']    = 'expression(alert(1))';
		$clean = Sanitizer::clean( $raw, 'Fallback', true, $this->map() );

		$this->assertIsArray( $clean );
		$this->assertArrayNotHasKey( 'bogus', $clean['data']['settings'] );
		$this->assertArrayNotHasKey( 'custom', $clean['data']['settings'] );
		$this->assertArrayNotHasKey( 'color', $clean['data']['styles'] );
		$this->assertArrayNotHasKey( 'blocks', $clean['data']['styles'] );
		$notes = implode( "\n", $clean['notes'] );
		$this->assertStringContainsString( 'templateParts', $notes );
		$this->assertStringContainsString( 'settings › bogus', $notes );
		$this->assertStringContainsString( 'settings › custom › bad', $notes );
		$this->assertStringContainsString( 'styles › color › background (it would load a file)', $notes );
		$this->assertStringContainsString( 'backgroundImage', $notes );
		$this->assertStringContainsString( 'core/paragraph › typography › fontSize', $notes );
		$this->assertStringNotContainsString( 'evil.example', (string) wp_json_encode( $clean['data'] ) );
	}

	public function test_custom_css_needs_the_rights_and_never_loads_files(): void {
		$raw                  = $this->brand();
		$raw['styles']['css'] = '.wp-site-blocks { padding: 1rem; }';
		$raw['styles']['blocks']['core/button']['css'] = '& { letter-spacing: 1px; }';

		$without = Sanitizer::clean( $raw, 'F', false );
		$this->assertArrayNotHasKey( 'css', $without['data']['styles'] );
		$this->assertFalse( $without['css'] );
		$this->assertStringContainsString( 'custom CSS', implode( ' ', $without['notes'] ) );

		$with = Sanitizer::clean( $raw, 'F', true );
		$this->assertSame( '.wp-site-blocks { padding: 1rem; }', $with['data']['styles']['css'] );
		$this->assertSame( '& { letter-spacing: 1px; }', $with['data']['styles']['blocks']['core/button']['css'] );
		$this->assertTrue( $with['css'] );

		foreach ( array( '@import url(https://evil.example/a.css);', 'body { background: url(x.png) }', '</style><script>alert(1)</script>' ) as $css ) {
			$raw['styles']['css'] = $css;
			$clean                = Sanitizer::clean( $raw, 'F', true );
			$this->assertArrayNotHasKey( 'css', $clean['data']['styles'], $css );
		}
	}

	public function test_fonts_from_other_hosts_or_missing_files_are_left_out(): void {
		$raw = $this->brand();
		$raw['settings']['typography']['fontFamilies'] = array(
			array(
				'name'       => 'Remote',
				'slug'       => 'remote',
				'fontFamily' => 'Remote, sans-serif',
				'fontFace'   => array(
					array(
						'fontFamily' => 'Remote',
						'src'        => array( 'https://fonts.example.com/remote.woff2', '//cdn.example.com/r.woff2', 'data:font/woff2;base64,AAAA' ),
					),
				),
			),
			array(
				'name'       => 'Bad; name',
				'slug'       => 'bad',
				'fontFamily' => 'Bad; } body { color: red',
			),
			array(
				'name'       => 'System',
				'slug'       => 'system',
				'fontFamily' => 'system-ui, sans-serif',
			),
		);
		$clean    = Sanitizer::clean( $raw, 'F', false, static fn(): ?string => null );
		$families = $clean['data']['settings']['typography']['fontFamilies'];
		$this->assertSame( array( 'remote', 'system' ), array_column( $families, 'slug' ) );
		$this->assertArrayNotHasKey( 'fontFace', $families[0] );
		$notes = implode( "\n", $clean['notes'] );
		$this->assertStringContainsString( 'never loaded from another host', $notes );
		$this->assertStringContainsString( 'Bad; name', $notes );
		$this->assertStringContainsString( 'no usable font file', $notes );
	}

	public function test_files_that_are_not_full_variations_are_refused(): void {
		$this->assertWPError(
			Sanitizer::clean(
				array(
					'blockTypes' => array( 'core/group' ),
					'styles'     => array(),
				),
				'F',
				false
			)
		);
		$this->assertWPError(
			Sanitizer::clean(
				array(
					'version' => 99,
					'styles'  => array( 'color' => array() ),
				),
				'F',
				false
			)
		);
		$this->assertWPError( Sanitizer::clean( array( 'styles' => array( 'a', 'b' ) ), 'F', false ) );
		$this->assertWPError( Sanitizer::clean( array( 'title' => 'Only a title' ), 'F', false ) );
		$this->assertWPError( Sanitizer::clean( array( 'styles' => array( 'spacing' => array( 'blockGap' => '1rem' ) ) ), '', false ) );
	}

	public function test_title_falls_back_to_the_file_name_and_is_cleaned(): void {
		$raw = $this->brand();
		unset( $raw['title'] );
		$this->assertSame( 'My Brand', Sanitizer::clean( $raw, 'My Brand', false )['title'] );
		$raw['title'] = '<b>Bold</b> brand';
		$this->assertSame( 'Bold brand', Sanitizer::clean( $raw, 'F', false )['title'] );
	}

	public function test_an_older_theme_json_version_is_migrated(): void {
		$raw            = $this->brand();
		$raw['version'] = 2;
		$clean          = Sanitizer::clean( $raw, 'F', false );
		$this->assertIsArray( $clean );
		$this->assertSame( $raw['settings']['color']['palette'], $clean['data']['settings']['color']['palette'] );
	}
}
