<?php
/**
 * Style Mermaid: catalog, builder, contrast (shared fixtures with the JavaScript), fonts from Google Fonts and Bunny
 * Fonts (HTTP mocked), rights, the public download and its limits, adding to the library.
 *
 * @package Windrose\Livery
 */

use Windrose\Livery\Builder;
use Windrose\Livery\Catalog;
use Windrose\Livery\Contrast;
use Windrose\Livery\Importer;
use Windrose\Livery\Library;
use Windrose\Livery\Mermaid;
use Windrose\Livery\Rate_Limit;
use Windrose\Livery\Remote_Fonts;
use Windrose\Livery\Sanitizer;

/**
 * @covers \Windrose\Livery\Builder
 * @covers \Windrose\Livery\Catalog
 * @covers \Windrose\Livery\Contrast
 * @covers \Windrose\Livery\Mermaid
 * @covers \Windrose\Livery\Rate_Limit
 * @covers \Windrose\Livery\Remote_Fonts
 */
class MermaidTest extends Livery_TestCase {

	/**
	 * HTTP requests the code made (mocked).
	 *
	 * @var list<string>
	 */
	private array $requests = array();

	public function set_up(): void {
		parent::set_up();
		$this->requests         = array();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ) );
		Remote_Fonts::clear();
		$this->remove_dir( Remote_Fonts::dir()['path'] );
		parent::tear_down();
	}

	/**
	 * Answers the font services from fixtures; any other host is a test failure.
	 *
	 * @param false|array<mixed> $pre  Short-circuit.
	 * @param array<mixed>       $args Arguments.
	 * @param string             $url  Address.
	 * @return array<string, mixed>
	 */
	public function mock_http( $pre, $args, $url ): array {
		$this->requests[] = $url;
		$host             = (string) wp_parse_url( $url, PHP_URL_HOST );
		$ok               = static fn( string $body ): array => array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
		if ( 'fonts.googleapis.com' === $host ) {
			if ( str_contains( $url, 'Nonexistent' ) ) {
				return array(
					'headers'  => array(),
					'body'     => 'Bad Request',
					'response' => array(
						'code'    => 400,
						'message' => 'Bad Request',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
			return $ok( (string) file_get_contents( __DIR__ . '/../fixtures/google-beiruti.css' ) );
		}
		if ( 'fonts.bunny.net' === $host && str_starts_with( (string) wp_parse_url( $url, PHP_URL_PATH ), '/css' ) ) {
			return $ok( (string) file_get_contents( __DIR__ . '/../fixtures/bunny-beiruti.css' ) );
		}
		if ( 'fonts.gstatic.com' === $host || 'fonts.bunny.net' === $host ) {
			return $ok( $this->woff2( $url ) );
		}
		$this->fail( 'Unexpected request to ' . $url );
	}

	/**
	 * A recipe from a ready style (fonts by role resolved as the browser does).
	 *
	 * @return array<string, mixed>
	 */
	private function recipe( string $slug = 'harbour', array $extra = array() ): array {
		foreach ( Catalog::styles() as $style ) {
			if ( $style['slug'] === $slug ) {
				$styles = json_decode( str_replace( array( '|@heading', '|@text' ), array( '|' . $style['fonts']['heading'], '|' . $style['fonts']['text'] ), (string) wp_json_encode( $style['styles'] ) ), true );
				return array_merge(
					array(
						'title'       => 'E2E ' . $style['title'],
						'description' => '',
						'settings'    => $style['settings'],
						'styles'      => $styles,
						'fonts'       => array(
							'heading' => array(
								'source' => 'bundled',
								'slug'   => $style['fonts']['heading'],
							),
							'text'    => array(
								'source' => 'bundled',
								'slug'   => $style['fonts']['text'],
							),
						),
					),
					$extra
				);
			}
		}
		$this->fail( 'No ready style ' . $slug );
	}

	/**
	 * Parses a recipe as the routes do.
	 *
	 * @param array<string, mixed> $recipe Recipe.
	 * @return array{title: string, description: string, settings: array<string, mixed>, styles: array<string, mixed>, fonts: array<string, mixed>}
	 */
	private function parsed( array $recipe ): array {
		$parsed = Builder::parse( (string) wp_json_encode( $recipe ) );
		$this->assertIsArray( $parsed );
		return $parsed;
	}

	/**
	 * Sends a REST request.
	 *
	 * @param array<string, mixed> $params Parameters.
	 */
	private function call( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/windrose-livery/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	public function test_catalog_has_the_fonts_their_files_and_licenses(): void {
		$fonts = Catalog::fonts();
		$this->assertCount( 39, $fonts, '37 families and Manrope and JetBrains Mono' );
		$this->assertNull( Catalog::font( 'merriweather' ), 'Left out on purpose' );
		foreach ( $fonts as $font ) {
			$this->assertNotSame( array(), Catalog::licenses( $font['slug'] ), $font['slug'] );
			foreach ( $font['faces'] as $face ) {
				$path = Catalog::path( $font['slug'], $face['file'] );
				$this->assertFileExists( $path );
				$this->assertStringStartsWith( 'wOF2', (string) file_get_contents( $path, false, null, 0, 4 ) );
			}
		}
		$this->assertSame( '', Catalog::path( 'lora', '../../windrose-livery.php' ), 'Only files of the family' );
		$this->assertCount( 21, Catalog::styles() );
		$this->assertSame( 'ipsum', Catalog::styles()[0]['slug'] );
		$this->assertCount( 7, Catalog::palettes() );
	}

	public function test_every_ready_style_builds_cleanly_and_passes_contrast(): void {
		foreach ( Catalog::styles() as $style ) {
			$built = Builder::clean( $this->parsed( $this->recipe( $style['slug'] ) ) );
			$this->assertIsArray( $built, $style['slug'] );
			$this->assertSame( array(), $built['clean']['notes'], $style['slug'] );
			$this->assertTrue( Contrast::passes( $built['checks'] ), $style['slug'] );
			$slugs = array_column( $built['clean']['data']['settings']['typography']['fontFamilies'], 'slug' );
			$this->assertContains( 'manrope', $slugs );
			$this->assertContains( 'jetbrains-mono', $slugs );
			$this->assertContains( $style['fonts']['heading'], $slugs );
			foreach ( $built['pending'] as $file ) {
				$this->assertContains( $file['entry']['kind'], array( 'font', 'license' ) );
			}
		}
	}

	public function test_contrast_matches_the_javascript_fixtures(): void {
		$fixtures = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/contrast.json' ), true );
		$this->assertNotEmpty( $fixtures );
		foreach ( $fixtures as $fixture ) {
			$checks = array_map( static fn( array $c ): array => array( $c['id'], $c['ratio'], $c['pass'] ), Contrast::check( $fixture['settings'], $fixture['styles'] ) );
			$this->assertSame( $fixture['expected'], $checks, $fixture['name'] );
		}
	}

	public function test_contrast_reads_colors_and_blends_transparency(): void {
		$this->assertSame( array( 255.0, 255.0, 255.0, 1.0 ), Contrast::parse( '#fff' ) );
		$this->assertEqualsWithDelta( 0.5, Contrast::parse( 'rgba(0 0 0 / 50%)' )[3], 0.001 );
		$this->assertNull( Contrast::parse( 'var(--x)' ) );
		$this->assertEqualsWithDelta( 21.0, Contrast::ratio( array( 0.0, 0.0, 0.0, 1.0 ), array( 255.0, 255.0, 255.0, 1.0 ) ), 0.001 );
	}

	public function test_understands_names_and_addresses_of_both_services(): void {
		$this->assertSame(
			array(
				'provider' => 'google',
				'family'   => 'Noto Sans Arabic',
			),
			Remote_Fonts::understand( 'https://fonts.googleapis.com/css2?family=Noto+Sans+Arabic:wght@100..900&display=swap', 'bunny' )
		);
		$this->assertSame(
			array(
				'provider' => 'bunny',
				'family'   => 'Frank Ruhl Libre',
			),
			Remote_Fonts::understand( 'https://fonts.bunny.net/css?family=frank-ruhl-libre:400,700|heebo:400', 'google' )
		);
		$this->assertSame( 'Merriweather', Remote_Fonts::understand( '  Merriweather ', 'google' )['family'] );
		// Font pages, as people copy them from the address bar.
		foreach (
			array(
				'https://fonts.bunny.net/family/unica-one' => array( 'bunny', 'Unica One' ),
				'fonts.bunny.net/family/noto-sans-arabic'  => array( 'bunny', 'Noto Sans Arabic' ),
				'https://fonts.google.com/specimen/Unica+One' => array( 'google', 'Unica One' ),
				'https://fonts.google.com/specimen/Noto%20Sans%20Arabic/about?query=x' => array( 'google', 'Noto Sans Arabic' ),
				'https://fonts.google.com/share?selection.family=Lobster|Lora:ital,wght@0,400' => array( 'google', 'Lobster' ),
				'http://fonts.google.com/specimen/Lora'    => array( 'google', 'Lora' ),
			) as $address => [ $provider, $family ]
		) {
			$this->assertSame(
				array(
					'provider' => $provider,
					'family'   => $family,
				),
				Remote_Fonts::understand( $address, 'bunny' === $provider ? 'google' : 'bunny' ),
				$address
			);
		}
		$this->assertWPError( Remote_Fonts::understand( 'https://evil.example/css?family=Lora', 'google' ) );
		$this->assertWPError( Remote_Fonts::understand( 'https://fonts.bunny.net/about', 'bunny' ), 'No family named' );
		$this->assertWPError( Remote_Fonts::understand( 'https://fonts.google.com/', 'google' ), 'No family named' );
		$this->assertWPError( Remote_Fonts::understand( 'Lora";}', 'google' ) );
		$this->assertWPError( Remote_Fonts::understand( 'Lora', 'cdn' ) );
	}

	public function test_parses_both_services_css_and_refuses_other_hosts(): void {
		$google = Remote_Fonts::parse_css( (string) file_get_contents( __DIR__ . '/../fixtures/google-beiruti.css' ), 'fonts.gstatic.com' );
		$bunny  = Remote_Fonts::parse_css( (string) file_get_contents( __DIR__ . '/../fixtures/bunny-beiruti.css' ), 'fonts.bunny.net' );
		foreach ( array( $google, $bunny ) as $faces ) {
			$this->assertCount( 8, $faces );
			$this->assertContains( 'arabic', array_column( $faces, 'subset' ) );
			$this->assertSame( 'Beiruti', $faces[0]['fontFamily'] );
			$this->assertMatchesRegularExpression( '/^U\+/', $faces[0]['unicodeRange'] );
		}
		$this->assertSame( array(), Remote_Fonts::parse_css( (string) file_get_contents( __DIR__ . '/../fixtures/google-beiruti.css' ), 'fonts.bunny.net' ), 'Files must come from the provider’s own host' );
	}

	public function test_cache_files_no_entry_names_go_after_an_hour(): void {
		$dir = Remote_Fonts::dir()['path'];
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/0123456789abcdef-orphan-old.woff2', 'wOF2 old' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A test file in the cache folder.
		file_put_contents( $dir . '/fedcba9876543210-orphan-new.woff2', 'wOF2 new' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Same.
		touch( $dir . '/0123456789abcdef-orphan-old.woff2', time() - 2 * HOUR_IN_SECONDS );

		$entry = Remote_Fonts::fetch( 'bunny', 'Beiruti', array( 400, 700 ), false, array( 'arabic' ) );
		$this->assertIsArray( $entry );
		$this->assertFileDoesNotExist( $dir . '/0123456789abcdef-orphan-old.woff2', 'Left by a lost entry more than an hour ago' );
		$this->assertFileExists( $dir . '/fedcba9876543210-orphan-new.woff2', 'A fetch may still be writing it' );
		foreach ( $entry['faces'] as $face ) {
			$this->assertFileExists( Remote_Fonts::path( $face['stored'] ) );
		}
		$this->assertFileExists( Remote_Fonts::path( $entry['license'] ) );
	}

	public function test_fetches_a_script_family_once_and_keeps_only_its_subset(): void {
		$entry = Remote_Fonts::fetch( 'bunny', 'Beiruti', array( 400, 700 ), false, array( 'arabic' ) );
		$this->assertIsArray( $entry );
		$this->assertSame( array( 'arabic' ), $entry['subsets'] );
		$this->assertCount( 2, $entry['faces'] );
		foreach ( $entry['faces'] as $face ) {
			$this->assertFileExists( Remote_Fonts::path( $face['stored'] ) );
		}
		$this->assertStringContainsString( 'https://fonts.bunny.net/family/beiruti', (string) Remote_Fonts::read( $entry['license'] ) );
		$count = count( $this->requests );
		$this->assertSame( 3, $count, 'The CSS, then two files' );
		$again = Remote_Fonts::fetch( 'bunny', 'Beiruti', array( 700, 400 ), false, array( 'arabic' ) );
		$this->assertSame( $entry['key'], $again['key'] );
		$this->assertCount( $count, $this->requests, 'Cached: no new request' );

		$this->assertWPError( Remote_Fonts::fetch( 'google', 'Nonexistent', array( 400 ), false, array( 'latin' ) ) );
		Remote_Fonts::forget( $entry['key'] );
		$this->assertNull( Remote_Fonts::get( $entry['key'] ) );
		$this->assertFileDoesNotExist( Remote_Fonts::path( $entry['faces'][0]['stored'] ) );
	}

	public function test_refuses_files_that_are_not_fonts(): void {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ) );
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				$css = (string) file_get_contents( __DIR__ . '/../fixtures/google-beiruti.css' );
				return array(
					'headers'  => array(),
					'body'     => str_contains( $url, 'googleapis' ) ? $css : '<html>not a font</html>',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
		$this->assertWPError( Remote_Fonts::fetch( 'google', 'Beiruti', array( 400 ), false, array( 'arabic' ) ) );
		$this->assertSame( array(), Remote_Fonts::keys() );
	}

	public function test_script_fonts_join_every_stack_and_keep_their_own_name(): void {
		$beiruti = Remote_Fonts::fetch( 'google', 'Beiruti', array( 400, 700 ), false, array( 'arabic' ) );
		$recipe  = $this->recipe(
			'backstage',
			array()
		);
		// Backstage uses Rubik for text: a "Rubik" for Hebrew must not mix with it (played by Bunny's Beiruti files).
		$rubik_entry             = Remote_Fonts::fetch( 'bunny', 'Beiruti', array( 400, 700 ), false, array( 'hebrew', 'arabic' ) );
		$rubik_entry['name']     = 'Rubik';
		$rubik_entry['slug']     = 'rubik';
		$rubik_entry['key']      = 'rubik-test-key';
		$index                   = get_option( Remote_Fonts::OPTION, array() );
		$index['rubik-test-key'] = $rubik_entry;
		update_option( Remote_Fonts::OPTION, $index, false );

		$recipe['fonts']['scripts'] = array(
			'arabic' => array(
				'heading' => array(
					'source' => 'remote',
					'key'    => $beiruti['key'],
				),
				'text'    => array(
					'source' => 'remote',
					'key'    => $beiruti['key'],
				),
			),
			'hebrew' => array(
				'heading' => array(
					'source' => 'remote',
					'key'    => 'rubik-test-key',
				),
				'text'    => array(
					'source' => 'remote',
					'key'    => 'rubik-test-key',
				),
			),
		);
		$built                      = Builder::font_families( $recipe['fonts'] );
		$this->assertIsArray( $built );
		$stacks = array_column( $built['families'], 'fontFamily', 'slug' );
		$this->assertSame( '"Rubik", "Beiruti", "Rubik Hebrew", sans-serif', $stacks['rubik'] );
		$this->assertStringContainsString( '"Beiruti", "Rubik Hebrew"', $stacks['manrope'] );
		$this->assertArrayHasKey( 'rubik-hebrew', $stacks );
		$this->assertSame( '"Rubik Hebrew", sans-serif', $stacks['rubik-hebrew'] );
		$faces = array_column( $built['families'], 'fontFace', 'slug' );
		$this->assertSame( 'Rubik Hebrew', $faces['rubik-hebrew'][0]['fontFamily'] );
		$this->assertArrayHasKey( 'unicodeRange', $faces['beiruti'][0] );

		// The whole style: script families stored with their LICENSE.txt, and accepted by the import checks.
		$built = Builder::clean( $this->parsed( $recipe ) );
		$this->assertIsArray( $built );
		$this->assertSame( array(), $built['clean']['notes'] );
		$licenses = array_filter( $built['pending'], static fn( array $p ): bool => 'license' === $p['entry']['kind'] && 'beiruti' === $p['entry']['family'] );
		$this->assertCount( 1, $licenses );
	}

	public function test_cyrillic_fallback_only_for_families_without_cyrillic(): void {
		$noto                       = Remote_Fonts::fetch( 'google', 'Beiruti', array( 400 ), false, array( 'arabic' ) );
		$recipe                     = $this->recipe( 'quiet-room' );
		$recipe['fonts']['scripts'] = array(
			'cyrillic-greek' => array(
				'heading' => array(
					'source' => 'remote',
					'key'    => $noto['key'],
				),
				'text'    => array(
					'source' => 'remote',
					'key'    => $noto['key'],
				),
			),
		);
		$built                      = Builder::font_families( $recipe['fonts'] );
		$stacks                     = array_column( $built['families'], 'fontFamily', 'slug' );
		$this->assertStringContainsString( '"Beiruti"', $stacks['lora'], 'Lora has no Greek' );
		$this->assertSame( '"Manrope", sans-serif', $stacks['manrope'], 'Manrope has Cyrillic and Greek' );
	}

	public function test_long_stacks_pass_the_import_checks(): void {
		$notes    = array();
		$stack    = '"Plus Jakarta Sans", "Noto Sans", "Beiruti", "Noto Sans Hebrew", "Noto Sans Devanagari", "Noto Sans Thai", "Noto Sans Bengali", "Noto Sans Tamil", "Noto Sans Gujarati", "Noto Sans Gurmukhi", "Noto Sans Georgian", sans-serif';
		$families = Sanitizer::clean_families(
			array(
				array(
					'name'       => 'Plus Jakarta Sans',
					'slug'       => 'plus-jakarta-sans',
					'fontFamily' => $stack,
				),
			),
			null,
			$notes
		);
		$this->assertSame( $stack, $families[0]['fontFamily'] );
	}

	public function test_preview_merges_ipsum_and_the_style(): void {
		$recipe = $this->recipe( 'harbour' );
		$recipe['settings']['color']['palette'][0]['color'] = '#fafafa';
		$preview = Builder::preview( $this->parsed( $recipe ) );
		$this->assertIsArray( $preview );
		$this->assertStringContainsString( '--wp--preset--color--base: #fafafa', $preview['css'] );
		$this->assertStringContainsString( '--wp--preset--font-family--roboto-slab', $preview['css'] );
		$this->assertStringContainsString( 'max-width: var(--wp--style--global--content-size)', $preview['css'] );
		$this->assertStringContainsString( '/assets/fonts/roboto-slab/roboto-slab-var-normal.woff2', $preview['fonts'] );
		$this->assertTrue( Contrast::passes( $preview['checks'] ) );
	}

	public function test_custom_css_and_remote_fonts_in_a_recipe_are_left_out(): void {
		$recipe                  = $this->recipe( 'harbour' );
		$recipe['styles']['css'] = 'body{background:url(https://evil.example/x.png)}';
		$recipe['settings']['typography']['fontFamilies'] = array(
			array(
				'name'       => 'Evil',
				'slug'       => 'evil',
				'fontFamily' => 'Evil',
				'fontFace'   => array(
					array(
						'fontFamily' => 'Evil',
						'src'        => array( 'https://evil.example/evil.woff2' ),
					),
				),
			),
		);
		$recipe['settings']['blocks']                     = array( 'core/paragraph' => array( 'color' => array( 'text' => true ) ) );
		$built = Builder::clean( $this->parsed( $recipe ) );
		$this->assertArrayNotHasKey( 'css', $built['clean']['data']['styles'] );
		$this->assertNotContains( 'evil', array_column( $built['clean']['data']['settings']['typography']['fontFamilies'], 'slug' ) );
		$this->assertArrayNotHasKey( 'blocks', $built['clean']['data']['settings'] );
	}

	public function test_adds_a_built_style_to_the_library_for_ipsum(): void {
		$built   = Builder::clean( $this->parsed( $this->recipe( 'fjord' ) ) );
		$preview = Importer::add_built( $built['clean'], $built['pending'], array() );
		$this->assertSame( 'E2E Fjord', $preview['title'] );
		$this->assertSame( array(), Library::all(), 'Dry run first' );
		$saved = Importer::add_built( $built['clean'], $built['pending'], array( 'dry_run' => false ) );
		$item  = Library::get( $saved['id'] );
		$this->assertSame( 'ipsum', $item['theme'] );
		$this->assertCount( count( $built['pending'] ), $item['files'] );
		$this->assertNotEmpty( Library::for_theme( 'ipsum', 'ipsum' ) );
	}

	public function test_rights_on_the_routes(): void {
		$recipe = (string) wp_json_encode( $this->recipe() );
		// A network administrator: everything.
		$this->assertSame( 200, $this->call( 'GET', '/mermaid/catalog' )->get_status() );
		$this->assertSame( 200, $this->call( 'POST', '/mermaid/preview', array( 'recipe' => $recipe ) )->get_status() );
		$this->assertSame( 200, $this->call( 'GET', '/mermaid/settings' )->get_status() );

		// A site administrator and a visitor: nothing while the switch is off.
		foreach ( array( self::$admin, 0 ) as $user ) {
			if ( self::$admin === $user ) {
				revoke_super_admin( self::$admin );
			}
			wp_set_current_user( $user );
			foreach ( array( array( 'GET', '/mermaid/catalog' ), array( 'POST', '/mermaid/preview' ), array( 'POST', '/mermaid/font' ), array( 'POST', '/mermaid/save' ), array( 'GET', '/mermaid/settings' ), array( 'POST', '/mermaid/settings' ) ) as [ $method, $route ] ) {
				$status = $this->call(
					$method,
					$route,
					array(
						'recipe' => $recipe,
						'family' => 'Lora',
						'public' => true,
					)
				)->get_status();
				$this->assertContains( $status, array( 401, 403 ), "$method $route for user $user" );
			}
		}

		// Public on, fonts off: catalog and preview, not fonts, never save or settings.
		update_option( Mermaid::OPTION, array( 'public' => true ) );
		$this->assertSame( 200, $this->call( 'GET', '/mermaid/catalog' )->get_status() );
		$this->assertFalse( $this->call( 'GET', '/mermaid/catalog' )->get_data()['remote'] );
		$this->assertSame( 200, $this->call( 'POST', '/mermaid/preview', array( 'recipe' => $recipe ) )->get_status() );
		$this->assertContains( $this->call( 'POST', '/mermaid/font', array( 'family' => 'Lora' ) )->get_status(), array( 401, 403 ) );
		$this->assertContains( $this->call( 'POST', '/mermaid/save', array( 'recipe' => $recipe ) )->get_status(), array( 401, 403 ) );

		// Both on: fonts too (mocked services), within the limit.
		update_option(
			Mermaid::OPTION,
			array(
				'public' => true,
				'remote' => true,
			)
		);
		$answer = $this->call(
			'POST',
			'/mermaid/font',
			array(
				'provider' => 'google',
				'family'   => 'Beiruti',
				'script'   => 'arabic',
			)
		);
		$this->assertSame( 200, $answer->get_status() );
		$this->assertStringContainsString( '/windrose-livery/cache/', $answer->get_data()['css'] );
		$this->assertStringNotContainsString( 'gstatic', $answer->get_data()['css'] );
		$this->assertSame(
			400,
			$this->call(
				'POST',
				'/mermaid/font',
				array(
					'family' => 'Lora',
					'script' => 'klingon',
				)
			)->get_status()
		);
	}

	public function test_settings_switch_per_site(): void {
		$answer = $this->call(
			'POST',
			'/mermaid/settings',
			array(
				'site'   => self::$ipsum_site,
				'public' => true,
				'remote' => true,
			)
		);
		$this->assertSame( 200, $answer->get_status() );
		$this->assertSame(
			array(
				'public' => true,
				'remote' => true,
			),
			Mermaid::settings( self::$ipsum_site )
		);
		$this->assertFalse( Mermaid::settings()['public'], 'The main site is unchanged' );
		$this->call(
			'POST',
			'/mermaid/settings',
			array(
				'site'   => self::$ipsum_site,
				'public' => false,
				'remote' => true,
			)
		);
		$this->assertFalse( Mermaid::settings( self::$ipsum_site )['remote'], 'Fonts need the public switch' );
		$this->assertSame(
			404,
			$this->call(
				'POST',
				'/mermaid/settings',
				array(
					'site'   => 99999,
					'public' => true,
				)
			)->get_status()
		);
	}

	public function test_download_for_visitors(): void {
		wp_set_current_user( 0 );
		$recipe = (string) wp_json_encode( $this->recipe( 'harbour' ) );
		$off    = Mermaid::prepare_download( $recipe );
		$this->assertWPError( $off );
		$this->assertSame( 403, $off->get_error_data()['status'] );

		update_option( Mermaid::OPTION, array( 'public' => true ) );
		$result = Mermaid::prepare_download( $recipe );
		$this->assertIsArray( $result );
		$this->assertSame( 'e2e-harbour', $result['slug'] );
		$this->temp[] = $result['zip'];
		$zip          = new ZipArchive();
		$zip->open( $result['zip'] );
		$names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$names[] = $zip->getNameIndex( $i );
		}
		$this->assertContains( 'e2e-harbour/e2e-harbour.json', $names );
		$this->assertContains( 'e2e-harbour/fonts.json', $names );
		$this->assertContains( 'e2e-harbour/fonts/roboto-slab/LICENSE.txt', $names );
		$this->assertContains( 'e2e-harbour/fonts/manrope/OFL.txt', $names );
		$zip->close();
		// The zip is a Livery package: its own import accepts it as is.
		$copy         = wp_tempnam( 'x.zip' );
		$this->temp[] = $copy;
		copy( $result['zip'], $copy );
		wp_set_current_user( self::$admin );
		$preview = Importer::import( $copy, 'e2e-harbour.zip' );
		$this->assertSame( array(), $preview['notes'] );
		$this->assertSame( 'ipsum', $preview['theme'] );
		wp_set_current_user( 0 );
		$this->assertSame( array(), Library::all(), 'Nothing stored' );

		// Too little contrast: refused for visitors (a library manager may still download).
		$bad = $this->recipe( 'harbour' );
		$bad['styles']['elements']['link']['color']['text'] = '#cccccc';
		$refused = Mermaid::prepare_download( (string) wp_json_encode( $bad ) );
		$this->assertSame( 422, $refused->get_error_data()['status'] );
		$this->assertNotEmpty( $refused->get_error_data()['checks'] );
		wp_set_current_user( self::$admin );
		$allowed = Mermaid::prepare_download( (string) wp_json_encode( $bad ) );
		$this->assertIsArray( $allowed );
		$this->temp[] = $allowed['zip'];
		wp_set_current_user( 0 );

		// Garbage in: an error, not a zip.
		$this->assertWPError( Mermaid::prepare_download( '{"title":' ) );
	}

	public function test_visitors_are_limited_per_address(): void {
		update_option( Mermaid::OPTION, array( 'public' => true ) );
		wp_set_current_user( 0 );
		for ( $i = 0; $i < Mermaid::LIMITS['download']; $i++ ) {
			$this->assertTrue( Rate_Limit::hit( 'download', Mermaid::LIMITS['download'] ) );
		}
		$limited = Mermaid::prepare_download( (string) wp_json_encode( $this->recipe() ) );
		$this->assertSame( 429, $limited->get_error_data()['status'] );
		$this->assertGreaterThan( 0, $limited->get_error_data()['retry_after'] );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
		$this->assertTrue( Rate_Limit::hit( 'download', Mermaid::LIMITS['download'] ), 'Another address has its own count' );
		// IPv6: one connection usually owns a /64, so the /64 is one visitor.
		$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::1';
		for ( $i = 0; $i < Mermaid::LIMITS['download']; $i++ ) {
			$this->assertTrue( Rate_Limit::hit( 'download', Mermaid::LIMITS['download'] ) );
		}
		$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:ffff:ffff:ffff:9';
		$this->assertFalse( Rate_Limit::hit( 'download', Mermaid::LIMITS['download'] ), 'Same /64, same count' );
		$_SERVER['REMOTE_ADDR'] = '2001:db8:1:3::1';
		$this->assertTrue( Rate_Limit::hit( 'download', Mermaid::LIMITS['download'] ), 'Another /64' );
		// IPv4 visitors in IPv6 form stay apart.
		$_SERVER['REMOTE_ADDR'] = '::ffff:203.0.113.8';
		for ( $i = 0; $i < Mermaid::LIMITS['download']; $i++ ) {
			Rate_Limit::hit( 'download', Mermaid::LIMITS['download'] );
		}
		$_SERVER['REMOTE_ADDR'] = '::ffff:203.0.113.9';
		$this->assertTrue( Rate_Limit::hit( 'download', Mermaid::LIMITS['download'] ), 'An IPv4 address in IPv6 form is its own visitor' );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Searches every stored value for the address.
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%203.0.113%' ) ), 'The address is never stored' );
	}

	public function test_block_renders_only_where_switched_on(): void {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( Mermaid::BLOCK ) );
		$this->assertSame( '', do_blocks( '<!-- wp:windrose/style-mermaid /-->' ) );
		update_option( Mermaid::OPTION, array( 'public' => true ) );
		$html = do_blocks( '<!-- wp:windrose/style-mermaid /-->' );
		$this->assertStringContainsString( 'data-windrose-livery-mermaid', $html );
		$this->assertTrue( wp_script_is( Mermaid::HANDLE, 'enqueued' ) );
	}
}
