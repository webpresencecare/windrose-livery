<?php
/**
 * Livery's fonts in WordPress's Font Library, and the warning before deleting one in use.
 *
 * @package Windrose\Livery
 */

use Windrose\Livery\Applier;
use Windrose\Livery\Font_Library;
use Windrose\Livery\Fonts;
use Windrose\Livery\Importer;
use Windrose\Livery\Library;

/**
 * @covers \Windrose\Livery\Font_Library
 * @covers \Windrose\Livery\Fonts
 * @covers \Windrose\Livery\Importer
 */
class FontLibraryTest extends Livery_TestCase {

	/**
	 * The main site's Font Library families: slug => post.
	 *
	 * @return array<string, WP_Post>
	 */
	private function families(): array {
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'   => 'wp_font_family',
				'post_status' => 'any',
				'numberposts' => -1,
			)
		) as $post ) {
			$out[ $post->post_name ] = $post;
		}
		return $out;
	}

	private function delete_family( int $id ): WP_REST_Response {
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/font-families/' . $id );
		$request->set_param( 'force', true );
		return rest_do_request( $request );
	}

	public function test_an_import_installs_its_fonts_in_the_main_sites_font_library(): void {
		$saved = Importer::import( $this->brand_zip(), 'h.zip', array( 'dry_run' => false ) );
		$item  = Library::get( $saved['id'] );

		$this->assertSame( wp_get_font_dir()['path'], Fonts::dir()['path'], 'The folder WordPress puts installed fonts in.' );
		$families = $this->families();
		$this->assertSame( array( 'baloo', 'cabin' ), array_keys( $families ) );
		$this->assertSame( 'Baloo', $families['baloo']->post_title );
		$this->assertSame( '1', get_post_meta( $families['baloo']->ID, Font_Library::META, true ) );

		$faces = get_children(
			array(
				'post_parent' => $families['baloo']->ID,
				'post_type'   => 'wp_font_face',
			)
		);
		$this->assertCount( 1, $faces );
		$face     = reset( $faces );
		$settings = json_decode( $face->post_content, true );
		$this->assertSame( $item['files'][0]['stored'], get_post_meta( $face->ID, '_wp_font_face_file', true ) );
		$this->assertSame( Fonts::dir()['url'] . '/' . $item['files'][0]['stored'], $settings['src'] );
		$this->assertSame( WP_Font_Utils::get_font_face_slug( $settings ), $face->post_title, 'Titled as WordPress titles faces.' );

		// WordPress's own route lists them like any installed font.
		$listed = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/font-families' ) )->get_data();
		$this->assertContains( 'baloo', array_column( array_column( $listed, 'font_family_settings' ), 'slug' ) );
	}

	public function test_an_installed_family_is_used_and_no_face_is_doubled(): void {
		$existing = wp_insert_post(
			array(
				'post_type'    => 'wp_font_family',
				'post_status'  => 'publish',
				'post_title'   => 'Baloo',
				'post_name'    => 'baloo',
				'post_content' => '{"fontFamily":"Baloo"}',
			)
		);
		Importer::import( $this->brand_zip(), 'h.zip', array( 'dry_run' => false ) );
		Importer::import(
			$this->brand_zip( $this->brand( 'Second' ) ),
			'h.zip',
			array( 'dry_run' => false )
		);
		$families = $this->families();
		$this->assertSame( $existing, $families['baloo']->ID );
		$this->assertSame( '', get_post_meta( $existing, Font_Library::META, true ), 'Not marked as Livery’s.' );
		$this->assertCount(
			1,
			get_children(
				array(
					'post_parent' => $existing,
					'post_type'   => 'wp_font_face',
				)
			),
			'Same file, same face: once.'
		);
	}

	public function test_deleting_the_variation_removes_its_fonts_from_the_font_library(): void {
		$saved = Importer::import( $this->brand_zip(), 'h.zip', array( 'dry_run' => false ) );
		$item  = Library::get( $saved['id'] );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'DELETE', '/windrose-livery/v1/variations/' . $item['id'] ) )->get_status() );
		$this->assertSame( array(), $this->families() );
		$this->assertFileDoesNotExist( Fonts::dir()['path'] . '/' . $item['files'][0]['stored'] );
	}

	public function test_deleting_a_font_in_use_is_refused_once_then_allowed(): void {
		$saved = Importer::import( $this->brand_zip(), 'h.zip', array( 'dry_run' => false ) );
		$item  = Library::get( $saved['id'] );
		$baloo = $this->families()['baloo']->ID;
		Applier::apply( $item, array( self::$ipsum_site ) );

		$first = $this->delete_family( $baloo );
		$this->assertSame( 409, $first->get_status() );
		$message = $first->as_error()->get_error_message();
		$this->assertStringStartsWith( ' ', $message, 'Core’s dialog puts it right after its own sentence.' );
		$this->assertStringContainsString( '“Harbour Brand”', $message );
		$this->assertStringContainsString( '1 site', $message );
		$this->assertStringContainsString( 'Delete it again within 10 minutes', $message );
		$this->assertNotNull( get_post( $baloo ) );

		$second = $this->delete_family( $baloo );
		$this->assertSame( 200, $second->get_status() );
		$this->assertNull( get_post( $baloo ) );
		$this->assertFileDoesNotExist( Fonts::dir()['path'] . '/' . $item['files'][0]['stored'], 'Core deletes the file: the warning said so.' );
	}

	public function test_an_unused_font_is_deleted_at_once_and_others_never_ask(): void {
		$family = wp_insert_post(
			array(
				'post_type'    => 'wp_font_family',
				'post_status'  => 'publish',
				'post_title'   => 'Plain',
				'post_name'    => 'plain',
				'post_content' => '{"fontFamily":"Plain"}',
			)
		);
		$this->assertSame( 200, $this->delete_family( $family )->get_status() );
		$this->assertSame(
			array(
				'variations' => array(),
				'sites'      => array(),
			),
			Font_Library::usage( array( 'abcdef0123456789-x.woff2' ) )
		);
	}

	public function test_the_warning_names_variations_and_sites(): void {
		$this->assertSame(
			'This font is used by the style variations “A” and “B”. Delete it again within 10 minutes to delete it anyway.',
			Font_Library::message(
				array(
					'variations' => array( 'A', 'B' ),
					'sites'      => array(),
				)
			)
		);
		$this->assertSame(
			'This font is used by 2 sites: One and Two. Delete it again within 10 minutes to delete it anyway.',
			Font_Library::message(
				array(
					'variations' => array(),
					'sites'      => array( 'One', 'Two' ),
				)
			)
		);
	}

	public function test_style_mermaid_offers_installed_fonts_but_not_livery_s_own(): void {
		Importer::import( $this->brand_zip(), 'h.zip', array( 'dry_run' => false ) );
		$this->assertSame( array(), Font_Library::installed(), 'Livery’s registered fonts are not offered twice.' );

		$dir = wp_get_font_dir()['path'];
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/lato.woff2', $this->woff2( 'lato' ) );
		$family = wp_insert_post(
			array(
				'post_type'    => 'wp_font_family',
				'post_status'  => 'publish',
				'post_title'   => 'Lato',
				'post_name'    => 'lato',
				'post_content' => '{"fontFamily":"Lato, serif"}',
			)
		);
		$face   = wp_insert_post(
			array(
				'post_type'    => 'wp_font_face',
				'post_status'  => 'publish',
				'post_parent'  => $family,
				'post_title'   => 'lato-400',
				'post_content' => wp_json_encode(
					array(
						'fontFamily' => 'Lato',
						'fontWeight' => '400',
						'fontStyle'  => 'normal',
						'src'        => wp_get_font_dir()['url'] . '/lato.woff2',
					)
				),
			)
		);
		add_post_meta( $face, '_wp_font_face_file', 'lato.woff2' );
		$installed = Font_Library::installed();
		$this->assertCount( 1, $installed );
		$this->assertSame( 'Lato', $installed[0]['name'] );
		$this->assertSame( 'serif', $installed[0]['generic'] );
		$this->assertSame( $dir . '/lato.woff2', Font_Library::face_path( $face ) );
		$this->assertSame( '', Font_Library::face_path( $family ), 'A family is not a face.' );
		$this->temp[] = $dir . '/lato.woff2';
	}
}
