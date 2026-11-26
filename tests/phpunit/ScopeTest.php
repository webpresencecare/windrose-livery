<?php
/**
 * Site administrators and their sites' own styles: the network switch, rights checked on the site, fonts in
 * the site's own Font Library, copies into the shared library.
 *
 * @package Windrose\Livery
 */

use Windrose\Livery\Editor;
use Windrose\Livery\Fonts;
use Windrose\Livery\Importer;
use Windrose\Livery\Library;
use Windrose\Livery\Network;
use Windrose\Livery\Schemes;
use Windrose\Livery\Tracker;

/**
 * @covers \Windrose\Livery\Library
 * @covers \Windrose\Livery\Network
 * @covers \Windrose\Livery\Rest
 * @covers \Windrose\Livery\Importer
 */
class ScopeTest extends Livery_TestCase {

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

	/**
	 * Imports into a scope, after checking the current user reaches that library's routes (REST uploads need a real
	 * upload, so the import itself runs the route's callback code directly).
	 *
	 * @return array<string, mixed>
	 */
	private function import_as( int $scope, string $zip ): array {
		$this->assertSame( 200, $this->call( 'GET', '/variations', array( 'scope' => $scope ) )->get_status() );
		$saved = Library::with_scope( $scope, static fn() => Importer::import( $zip, 'h.zip', array( 'dry_run' => false ) ) );
		$this->assertIsArray( $saved );
		return $saved;
	}

	private function site_admin(): void {
		revoke_super_admin( self::$admin );
		$this->assertTrue( current_user_can_for_site( self::$ipsum_site, 'edit_theme_options' ) );
	}

	public function test_the_switch_is_off_by_default_and_only_network_administrators_change_it(): void {
		$this->assertFalse( Network::site_admins() );
		$this->assertSame( array( 'siteAdmins' => false ), $this->call( 'GET', '/network' )->get_data() );
		$this->site_admin();
		$this->assertSame( 403, $this->call( 'POST', '/network', array( 'site_admins' => true ) )->get_status() );
		$this->assertSame( 403, $this->call( 'GET', '/variations', array( 'scope' => self::$ipsum_site ) )->get_status(), 'Off: no screen of their own.' );
		$this->assertFalse( Library::can_manage_site( self::$ipsum_site ) );
		grant_super_admin( self::$admin );
		$this->assertSame( array( 'siteAdmins' => true ), $this->call( 'POST', '/network', array( 'site_admins' => true ) )->get_data() );
	}

	public function test_a_site_administrator_keeps_styles_for_their_site_only(): void {
		update_site_option( Network::OPTION, array( 'site_admins' => true ) );
		$this->site_admin();
		$this->assertTrue( Library::can_manage_site( self::$ipsum_site ) );
		$this->assertSame( 403, $this->call( 'GET', '/variations' )->get_status(), 'The shared library stays the network’s.' );
		$this->assertSame( 403, $this->call( 'GET', '/variations', array( 'scope' => 999 ) )->get_status(), 'An unknown site means the shared library.' );

		$id = $this->import_as( self::$ipsum_site, $this->brand_zip() )['id'];

		$this->assertSame( 'windrose_livery', get_blog_post( self::$ipsum_site, $id )->post_type, 'Stored on the site.' );
		switch_to_blog( self::$ipsum_site );
		$this->assertSame( '1', (string) get_post_meta( $id, Library::META_OWN, true ), 'Marked as the site’s own.' );
		restore_current_blog();
		$this->assertSame( array(), Library::all(), 'The shared library is unchanged.' );

		$own = Library::with_scope( self::$ipsum_site, static fn(): array => Library::all() );
		$this->assertCount( 1, $own );
		$this->assertSame( self::$ipsum_site, $own[0]['scope'] );
		$dir = Library::with_scope( self::$ipsum_site, static fn(): array => Fonts::dir() );
		$this->assertStringEndsWith( '/sites/' . self::$ipsum_site . '/fonts', $dir['path'], 'The site’s own Font Library folder.' );
		$this->assertFileExists( $dir['path'] . '/' . $own[0]['files'][0]['stored'] );
		switch_to_blog( self::$ipsum_site );
		$this->assertCount( 2, get_posts( array( 'post_type' => 'wp_font_family' ) ), 'Registered in the site’s Font Library.' );
		$offered = Library::offered( self::$ipsum_site, 'ipsum', 'ipsum' );
		restore_current_blog();
		$this->assertSame( array( 'Harbour Brand' ), array_column( $offered, 'title' ) );
		$this->assertSame( array(), get_posts( array( 'post_type' => 'wp_font_family' ) ), 'Not in the main site’s.' );

		// Another site: not offered there, and not theirs to manage.
		$this->assertSame( array(), Library::offered( self::$tt5_site, 'ipsum', 'ipsum' ) );
		remove_user_from_blog( self::$admin, self::$tt5_site );
		$this->assertSame( 403, $this->call( 'GET', '/variations', array( 'scope' => self::$tt5_site ) )->get_status() );
		add_user_to_blog( self::$tt5_site, self::$admin, 'administrator' );
	}

	public function test_a_sites_own_style_applies_to_that_site_only_and_is_tracked_with_its_scope(): void {
		update_site_option( Network::OPTION, array( 'site_admins' => true ) );
		$this->site_admin();
		$id     = $this->import_as( self::$ipsum_site, $this->brand_zip() )['id'];
		$answer = $this->call(
			'POST',
			'/variations/' . $id . '/apply',
			array(
				'scope' => self::$ipsum_site,
				'sites' => array( self::$tt5_site ),
			)
		)->get_data();
		$this->assertSame( array( self::$ipsum_site ), array_column( $answer['results'], 'site' ), 'Asked for another site: applied to its own site only.' );
		$this->assertSame( 'applied', $answer['results'][0]['status'] );
		switch_to_blog( self::$ipsum_site );
		$record = Tracker::get();
		restore_current_blog();
		$this->assertSame( self::$ipsum_site, $record['scope'] );
		$this->assertSame( $id, $record['id'] );
	}

	public function test_site_administrators_cannot_touch_font_packs_or_visitor_font_downloads(): void {
		update_site_option( Network::OPTION, array( 'site_admins' => true ) );
		$this->site_admin();
		$this->assertSame( 403, $this->call( 'POST', '/mermaid/packs/hebrew', array( 'scope' => self::$ipsum_site ) )->get_status() );
		$this->assertSame( 403, $this->call( 'DELETE', '/mermaid/packs/hebrew', array( 'scope' => self::$ipsum_site ) )->get_status() );
		$this->assertSame( 200, $this->call( 'GET', '/mermaid/packs', array( 'scope' => self::$ipsum_site ) )->get_status(), 'They see which packs are installed.' );

		$row = $this->call(
			'POST',
			'/mermaid/settings',
			array(
				'scope'  => self::$ipsum_site,
				'site'   => self::$ipsum_site,
				'public' => true,
				'remote' => true,
				'switch' => true,
				'follow' => true,
			)
		)->get_data();
		$this->assertTrue( $row['public'] );
		$this->assertFalse( $row['remote'], 'Fonts fetched by the network’s server: network administrators only.' );
		$this->assertTrue( $row['remoteLocked'] );
		$this->assertSame(
			array(
				'switch' => true,
				'follow' => true,
			),
			Schemes::settings( self::$ipsum_site )
		);
		$other = $this->call(
			'POST',
			'/mermaid/settings',
			array(
				'scope'  => self::$ipsum_site,
				'site'   => self::$tt5_site,
				'switch' => true,
			)
		);
		$this->assertSame( 403, $other->get_status(), 'Their own site’s switches only.' );
	}

	public function test_a_sites_own_library_takes_only_that_sites_styles(): void {
		// Sites' own libraries on; the network administrator passes every other rule: only "same site as the library"
		// refuses this.
		update_site_option( Network::OPTION, array( 'site_admins' => true ) );
		$params = array(
			'scope'   => self::$ipsum_site,
			'site'    => self::$tt5_site,
			'title'   => 'From another site',
			'dry_run' => true,
		);
		$this->assertSame( 403, $this->call( 'POST', '/from-site', $params )->get_status() );
		$params['site'] = self::$ipsum_site;
		$this->assertNotSame( 403, $this->call( 'POST', '/from-site', $params )->get_status() );
	}

	public function test_a_network_administrator_copies_a_sites_style_into_the_shared_library(): void {
		update_site_option( Network::OPTION, array( 'site_admins' => true ) );
		$this->site_admin();
		$id = $this->import_as( self::$ipsum_site, $this->brand_zip() )['id'];
		grant_super_admin( self::$admin );

		$listed = $this->call( 'GET', '/site-styles' )->get_data();
		$this->assertSame( array( 'Harbour Brand' ), array_column( $listed, 'title' ) );
		$this->assertSame( 'Ipsum site', $listed[0]['siteName'] );

		$dry = $this->call( 'POST', '/variations/' . $id . '/copy', array( 'scope' => self::$ipsum_site ) )->get_data();
		$this->assertNull( $dry['replaces'] );
		$this->assertSame( array(), Library::all(), 'Dry run first.' );

		$copy   = $this->call(
			'POST',
			'/variations/' . $id . '/copy',
			array(
				'scope'   => self::$ipsum_site,
				'dry_run' => false,
			)
		)->get_data();
		$shared = Library::get( $copy['id'] );
		$this->assertSame( 0, $shared['scope'] );
		$this->assertFileExists( Fonts::dir()['path'] . '/' . $shared['files'][0]['stored'], 'Its fonts copied into the main site’s Font Library.' );
		$this->assertCount( 1, Library::with_scope( self::$ipsum_site, static fn(): array => Library::all() ), 'The site keeps its own.' );

		$again = $this->call(
			'POST',
			'/variations/' . $id . '/copy',
			array(
				'scope'   => self::$ipsum_site,
				'dry_run' => false,
			)
		);
		$this->assertSame( 409, $again->get_status(), 'Same title: replace, or not.' );
	}

	public function test_turned_off_again_their_styles_stay_in_their_site_editor(): void {
		update_site_option( Network::OPTION, array( 'site_admins' => true ) );
		$this->site_admin();
		$this->import_as( self::$ipsum_site, $this->brand_zip() );
		update_site_option( Network::OPTION, array( 'site_admins' => false ) );
		Library::flush();
		$this->assertFalse( Library::can_manage_site( self::$ipsum_site ) );
		switch_to_blog( self::$ipsum_site );
		$request = new WP_REST_Request( 'GET', '/wp/v2/global-styles/themes/ipsum/variations' );
		$titles  = array_column( rest_do_request( $request )->get_data(), 'title' );
		restore_current_blog();
		$this->assertContains( 'Harbour Brand', $titles );
	}

	public function test_the_site_editor_gets_where_a_variation_may_go(): void {
		update_site_option( Network::OPTION, array( 'site_admins' => true ) );
		switch_to_blog( self::$ipsum_site );
		set_current_screen( 'site-editor' );
		Editor::menu_item();
		$before = implode( '', (array) wp_scripts()->get_data( Editor::SCRIPT, 'before' ) );
		wp_dequeue_script( Editor::SCRIPT );
		wp_deregister_script( Editor::SCRIPT );
		set_current_screen( 'front' );
		restore_current_blog();
		$this->assertStringContainsString( '"shared":true', $before, 'A network administrator: both.' );
		$this->assertStringContainsString( '"own":true', $before );
	}
}
