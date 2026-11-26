<?php
/**
 * Livery on a single site (no multisite): the library is the site itself; administrators manage it; apply changes
 * this site only. Run with phpunit-single.xml.dist.
 *
 * @package Windrose\Livery
 */

use Windrose\Livery\Admin;
use Windrose\Livery\Applier;
use Windrose\Livery\Fonts;
use Windrose\Livery\Importer;
use Windrose\Livery\Library;
use Windrose\Livery\Mermaid;
use Windrose\Livery\Themes;

/**
 * @covers \Windrose\Livery\Library
 * @covers \Windrose\Livery\Applier
 * @covers \Windrose\Livery\Rest
 * @covers \Windrose\Livery\Admin
 * @covers \Windrose\Livery\Mermaid
 */
class SingleSiteTest extends WP_UnitTestCase {

	private static int $admin  = 0;
	private static int $editor = 0;

	/**
	 * Temporary files.
	 *
	 * @var list<string>
	 */
	private array $temp = array();

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$admin  = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor = $factory->user->create( array( 'role' => 'editor' ) );
	}

	public function set_up(): void {
		parent::set_up();
		$this->assertFalse( is_multisite() );
		wp_set_current_user( self::$admin );
		switch_theme( 'ipsum' );
		Library::flush();
		Fonts::flush();
		Themes::flush();
		WP_Theme_JSON_Resolver::clean_cached_data();
	}

	public function tear_down(): void {
		foreach ( $this->temp as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$dir = Fonts::dir()['path'];
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			if ( is_string( $file ) ) {
				unlink( $file );
			}
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir );
		}
		Library::flush();
		Fonts::flush();
		parent::tear_down();
	}

	private function package(): string {
		$path = wp_tempnam( 'single.zip' );
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString(
			'solo/solo.json',
			(string) wp_json_encode(
				array(
					'version'  => 3,
					'title'    => 'Solo',
					'settings' => array(
						'color'      => array(
							'palette' => array(
								array(
									'slug'  => 'base',
									'name'  => 'Paper',
									'color' => '#fafafa',
								),
							),
						),
						'typography' => array(
							'fontFamilies' => array(
								array(
									'name'       => 'Solo Sans',
									'slug'       => 'solo-sans',
									'fontFamily' => 'Solo Sans, sans-serif',
									'fontFace'   => array(
										array(
											'fontFamily' => 'Solo Sans',
											'src'        => array( 'file:./fonts/solo.woff2' ),
										),
									),
								),
							),
						),
					),
					'styles'   => array( 'color' => array( 'text' => 'var:preset|color|base' ) ),
				)
			)
		);
		$zip->addFromString( 'solo/fonts/solo.woff2', 'wOF2' . str_repeat( "\1", 40 ) );
		$zip->close();
		$this->temp[] = $path;
		return $path;
	}

	public function test_the_site_is_its_own_library_and_apply_changes_it(): void {
		$this->assertSame( get_current_blog_id(), Library::site_id() );
		$saved = Importer::import( $this->package(), 'solo.zip', array( 'dry_run' => false ) );
		$this->assertIsArray( $saved );
		$this->assertSame( 1, $saved['files'], 'A theme-style file:./ source is read from the package.' );
		$this->assertSame( wp_get_font_dir()['path'], Fonts::dir()['path'], 'The site’s Font Library folder.' );
		$this->assertCount( 1, get_posts( array( 'post_type' => 'wp_font_family' ) ), 'Registered in its Font Library.' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/global-styles/themes/ipsum/variations' ) );
		$titles   = array_column( (array) $response->get_data(), 'title' );
		$this->assertSame( 'Solo', end( $titles ) );

		$results = Applier::apply( Library::get( $saved['id'] ), array( 12345 ) );
		$this->assertCount( 1, $results, 'Site IDs are ignored: this site only.' );
		$this->assertSame( 'applied', $results[0]['status'], $results[0]['message'] );
		$sites = Applier::sites();
		$this->assertCount( 1, $sites );
		$this->assertSame( $saved['id'], $sites[0]['applied']['id'] );
	}

	public function test_administrators_manage_it_editors_do_not(): void {
		$this->assertTrue( Library::can_manage() );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/windrose-livery/v1/variations' ) )->get_status() );
		wp_set_current_user( self::$editor );
		$this->assertFalse( Library::can_manage() );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/windrose-livery/v1/variations' ) )->get_status() );
		$from_site = new WP_REST_Request( 'POST', '/windrose-livery/v1/from-site' );
		$from_site->set_param( 'title', 'Mine' );
		$this->assertSame( 403, rest_do_request( $from_site )->get_status() );
	}

	public function test_the_screen_is_under_appearance(): void {
		global $submenu;
		set_current_screen( 'dashboard' );
		Admin::menu();
		$this->assertContains( Admin::SLUG, array_column( $submenu['themes.php'] ?? array(), 2 ) );
		$this->assertStringContainsString( '/wp-admin/themes.php?page=windrose-livery', Admin::url() );
	}

	public function test_style_mermaid_on_a_single_site(): void {
		// The administrator manages the switches of the site itself.
		$request = new WP_REST_Request( 'POST', '/windrose-livery/v1/mermaid/settings' );
		$request->set_param( 'public', true );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame(
			array(
				'public' => true,
				'remote' => false,
			),
			Mermaid::settings()
		);
		$list = rest_do_request( new WP_REST_Request( 'GET', '/windrose-livery/v1/mermaid/settings' ) )->get_data();
		$this->assertCount( 1, $list );

		// An editor is a visitor here: the catalog (public is on), never the settings.
		wp_set_current_user( self::$editor );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/windrose-livery/v1/mermaid/catalog' ) )->get_status() );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/windrose-livery/v1/mermaid/settings' ) )->get_status() );

		// A visitor downloads the Ipsum ready style; nothing is stored.
		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.4';
		$style                  = \Windrose\Livery\Catalog::styles()[0];
		$recipe                 = array(
			'title'    => 'Single',
			'settings' => $style['settings'],
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
		$result                 = Mermaid::prepare_download( (string) wp_json_encode( $recipe ) );
		$this->assertIsArray( $result );
		$this->temp[] = $result['zip'];
		$this->assertSame( array(), Library::all() );
	}
}
