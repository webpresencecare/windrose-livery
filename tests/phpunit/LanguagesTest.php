<?php
/**
 * Per-language font rules (`Languages`), font face sizes kept by the import checks and printed for the editor.
 *
 * @package Windrose\Livery
 */

use Windrose\Livery\Applier;
use Windrose\Livery\Fonts;
use Windrose\Livery\Importer;
use Windrose\Livery\Languages;
use Windrose\Livery\Library;
use Windrose\Livery\Sanitizer;

/**
 * @covers \Windrose\Livery\Languages
 * @covers \Windrose\Livery\Sanitizer
 * @covers \Windrose\Livery\Fonts
 */
class LanguagesTest extends Livery_TestCase {

	/**
	 * Font families as the design kit's styles have them (CJK web fonts in every stack).
	 *
	 * @return list<array<string, string>>
	 */
	private function kit_families(): array {
		$scripts = '"Beiruti", "Noto Sans SC", "Noto Sans JP", "Noto Sans KR"';
		return array(
			array(
				'slug'       => 'roboto-slab',
				'fontFamily' => '"Roboto Slab", ' . $scripts . ', serif',
			),
			array(
				'slug'       => 'source-sans-3',
				'fontFamily' => '"Source Sans 3", ' . $scripts . ', sans-serif',
			),
			array(
				'slug'       => 'noto-sans-jp',
				'fontFamily' => '"Noto Sans JP", sans-serif',
			),
			array(
				'slug'       => 'plain',
				'fontFamily' => 'Georgia, serif',
			),
		);
	}

	/**
	 * Styles: text and heading fonts.
	 *
	 * @return array<string, mixed>
	 */
	private function styles(): array {
		return array(
			'typography' => array( 'fontFamily' => 'var:preset|font-family|source-sans-3' ),
			'elements'   => array( 'heading' => array( 'typography' => array( 'fontFamily' => 'var(--wp--preset--font-family--roboto-slab)' ) ) ),
		);
	}

	public function test_a_language_font_moves_up_in_every_stack_with_script_fonts(): void {
		$css = Languages::css(
			array(
				'ja' => array(
					'heading' => 'noto-sans-jp',
					'text'    => 'noto-sans-jp',
				),
			),
			$this->kit_families(),
			$this->styles()
		);
		$this->assertStringContainsString( ':root:is(:lang(ja)){--wp--preset--font-family--roboto-slab:"Roboto Slab", "Noto Sans JP", "Beiruti", "Noto Sans SC", "Noto Sans KR", serif;--wp--preset--font-family--source-sans-3:"Source Sans 3", "Noto Sans JP", "Beiruti", "Noto Sans SC", "Noto Sans KR", sans-serif;}', $css );
		$this->assertStringNotContainsString( 'font-family--noto-sans-jp:', $css, 'Not the language font\'s own stack' );
		$this->assertStringNotContainsString( 'font-family--plain', $css, 'Not a stack without script fonts' );
		// Elements with their own language: the text font, headings the heading font.
		$this->assertStringContainsString( ':not(html)[lang]:is(:lang(ja)){--wp--preset--font-family--roboto-slab:', $css );
		$this->assertStringContainsString( 'font-family:var(--wp--preset--font-family--source-sans-3);}', $css );
		$this->assertStringContainsString( ':is(h1,h2,h3,h4,h5,h6)[lang]:is(:lang(ja)),:is(h1,h2,h3,h4,h5,h6) :not(html)[lang]:is(:lang(ja)){font-family:var(--wp--preset--font-family--roboto-slab);}', $css );
	}

	public function test_tags_aliases_order_and_bad_values(): void {
		$rules = Languages::rules(
			array(
				'zh-Hant'     => array(
					'heading' => 'noto-sans-jp',
					'text'    => 'noto-sans-jp',
				),
				'zh'          => array(
					'heading' => 'noto-sans-jp',
					'text'    => 'noto-sans-jp',
				),
				'x;}'         => array(
					'heading' => 'a',
					'text'    => 'a',
				),
				'fa'          => array(
					'heading' => 'bad slug;',
					'text'    => 'a',
				),
				'toolongtags' => 'not an array',
			)
		);
		$this->assertSame( array( 'zh', 'zh-hant' ), array_keys( $rules ), 'Lowercase, shorter first, bad ones out' );
		$css = Languages::css( $rules, $this->kit_families(), $this->styles() );
		$this->assertStringContainsString( ':root:is(:lang(zh-hant),:lang(zh-tw),:lang(zh-hk),:lang(zh-mo))', $css );
		$this->assertLessThan( strpos( $css, ':lang(zh-hant)' ), strpos( $css, ':lang(zh))' ) );

		// A rule naming a family the style does not have prints nothing; a stack with anything but names is skipped.
		$this->assertSame(
			'',
			Languages::css(
				array(
					'fa' => array(
						'heading' => 'vazirmatn',
						'text'    => 'vazirmatn',
					),
				),
				$this->kit_families(),
				$this->styles()
			)
		);
		$families                  = $this->kit_families();
		$families[0]['fontFamily'] = '"Roboto Slab", "x</style><script>", serif';
		$css                       = Languages::css( array( 'ja' => $rules['zh'] ), $families, $this->styles() );
		$this->assertStringNotContainsString( 'roboto-slab:', $css );
		$this->assertStringNotContainsString( '<', $css );
	}

	public function test_site_css_follows_the_sites_current_styles(): void {
		$this->assertSame( '', Languages::site_css(), 'Nothing without rules' );
		$item = $this->stored_with_rules();
		Applier::apply( $item, array( self::$ipsum_site ) );
		switch_to_blog( self::$ipsum_site );
		WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();
		$css = Languages::site_css();
		restore_current_blog();
		$this->assertStringContainsString( ':root:is(:lang(ja)){', $css );
		$this->assertStringContainsString( '"Fraunces", "Noto Sans JP", "Beiruti", "Noto Sans SC", serif', $css );
		WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();
	}

	public function test_a_site_administrator_keeps_the_language_rules(): void {
		$item = $this->stored_with_rules();
		// A site administrator, not a network administrator: WordPress removes settings.custom from their saves.
		revoke_super_admin( self::$admin );
		wp_set_current_user( self::$admin );
		$this->assertFalse( current_user_can_for_site( self::$ipsum_site, 'unfiltered_html' ) );
		$results = Applier::apply( $item, array( self::$ipsum_site ) );
		$this->assertContains( $results[0]['status'], array( 'applied', 'filtered' ) );
		switch_to_blog( self::$ipsum_site );
		WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();
		$css = Languages::site_css();
		restore_current_blog();
		$this->assertStringContainsString( ':root:is(:lang(ja)){', $css, 'The rule travels in the language font\'s slug, given on import' );
		$this->assertStringContainsString( '"Fraunces", "Noto Sans JP", "Beiruti", "Noto Sans SC", serif', $css );
		WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();
	}

	public function test_rules_from_family_slugs(): void {
		$this->assertSame(
			array(
				'fa'      => array(
					'heading' => 'vazirmatn-arabic-lang-fa',
					'text'    => 'vazirmatn-arabic-lang-fa',
				),
				'zh-hant' => array(
					'heading' => 'noto-sans-tc-lang-zh-hant',
					'text'    => 'noto-sans-tc-lang-zh-hant',
				),
			),
			Languages::from_families(
				array(
					array( 'slug' => 'vazirmatn-arabic-lang-fa' ),
					array( 'slug' => 'noto-sans-tc-lang-zh-hant' ),
					array( 'slug' => 'fraunces' ),
					array( 'slug' => 'bad-lang-' ),
					'not a family',
				)
			)
		);
	}

	public function test_on_import_a_rule_font_used_elsewhere_keeps_its_slug(): void {
		$data = array(
			'settings' => array(
				'custom'     => array(
					Languages::KEY => array(
						'ja' => array(
							'heading' => 'noto-sans-jp',
							'text'    => 'noto-sans-jp',
						),
						'ko' => array(
							'heading' => 'noto-sans-kr',
							'text'    => 'noto-sans-kr',
						),
						'zh' => array(
							'heading' => 'noto-sans-sc',
							'text'    => 'noto-sans-sc',
						),
					),
				),
				'typography' => array(
					'fontFamilies' => array(
						array( 'slug' => 'noto-sans-jp' ),
						array( 'slug' => 'noto-sans-kr' ),
						array( 'slug' => 'noto-sans-sc' ),
					),
				),
			),
			'styles'   => array(
				'elements' => array( 'button' => array( 'typography' => array( 'fontFamily' => 'var:preset|font-family|noto-sans-kr' ) ) ),
				'blocks'   => array( 'core/quote' => array( 'typography' => array( 'fontFamily' => 'var(--wp--preset--font-family--noto-sans-sc)' ) ) ),
			),
		);
		$out  = Languages::into_slugs( $data );
		$this->assertSame( array( 'noto-sans-jp-lang-ja', 'noto-sans-kr', 'noto-sans-sc' ), array_column( $out['settings']['typography']['fontFamilies'], 'slug' ), 'Korean\'s font is also the buttons\' font, Chinese\'s the quotes\' (CSS variable form): renaming them would break them' );
		$this->assertSame( 'noto-sans-jp-lang-ja', $out['settings']['custom'][ Languages::KEY ]['ja']['text'] );
		$this->assertSame( 'noto-sans-kr', $out['settings']['custom'][ Languages::KEY ]['ko']['text'] );
		$this->assertSame( $out, Languages::into_slugs( $out ), 'Twice is once' );
	}

	/**
	 * A stored Ipsum variation with a Japanese rule in settings.custom, as the design kit's styles have it.
	 *
	 * @return array<string, mixed>
	 */
	private function stored_with_rules(): array {
		$face    = static fn( string $name, string $file ): array => array(
			'fontFamily' => $name,
			'fontWeight' => '400',
			'fontStyle'  => 'normal',
			'src'        => array( 'file:./fonts/' . $file ),
		);
		$scripts = '"Beiruti", "Noto Sans SC", "Noto Sans JP"';
		$data    = array(
			'version'  => 3,
			'title'    => 'Rules',
			'settings' => array(
				'custom'     => array(
					Languages::KEY => array(
						'ja' => array(
							'heading' => 'noto-sans-jp',
							'text'    => 'noto-sans-jp',
						),
					),
				),
				'typography' => array(
					'fontFamilies' => array(
						array(
							'name'       => 'Fraunces',
							'slug'       => 'fraunces',
							'fontFamily' => '"Fraunces", ' . $scripts . ', serif',
							'fontFace'   => array( $face( 'Fraunces', 'fraunces.woff2' ) ),
						),
						array(
							'name'       => 'Noto Sans JP',
							'slug'       => 'noto-sans-jp',
							'fontFamily' => '"Noto Sans JP", sans-serif',
							'fontFace'   => array( $face( 'Noto Sans JP', 'jp.woff2' ) + array( 'sizeAdjust' => '105%' ) ),
						),
					),
				),
			),
			'styles'   => array( 'typography' => array( 'fontFamily' => 'var:preset|font-family|fraunces' ) ),
		);
		$zip     = $this->zip(
			array(
				'rules/rules.json'           => (string) wp_json_encode( $data ),
				'rules/fonts/fraunces.woff2' => $this->woff2( 'f' ),
				'rules/fonts/jp.woff2'       => $this->woff2( 'j' ),
			)
		);
		$preview = Importer::import( $zip, 'rules.zip', array( 'theme' => 'ipsum' ) );
		$this->assertIsArray( $preview, is_wp_error( $preview ) ? $preview->get_error_message() : '' );
		$this->assertSame( array(), $preview['notes'] );
		$result = Importer::import(
			$zip,
			'rules.zip',
			array(
				'dry_run' => false,
				'theme'   => 'ipsum',
			)
		);
		$this->assertIsArray( $result );
		$item = Library::get( $result['id'] );
		$this->assertSame( 'noto-sans-jp-lang-ja', $item['data']['settings']['custom'][ Languages::KEY ]['ja']['text'], 'Kept by the import checks, with the language in the font\'s slug' );
		$this->assertContains( 'noto-sans-jp-lang-ja', array_column( $item['data']['settings']['typography']['fontFamilies'], 'slug' ) );
		return $item;
	}

	public function test_size_adjust_and_overrides_are_kept_and_printed(): void {
		$notes    = array();
		$families = Sanitizer::clean_families(
			array(
				array(
					'name'       => 'Beiruti',
					'slug'       => 'beiruti',
					'fontFamily' => '"Beiruti", sans-serif',
					'fontFace'   => array(
						array(
							'fontFamily'      => 'Beiruti',
							'src'             => array( 'windrose-livery:0123456789abcdef-beiruti.woff2' ),
							'sizeAdjust'      => '115%',
							'ascentOverride'  => '90%',
							'lineGapOverride' => 'normal',
							'descentOverride' => '30%;color:red',
						),
						array(
							'fontFamily' => 'Beiruti',
							'src'        => array( 'windrose-livery:0123456789abcdef-beiruti.woff2' ),
							'sizeAdjust' => 'calc(100% + 1px)',
						),
					),
				),
			),
			null,
			$notes
		);
		$face     = $families[0]['fontFace'][0];
		$this->assertSame( '115%', $face['sizeAdjust'] );
		$this->assertSame( '90%', $face['ascentOverride'] );
		$this->assertSame( 'normal', $face['lineGapOverride'] );
		$this->assertArrayNotHasKey( 'descentOverride', $face );
		$this->assertArrayNotHasKey( 'sizeAdjust', $families[0]['fontFace'][1] );

		$css = Fonts::css( Fonts::resolve_families( $families ) );
		$this->assertStringContainsString( 'size-adjust:115%;ascent-override:90%;line-gap-override:normal;', $css );
	}
}
