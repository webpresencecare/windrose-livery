<?php
/**
 * Font packs: checks of a pack zip, install (upload and from GitHub, HTTP answered by a stand-in), remove, styles made
 * with pack fonts (stacks, sizes, language fonts and rules), rights on the routes.
 *
 * @package Windrose\Livery
 */

use Windrose\Livery\Builder;
use Windrose\Livery\Catalog;
use Windrose\Livery\Fonts;
use Windrose\Livery\Importer;
use Windrose\Livery\Languages;
use Windrose\Livery\Library;
use Windrose\Livery\Packs;

/**
 * @covers \Windrose\Livery\Packs
 * @covers \Windrose\Livery\Builder
 */
class PacksTest extends Livery_TestCase {

	/**
	 * Requests made (addresses).
	 *
	 * @var list<string>
	 */
	private array $requests = array();

	/**
	 * Release asset served by the stand-in (path), or empty.
	 *
	 * @var string
	 */
	private string $asset = '';

	public function set_up(): void {
		parent::set_up();
		$this->requests = array();
		$this->asset    = '';
		add_filter( 'pre_http_request', array( $this, 'github' ), 5, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'github' ), 5 );
		Packs::remove_all();
		$dir = Packs::dir()['path'];
		if ( is_dir( $dir ) ) {
			Fonts::filesystem()->rmdir( $dir, true );
		}
		parent::tear_down();
	}

	/**
	 * GitHub's API for one release (v<this version>) with one pack asset; anything else fails the test.
	 *
	 * @param mixed  $pre  Earlier answer.
	 * @param array  $args Arguments.
	 * @param string $url  Address.
	 * @return array<string, mixed>
	 */
	public function github( $pre, $args, $url ): array {
		$this->requests[] = $url;
		$answer           = static fn( int $code, string $body, array $headers = array() ): array => array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => $args['filename'] ?? null,
		);
		$tag              = 'v' . WINDROSE_LIVERY_VERSION;
		if ( 'https://api.github.com/repos/webpresencecare/windrose-livery/releases/tags/' . $tag === $url ) {
			if ( '' === $this->asset ) {
				return $answer( 404, '{"message":"Not Found"}' );
			}
			$name = 'windrose-livery-fonts-arabic.zip';
			return $answer(
				200,
				(string) wp_json_encode(
					array(
						'tag_name' => $tag,
						'draft'    => false,
						'assets'   => array(
							array(
								'url'                  => 'https://api.github.com/repos/webpresencecare/windrose-livery/releases/assets/77',
								'name'                 => $name,
								'state'                => 'uploaded',
								'size'                 => filesize( $this->asset ),
								'digest'               => 'sha256:' . hash_file( 'sha256', $this->asset ),
								'browser_download_url' => 'https://github.com/webpresencecare/windrose-livery/releases/download/' . $tag . '/' . $name,
							),
						),
					)
				)
			);
		}
		if ( 'https://api.github.com/repos/webpresencecare/windrose-livery/releases/assets/77' === $url ) {
			return $answer( 302, '', array( 'location' => 'https://release-assets.githubusercontent.com/github-production-release-asset/77?sig=x' ) );
		}
		if ( str_starts_with( $url, 'https://release-assets.githubusercontent.com/' ) ) {
			copy( $this->asset, $args['filename'] );
			return $answer( 200, '' );
		}
		$this->fail( 'Unexpected request: ' . $url );
	}

	/**
	 * A pack index entry for a family.
	 *
	 * @return array<string, mixed>
	 */
	private function family( string $name, string $size = '' ): array {
		$slug = sanitize_title( $name );
		return array(
			'name'       => $name,
			'slug'       => $slug,
			'generic'    => 'sans-serif',
			'sizeAdjust' => $size,
			'license'    => 'OFL.txt',
			'faces'      => array(
				array(
					'file'         => $slug . '-var-normal.woff2',
					'fontWeight'   => '200 900',
					'fontStyle'    => 'normal',
					'unicodeRange' => 'U+0600-06FF, U+FB50-FDFF',
				),
			),
		);
	}

	/**
	 * A pack zip.
	 *
	 * @param list<array<string, mixed>> $families Families.
	 * @param array<string, mixed>       $change   Changes to the index.
	 * @param array<string, string|null> $files    Extra or replaced entries (null removes).
	 */
	private function pack( array $families = array(), array $change = array(), array $files = array(), string $script = 'arabic' ): string {
		$families = array() === $families ? array( $this->family( 'Beiruti', '120%' ), $this->family( 'Vazirmatn', '120%' ) ) : $families;
		$root     = 'windrose-livery-fonts-' . $script;
		$index    = array_merge(
			array(
				'format'   => 1,
				'script'   => $script,
				'livery'   => WINDROSE_LIVERY_VERSION,
				'source'   => 'https://github.com/google/fonts/tree/abc',
				'families' => $families,
			),
			$change
		);
		$entries  = array( $root . '/pack.json' => (string) wp_json_encode( $index ) );
		foreach ( $families as $family ) {
			$entries[ $root . '/' . $family['slug'] . '/OFL.txt' ] = 'SIL Open Font License, Version 1.1 (' . $family['name'] . ')';
			foreach ( $family['faces'] as $face ) {
				$entries[ $root . '/' . $family['slug'] . '/' . $face['file'] ] = $this->woff2( $family['name'] . $face['file'] );
			}
		}
		foreach ( $files as $name => $content ) {
			if ( null === $content ) {
				unset( $entries[ $name ] );
			} else {
				$entries[ $name ] = $content;
			}
		}
		return $this->zip( $entries );
	}

	/** The release rebuilds the packs and refuses a summary of another version: catch a missed bump before the tag. */
	public function test_the_pack_summary_names_this_version(): void {
		$summary = json_decode( (string) file_get_contents( WINDROSE_LIVERY_DIR . '/assets/mermaid/packs.json' ), true );
		$this->assertIsArray( $summary );
		$this->assertSame( WINDROSE_LIVERY_VERSION, $summary['version'] ?? null, 'Bump "version" in assets/mermaid/packs.json with the plugin.' );
	}

	public function test_every_script_with_pairs_has_a_pack_of_offered_families(): void {
		$available = Packs::available();
		foreach ( Catalog::scripts() as $script ) {
			if ( ! empty( $script['system'] ) ) {
				$this->assertArrayNotHasKey( $script['slug'], $available );
				continue;
			}
			$this->assertArrayHasKey( $script['slug'], $available, $script['slug'] );
			$this->assertSame( Packs::allowed_names( $script['slug'] ), array_column( $available[ $script['slug'] ]['families'], 'name' ) );
			$this->assertSame( 'windrose-livery-fonts-' . $script['slug'] . '.zip', $available[ $script['slug'] ]['file'] );
		}
		$this->assertContains( 'Vazirmatn', Packs::allowed_names( 'arabic' ) );
		$this->assertContains( 'Noto Nastaliq Urdu', Packs::allowed_names( 'arabic' ) );
		$this->assertNull( Packs::allowed_names( 'japanese' ), 'System fonts' );
		$this->assertNull( Packs::allowed_names( 'klingon' ) );
	}

	public function test_installs_an_uploaded_pack_on_the_library_site(): void {
		switch_to_blog( self::$ipsum_site );
		$pack = Packs::install_file( $this->pack() );
		restore_current_blog();
		$this->assertIsArray( $pack );
		$this->assertSame( 'arabic', $pack['script'] );
		$this->assertSame( array( 'beiruti', 'vazirmatn' ), array_column( $pack['families'], 'slug' ) );
		$this->assertArrayHasKey( 'arabic', get_blog_option( get_main_site_id(), Packs::OPTION ), 'Index on the library site' );
		$path = Packs::path( 'arabic', 'beiruti', 'beiruti-var-normal.woff2' );
		$this->assertFileExists( $path );
		$this->assertStringStartsWith( Packs::dir()['path'] . '/arabic/beiruti/', $path );
		$this->assertFileExists( Packs::path( 'arabic', 'beiruti', 'OFL.txt' ) );
		$this->assertStringEndsWith( '/windrose-livery/packs/arabic/beiruti/beiruti-var-normal.woff2', Packs::url( 'arabic', 'beiruti', 'beiruti-var-normal.woff2' ) );
		$this->assertSame( '', Packs::path( 'arabic', 'beiruti', '../../fonts/x.woff2' ), 'Only files the index names' );
		$this->assertSame( '', Packs::path( 'arabic', 'cairo', 'cairo.woff2' ) );

		$browser = array_column( Packs::for_browser(), null, 'script' );
		$this->assertTrue( $browser['arabic']['installed'] );
		$this->assertFalse( $browser['hebrew']['installed'] );
		$this->assertStringContainsString( 'unicode-range:U+0600-06FF', $browser['arabic']['css'] );
	}

	/**
	 * @dataProvider provide_bad_packs
	 */
	public function test_refuses_bad_packs( callable $make, string $why ): void {
		$result = Packs::install_file( $make( $this ) );
		$this->assertWPError( $result, $why );
		$this->assertSame( array(), Packs::installed(), $why );
		$this->assertDirectoryDoesNotExist( Packs::dir()['path'] . '/arabic', $why );
	}

	public static function provide_bad_packs(): array {
		$root = 'windrose-livery-fonts-arabic';
		return array(
			'unknown script'           => array( static fn( $t ) => $t->call_pack( array(), array(), array(), 'klingon' ), 'klingon' ),
			'system fonts script'      => array( static fn( $t ) => $t->call_pack( array(), array(), array(), 'japanese' ), 'japanese' ),
			'other format'             => array( static fn( $t ) => $t->call_pack( array(), array( 'format' => 2 ) ), 'format' ),
			'script and folder differ' => array( static fn( $t ) => $t->call_pack( array(), array( 'script' => 'hebrew' ) ), 'folder' ),
			'family not offered'       => array( static fn( $t ) => $t->call_pack( array( $t->call_family( 'Comic Neue' ) ) ), 'family' ),
			'slug not from the name'   => array( static fn( $t ) => $t->call_pack( array( array_merge( $t->call_family( 'Beiruti' ), array( 'slug' => '../fonts' ) ) ) ), 'slug' ),
			'license missing'          => array( static fn( $t ) => $t->call_pack( array(), array(), array( $root . '/beiruti/OFL.txt' => null ) ), 'license' ),
			'font file missing'        => array( static fn( $t ) => $t->call_pack( array(), array(), array( $root . '/beiruti/beiruti-var-normal.woff2' => null ) ), 'font' ),
			'not a font'               => array( static fn( $t ) => $t->call_pack( array(), array(), array( $root . '/beiruti/beiruti-var-normal.woff2' => '<?php echo 1;' ) ), 'bytes' ),
			'file name with a folder'  => array(
				static function ( $t ) {
					$family                     = $t->call_family( 'Beiruti' );
					$family['faces'][0]['file'] = '../x.woff2';
					return $t->call_pack( array( $family ) );
				},
				'path',
			),
			'unsafe unicode range'     => array(
				static function ( $t ) {
					$family                             = $t->call_family( 'Beiruti' );
					$family['faces'][0]['unicodeRange'] = 'U+0600;} body{color:red';
					return $t->call_pack( array( $family ) );
				},
				'range',
			),
			'no pack.json'             => array( static fn( $t ) => $t->call_pack( array(), array(), array( $root . '/pack.json' => null ) ), 'index' ),
			'two packs in one zip'     => array( static fn( $t ) => $t->call_pack( array(), array(), array( 'windrose-livery-fonts-hebrew/pack.json' => '{}' ) ), 'two' ),
		);
	}

	/**
	 * The data provider's way to build packs.
	 *
	 * @param list<array<string, mixed>> $families Families.
	 * @param array<string, mixed>       $change   Changes.
	 * @param array<string, string|null> $files    Entries.
	 */
	public function call_pack( array $families = array(), array $change = array(), array $files = array(), string $script = 'arabic' ): string {
		return $this->pack( $families, $change, $files, $script );
	}

	/**
	 * The data provider's way to build a family.
	 *
	 * @return array<string, mixed>
	 */
	public function call_family( string $name ): array {
		return $this->family( $name );
	}

	public function test_a_pack_for_another_script_is_refused_when_one_is_expected(): void {
		$this->assertSame( 'windrose_livery_pack_script', Packs::install_file( $this->pack(), 'hebrew' )->get_error_code() );
	}

	public function test_installs_from_this_versions_github_release_and_checks_the_checksum(): void {
		$missing = Packs::install_from_github( 'arabic' );
		$this->assertWPError( $missing );
		$this->assertSame( 'windrose_common_asset_missing', $missing->get_error_code() );

		$this->asset = $this->pack();
		$pack        = Packs::install_from_github( 'arabic' );
		$this->assertIsArray( $pack );
		$this->assertArrayHasKey( 'arabic', Packs::installed() );
		$hosts = array_unique( array_map( static fn( string $u ): string => (string) wp_parse_url( $u, PHP_URL_HOST ), $this->requests ) );
		$this->assertSame( array( 'api.github.com', 'release-assets.githubusercontent.com' ), array_values( $hosts ) );
		$this->assertContains( 'https://api.github.com/repos/webpresencecare/windrose-livery/releases/tags/v' . WINDROSE_LIVERY_VERSION, $this->requests );

		// A pack named for one script never installs another.
		$this->assertSame( 'windrose_livery_pack_script', Packs::install_from_github( 'klingon' )->get_error_code() );
	}

	public function test_style_with_pack_fonts_sizes_and_a_language_font(): void {
		Packs::install_file( $this->pack() );
		$recipe = array(
			'title'       => 'Pack style',
			'description' => '',
			'settings'    => array(
				'custom' => array(
					Languages::KEY => array(
						'xx' => array(
							'heading' => 'evil',
							'text'    => 'evil',
						),
					),
				),
			),
			'styles'      => array(
				'typography' => array( 'fontFamily' => 'var:preset|font-family|inter' ),
				'elements'   => array( 'heading' => array( 'typography' => array( 'fontFamily' => 'var:preset|font-family|fraunces' ) ) ),
			),
			'fonts'       => array(
				'heading' => array(
					'source' => 'bundled',
					'slug'   => 'fraunces',
				),
				'text'    => array(
					'source' => 'bundled',
					'slug'   => 'inter',
				),
				'scripts' => array(
					'arabic' => array(
						'heading'   => array(
							'source' => 'pack',
							'pack'   => 'arabic',
							'slug'   => 'beiruti',
						),
						'text'      => array(
							'source' => 'pack',
							'pack'   => 'arabic',
							'slug'   => 'beiruti',
						),
						'size'      => 115,
						'languages' => array(
							'fa' => array(
								'source' => 'pack',
								'pack'   => 'arabic',
								'slug'   => 'vazirmatn',
							),
							'xx' => array(
								'source' => 'pack',
								'pack'   => 'arabic',
								'slug'   => 'vazirmatn',
							),
						),
					),
				),
			),
		);
		$parsed = Builder::parse( (string) wp_json_encode( $recipe ) );
		$built  = Builder::clean( $parsed );
		$this->assertIsArray( $built, is_wp_error( $built ) ? $built->get_error_message() : '' );
		$this->assertSame( array(), $built['clean']['notes'] );
		$settings = $built['clean']['data']['settings'];
		$stacks   = array_column( $settings['typography']['fontFamilies'], 'fontFamily', 'slug' );
		$faces    = array_column( $settings['typography']['fontFamilies'], 'fontFace', 'slug' );
		$this->assertSame( '"Inter", "Beiruti", sans-serif', $stacks['inter'] );
		$this->assertSame( '"Vazirmatn", sans-serif', $stacks['vazirmatn-lang-fa'], 'A family of its own, the language in its slug' );
		$this->assertStringNotContainsString( 'Vazirmatn', $stacks['manrope'], 'In no stack' );
		$this->assertSame( '115%', $faces['beiruti'][0]['sizeAdjust'] );
		$this->assertSame( '115%', $faces['vazirmatn-lang-fa'][0]['sizeAdjust'] );
		$this->assertSame( 'U+0600-06FF, U+FB50-FDFF', $faces['beiruti'][0]['unicodeRange'] );
		$this->assertArrayNotHasKey( 'sizeAdjust', $faces['inter'][0] );
		$this->assertSame(
			array(
				'fa' => array(
					'heading' => 'vazirmatn-lang-fa',
					'text'    => 'vazirmatn-lang-fa',
				),
			),
			$settings['custom'][ Languages::KEY ],
			'Rules come from the font choices only, and only for the script\'s languages'
		);
		$this->assertSame( $settings['custom'][ Languages::KEY ], Languages::from_families( $settings['typography']['fontFamilies'] ), 'The same rule from the slugs, which survive WordPress\'s filter' );
		$licenses = array_filter( $built['pending'], static fn( array $p ): bool => 'license' === $p['entry']['kind'] );
		$this->assertSame( array( 'beiruti', 'fraunces', 'inter', 'jetbrains-mono', 'manrope', 'vazirmatn-lang-fa' ), $this->sorted( array_column( array_column( $licenses, 'entry' ), 'family' ) ) );

		// Size 100 means no sizeAdjust; no size means the pack's measured one.
		$recipe['fonts']['scripts']['arabic']['size'] = 100;
		$faces                                        = array_column( Builder::font_families( Builder::parse( (string) wp_json_encode( $recipe ) )['fonts'] )['families'], 'fontFace', 'slug' );
		$this->assertArrayNotHasKey( 'sizeAdjust', $faces['beiruti'][0] );
		unset( $recipe['fonts']['scripts']['arabic']['size'] );
		$faces = array_column( Builder::font_families( Builder::parse( (string) wp_json_encode( $recipe ) )['fonts'] )['families'], 'fontFace', 'slug' );
		$this->assertSame( '120%', $faces['beiruti'][0]['sizeAdjust'] );

		// The preview carries the language rules.
		$preview = Builder::preview( $parsed );
		$this->assertIsArray( $preview );
		$this->assertStringContainsString( ':root:is(:lang(fa)){--wp--preset--font-family--fraunces:"Fraunces", "Vazirmatn", "Beiruti", serif;--wp--preset--font-family--inter:"Inter", "Vazirmatn", "Beiruti", sans-serif;', $preview['css'] );
		$this->assertStringContainsString( 'font-family:var(--wp--preset--font-family--inter);}', $preview['css'], 'Elements in Persian on another page' );
		$this->assertStringContainsString( 'size-adjust:115%', $preview['fonts'] );
		$this->assertStringContainsString( '/windrose-livery/packs/arabic/vazirmatn/vazirmatn-var-normal.woff2', $preview['fonts'] );

		// Added to the library, then the pack removed: the style keeps its own copies.
		$saved = Importer::add_built( $built['clean'], $built['pending'], array( 'dry_run' => false ) );
		$this->assertTrue( Packs::remove( 'arabic' ) );
		$this->assertDirectoryDoesNotExist( Packs::dir()['path'] . '/arabic' );
		foreach ( Library::get( $saved['id'] )['files'] as $file ) {
			$this->assertTrue( Fonts::exists( $file['stored'] ), $file['name'] );
		}
		$this->assertFalse( Packs::remove( 'arabic' ) );

		// A recipe naming a pack that is not installed.
		$this->assertSame( 'windrose_livery_pack_missing', Builder::clean( $parsed )->get_error_code() );
	}

	/**
	 * Sorted unique values.
	 *
	 * @param list<string> $values Values.
	 * @return list<string>
	 */
	private function sorted( array $values ): array {
		$values = array_values( array_unique( $values ) );
		sort( $values );
		return $values;
	}

	public function test_rights_on_the_pack_routes(): void {
		Packs::install_file( $this->pack() );
		$call = static function ( string $method, string $route ): int {
			return rest_do_request( new WP_REST_Request( $method, '/windrose-livery/v1' . $route ) )->get_status();
		};

		wp_set_current_user( 0 );
		$this->assertSame( 401, $call( 'GET', '/mermaid/packs' ) );
		$this->assertSame( 401, $call( 'POST', '/mermaid/packs/arabic' ) );
		$this->assertSame( 401, $call( 'DELETE', '/mermaid/packs/arabic' ) );
		$this->assertSame( 401, $call( 'POST', '/mermaid/packs' ) );

		revoke_super_admin( self::$admin );
		wp_set_current_user( self::$admin );
		$this->assertSame( 403, $call( 'GET', '/mermaid/packs' ), 'A site administrator does not manage the network library' );
		$this->assertSame( 403, $call( 'DELETE', '/mermaid/packs/arabic' ) );
		$this->assertArrayHasKey( 'arabic', Packs::installed() );

		grant_super_admin( self::$admin );
		$this->assertSame( 200, $call( 'GET', '/mermaid/packs' ) );
		$this->assertSame( 400, $call( 'POST', '/mermaid/packs' ), 'No file' );
		$this->assertSame( 200, $call( 'DELETE', '/mermaid/packs/arabic' ) );
		$this->assertSame( 404, $call( 'DELETE', '/mermaid/packs/arabic' ) );
		$this->assertSame( array(), Packs::installed() );

		// Visitors of a public Style Mermaid see installed packs in the catalog, without managing them.
		Packs::install_file( $this->pack() );
		update_option( 'windrose_livery_mermaid', array( 'public' => true ) );
		wp_set_current_user( 0 );
		$catalog = rest_do_request( new WP_REST_Request( 'GET', '/windrose-livery/v1/mermaid/catalog' ) );
		$this->assertSame( 200, $catalog->get_status() );
		$packs = array_column( $catalog->get_data()['packs'], null, 'script' );
		$this->assertTrue( $packs['arabic']['installed'] );
		$this->assertSame( 'private, no-cache', $catalog->get_headers()['Cache-Control'] );
	}
}
