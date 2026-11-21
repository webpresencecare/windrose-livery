<?php
/**
 * REST routes: rights, save from a site, delete, uninstall.
 *
 * @package Windrose\Livery
 */

use Windrose\Livery\Applier;
use Windrose\Livery\Fonts;
use Windrose\Livery\Importer;
use Windrose\Livery\Library;

/**
 * @covers \Windrose\Livery\Rest
 * @covers \Windrose\Livery\Importer
 * @covers \Windrose\Livery\Fonts
 */
class RestTest extends Livery_TestCase {

	/**
	 * Sends a REST request.
	 *
	 * @param array<string, mixed> $params Body parameters.
	 */
	private function call( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/windrose-livery/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_do_request( $request );
	}

	public function test_only_network_administrators_reach_the_routes(): void {
		$item = Library::get( Importer::import( $this->brand_zip(), 'h.zip', array( 'dry_run' => false ) )['id'] );
		revoke_super_admin( self::$admin );
		$this->assertTrue( current_user_can_for_site( self::$ipsum_site, 'edit_theme_options' ), 'A site administrator...' );
		foreach (
			array(
				array( 'GET', '/variations' ),
				array( 'GET', '/sites' ),
				array( 'POST', '/import' ),
				array( 'POST', '/from-site' ),
				array( 'POST', '/variations/' . $item['id'] . '/apply' ),
				array( 'DELETE', '/variations/' . $item['id'] ),
			) as [ $method, $route ]
		) {
			$params = '/from-site' === $route ? array(
				'site'  => self::$ipsum_site,
				'title' => 'X',
			) : array();
			$this->assertSame( 403, $this->call( $method, $route, $params )->get_status(), "$method $route" );
		}
		$this->assertNotNull( Library::get( $item['id'] ) );
	}

	public function test_lists_variations_and_block_themes(): void {
		Importer::import( $this->brand_zip(), 'h.zip', array( 'dry_run' => false ) );
		$data = $this->call( 'GET', '/variations' )->get_data();
		$this->assertSame( 'Harbour Brand', $data['items'][0]['title'] );
		$this->assertSame( 'Ipsum', $data['items'][0]['themeName'] );
		$this->assertSame( 2, $data['items'][0]['files'] );
		$this->assertContains( 'ipsum', array_column( $data['themes'], 'value' ) );
		$this->assertNotContains( 'twentyeleven', array_column( $data['themes'], 'value' ), 'Classic themes are not offered.' );
		$this->assertTrue( $data['canCss'] );
	}

	public function test_import_needs_an_uploaded_file(): void {
		$response = $this->call( 'POST', '/import' );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'windrose_livery_upload', $response->get_data()['code'] );
	}

	public function test_apply_needs_sites_and_an_existing_variation(): void {
		$item = Library::get( Importer::import( $this->brand_zip(), 'h.zip', array( 'dry_run' => false ) )['id'] );
		$this->assertSame( 400, $this->call( 'POST', '/variations/' . $item['id'] . '/apply' )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/variations/999999/apply', array( 'sites' => array( self::$ipsum_site ) ) )->get_status() );
		$ok = $this->call( 'POST', '/variations/' . $item['id'] . '/apply', array( 'sites' => array( self::$ipsum_site, self::$tt5_site ) ) );
		$this->assertSame( array( 'applied', 'skipped' ), array_column( $ok->get_data()['results'], 'status' ) );
	}

	public function test_save_a_sites_styles_copies_its_font_library_fonts(): void {
		// A font installed through the site's Font Library, used in its saved styles.
		switch_to_blog( self::$ipsum_site );
		$dir = wp_get_font_dir();
		wp_mkdir_p( $dir['path'] );
		file_put_contents( $dir['path'] . '/inter.woff2', $this->woff2( 'inter' ) );
		$this->temp[] = $dir['path'] . '/inter.woff2';
		$post         = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), true );
		$content      = array(
			'version'                     => 3,
			'isGlobalStylesUserThemeJSON' => true,
			'settings'                    => array(
				'color'      => array(
					'palette' => array(
						'theme'  => array(
							array(
								'slug'  => 'base',
								'name'  => 'Paper',
								'color' => '#fafafa',
							),
						),
						'custom' => array(
							array(
								'slug'  => 'custom-red',
								'name'  => 'Red',
								'color' => '#c00000',
							),
						),
					),
				),
				'typography' => array(
					'fontFamilies' => array(
						'custom' => array(
							array(
								'name'       => 'Inter',
								'slug'       => 'inter',
								'fontFamily' => 'Inter, sans-serif',
								'fontFace'   => array(
									array(
										'fontFamily' => 'Inter',
										'fontWeight' => '400',
										'fontStyle'  => 'normal',
										'src'        => $dir['url'] . '/inter.woff2',
									),
								),
							),
						),
					),
				),
			),
			'styles'                      => array( 'typography' => array( 'fontFamily' => 'var:preset|font-family|inter' ) ),
		);
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $post['ID'],
					'post_content' => wp_json_encode( $content ),
				)
			)
		);
		restore_current_blog();
		WP_Theme_JSON_Resolver::clean_cached_data();

		$preview = $this->call(
			'POST',
			'/from-site',
			array(
				'site'  => self::$ipsum_site,
				'title' => 'Paper',
			)
		)->get_data();
		$this->assertSame( array( 'base', 'custom-red' ), array_column( $preview['palette'], 'slug' ), 'Theme and custom colors become one list.' );
		$this->assertSame( 'Inter', $preview['fonts'][0]['name'] );
		$this->assertSame( 1, $preview['files'] );
		$this->assertSame( array(), Library::all() );

		$saved = $this->call(
			'POST',
			'/from-site',
			array(
				'site'    => self::$ipsum_site,
				'title'   => 'Paper',
				'dry_run' => false,
			)
		);
		$this->assertSame( 200, $saved->get_status() );
		$item = Library::get( $saved->get_data()['id'] );
		$this->assertTrue( Fonts::exists( $item['files'][0]['stored'] ), 'Copied into the library’s folder.' );
		$this->assertSame( 'ipsum', $item['theme'] );

		$none = $this->call(
			'POST',
			'/from-site',
			array(
				'site'  => self::$tt5_site,
				'title' => 'Empty',
			)
		);
		$this->assertSame( 'windrose_livery_no_styles', $none->get_data()['code'] );
	}

	public function test_delete_keeps_font_files_a_site_still_uses(): void {
		$item = Library::get( Importer::import( $this->brand_zip(), 'h.zip', array( 'dry_run' => false ) )['id'] );
		Applier::apply( $item, array( self::$ipsum_site ) );
		$other = Library::get( Importer::import( $this->brand_zip( $this->brand( 'Unused' ), array( 'harbour/fonts/cabin/cabin-400-normal.woff2' => $this->woff2( 'only here' ) ) ), 'u.zip', array( 'dry_run' => false ) )['id'] );

		$kept = $this->call( 'DELETE', '/variations/' . $item['id'] )->get_data();
		$this->assertTrue( $kept['deleted'] );
		$this->assertSame( 0, $kept['filesDeleted'], 'The site uses the fonts; Unused shares Baloo and the licenses.' );
		$this->assertSame( 2, $kept['fontsKept'] );
		$this->assertNull( Library::get( $item['id'] ) );

		$gone = $this->call( 'DELETE', '/variations/' . $other['id'] )->get_data();
		$this->assertGreaterThan( 0, $gone['filesDeleted'] );
		$this->assertFalse( Fonts::exists( $other['files'][2]['stored'] ), 'Its own Cabin, used by nothing, is deleted.' );
		$this->assertTrue( Fonts::exists( $item['files'][0]['stored'] ), 'Baloo, used by the site, stays.' );
	}

	public function test_a_license_stays_while_its_font_stays(): void {
		$files  = array(
			array(
				'stored' => 'aaaaaaaaaaaaaaaa-a.woff2',
				'name'   => 'a.woff2',
				'family' => 'a',
				'kind'   => 'font',
			),
			array(
				'stored' => 'bbbbbbbbbbbbbbbb-OFL.txt',
				'name'   => 'OFL.txt',
				'family' => 'a',
				'kind'   => 'license',
			),
			array(
				'stored' => 'cccccccccccccccc-c.woff2',
				'name'   => 'c.woff2',
				'family' => 'c',
				'kind'   => 'font',
			),
			array(
				'stored' => 'dddddddddddddddd-OFL.txt',
				'name'   => 'OFL.txt',
				'family' => 'c',
				'kind'   => 'license',
			),
		);
		$unused = array( 'bbbbbbbbbbbbbbbb-OFL.txt', 'cccccccccccccccc-c.woff2', 'dddddddddddddddd-OFL.txt' );
		$this->assertSame( array( 'cccccccccccccccc-c.woff2', 'dddddddddddddddd-OFL.txt' ), Fonts::deletable( $files, $unused ) );
	}

	public function test_uninstall_removes_records_and_keeps_fonts(): void {
		$item = Library::get( Importer::import( $this->brand_zip(), 'h.zip', array( 'dry_run' => false ) )['id'] );
		Applier::apply( $item, array( self::$ipsum_site ) );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'windrose-livery/windrose-livery.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';
		Library::flush();

		$this->assertSame( array(), Library::all() );
		$this->assertFalse( get_blog_option( self::$ipsum_site, 'windrose_livery_applied' ) );
		$this->assertTrue( Fonts::exists( $item['files'][0]['stored'] ) );
		$this->assertNotEmpty( $this->site_styles( self::$ipsum_site )['settings'] ?? array(), 'The site keeps its styles.' );
	}
}
