<?php
/**
 * Import of packages, dry run first.
 *
 * @package Windrose\Livery
 */

use Windrose\Livery\Fonts;
use Windrose\Livery\Importer;
use Windrose\Livery\Library;
use Windrose\Livery\Package;

/**
 * @covers \Windrose\Livery\Importer
 * @covers \Windrose\Livery\Package
 * @covers \Windrose\Livery\Library
 * @covers \Windrose\Livery\Themes
 */
class ImportTest extends Livery_TestCase {

	public function test_dry_run_previews_and_writes_nothing(): void {
		$preview = Importer::import( $this->brand_zip(), 'harbour.zip' );

		$this->assertIsArray( $preview );
		$this->assertSame( 'Harbour Brand', $preview['title'] );
		$this->assertSame( 'ipsum', $preview['theme'], 'Guessed from the color names base...' );
		$this->assertSame( array( 'base', 'contrast', 'accent-1' ), array_column( $preview['palette'], 'slug' ) );
		$this->assertSame( array( 'Baloo', 'Cabin' ), array_column( $preview['fonts'], 'name' ) );
		$this->assertSame( 4, $preview['files'], 'Two fonts and their two licenses.' );
		$this->assertSame( array(), $preview['notes'] );
		$this->assertNull( $preview['replaces'] );
		$this->assertSame( array(), Library::all() );
		$this->assertDirectoryDoesNotExist( Fonts::dir()['path'] );
	}

	public function test_import_stores_the_variation_on_the_main_site_and_its_files_once(): void {
		switch_to_blog( self::$ipsum_site );
		$saved = Importer::import( $this->brand_zip(), 'harbour.zip', array( 'dry_run' => false ) );
		restore_current_blog();

		$this->assertIsArray( $saved );
		$this->assertSame( 1, $saved['version'] );
		$item = Library::get( $saved['id'] );
		$this->assertNotNull( $item );
		$this->assertSame( 'ipsum', $item['theme'] );
		$this->assertSame( 'windrose_livery', get_blog_post( 1, $saved['id'] )->post_type, 'Stored on the main site, not the site of the request.' );
		$this->assertSame( 'private', get_blog_post( 1, $saved['id'] )->post_status );

		$src = $item['data']['settings']['typography']['fontFamilies'][0]['fontFace'][0]['src'][0];
		$this->assertMatchesRegularExpression( '/^windrose-livery:[a-f0-9]{16}-baloo-400-normal\.woff2$/', $src );
		$this->assertCount( 4, $item['files'] );
		$this->assertSame( array( 'font', 'license', 'font', 'license' ), array_column( $item['files'], 'kind' ) );
		foreach ( $item['files'] as $file ) {
			$this->assertFileExists( Fonts::dir()['path'] . '/' . $file['stored'] );
		}
		$this->assertSame( $this->main_fonts()['path'], Fonts::dir()['path'] );
	}

	public function test_same_title_needs_replace_and_makes_a_new_version(): void {
		$first = Importer::import( $this->brand_zip(), 'harbour.zip', array( 'dry_run' => false ) );

		$again = Importer::import( $this->brand_zip(), 'harbour.zip' );
		$this->assertSame( $first['id'], $again['replaces']['id'] );
		$this->assertSame( 1, $again['replaces']['version'] );

		$refused = Importer::import( $this->brand_zip(), 'harbour.zip', array( 'dry_run' => false ) );
		$this->assertWPError( $refused );
		$this->assertSame( 'windrose_livery_exists', $refused->get_error_code() );

		// Version 2: Cabin replaced by another file; the old Cabin file is no longer used by anything.
		$old_cabin = Library::get( $first['id'] )['files'][2]['stored'];
		$zip       = $this->brand_zip( array(), array( 'harbour/fonts/cabin/cabin-400-normal.woff2' => $this->woff2( 'cabin v2' ) ) );
		$second    = Importer::import(
			$zip,
			'harbour.zip',
			array(
				'dry_run' => false,
				'replace' => true,
			)
		);

		$this->assertSame( $first['id'], $second['id'] );
		$this->assertSame( 2, $second['version'] );
		$this->assertCount( 1, Library::all() );
		$this->assertFalse( Fonts::exists( $old_cabin ), 'The replaced font file is deleted.' );
		$this->assertTrue( Fonts::exists( Library::get( $first['id'] )['files'][0]['stored'] ), 'Baloo is unchanged and kept.' );
	}

	public function test_same_title_for_another_theme_is_a_separate_variation(): void {
		Importer::import( $this->brand_zip(), 'harbour.zip', array( 'dry_run' => false ) );
		$other = Importer::import(
			$this->brand_zip(),
			'harbour.zip',
			array(
				'dry_run' => false,
				'theme'   => 'twentytwentyfive',
			)
		);
		$this->assertIsArray( $other );
		$this->assertCount( 2, Library::all() );
	}

	public function test_fonts_outside_the_folder_or_with_wrong_content_are_left_out(): void {
		$fonts = $this->brand_fonts();
		$fonts['fontFamilies'][0]['fontFace'][0]['src'] = array( '../../evil.woff2' );
		$fonts['fontFamilies'][1]['fontFace'][0]['src'] = array( 'fonts/cabin/fake.woff2' );
		$zip     = $this->brand_zip(
			array(),
			array(
				'evil.woff2'                     => $this->woff2( 'evil' ),
				'harbour/fonts/cabin/fake.woff2' => '<?php echo "not a font";',
			),
			$fonts
		);
		$preview = Importer::import( $zip, 'harbour.zip' );

		$this->assertSame( 0, $preview['files'] );
		$notes = implode( "\n", $preview['notes'] );
		$this->assertStringContainsString( 'not found in the package', $notes );
		$this->assertStringContainsString( 'fake.woff2 does not contain the font its name says', $notes );
	}

	public function test_entries_with_dot_dot_in_their_name_are_never_read(): void {
		$fonts = $this->brand_fonts();
		$fonts['fontFamilies'][0]['fontFace'][0]['src'] = array( '../outside.woff2' );
		$zip     = $this->brand_zip( array(), array( 'harbour/../outside.woff2' => $this->woff2( 'outside' ) ), $fonts );
		$preview = Importer::import( $zip, 'harbour.zip' );
		$this->assertSame( 2, $preview['files'], 'Only Cabin and its license.' );
		$this->assertStringContainsString( 'not found in the package', implode( "\n", $preview['notes'] ) );
	}

	public function test_packages_that_are_not_one_variation_are_refused(): void {
		$two = $this->zip(
			array(
				'a.json' => (string) wp_json_encode( $this->brand( 'A' ) ),
				'b.json' => (string) wp_json_encode( $this->brand( 'B' ) ),
			)
		);
		$this->assertSame( 'windrose_livery_zip_variation', Importer::import( $two, 'two.zip' )->get_error_code() );

		$none = $this->zip( array( 'readme.txt' => 'hello' ) );
		$this->assertSame( 'windrose_livery_zip_variation', Importer::import( $none, 'none.zip' )->get_error_code() );

		$broken = $this->zip( array( 'a.json' => '{not json' ) );
		$this->assertSame( 'windrose_livery_json', Importer::import( $broken, 'broken.zip' )->get_error_code() );

		$this->assertSame( 'windrose_livery_type', Importer::import( $this->json_file( $this->brand() ), 'brand.css' )->get_error_code() );
	}

	public function test_a_json_file_alone_is_imported_without_fonts(): void {
		$raw = $this->brand();
		$raw['settings']['typography']['fontFamilies'] = $this->brand_fonts()['fontFamilies'];
		$saved = Importer::import( $this->json_file( $raw ), 'harbour.json', array( 'dry_run' => false ) );

		$this->assertIsArray( $saved );
		$this->assertSame( 0, $saved['files'] );
		$item = Library::get( $saved['id'] );
		$this->assertArrayNotHasKey( 'fontFace', $item['data']['settings']['typography']['fontFamilies'][0] );
	}

	public function test_the_theme_is_guessed_from_preset_names(): void {
		// Ipsum and Twenty Twenty-Five share their color names since core r65224: other names tell them apart.
		$tt5                                   = $this->brand();
		$tt5['settings']['color']['palette'][] = array(
			'color' => '#ffd6e0',
			'name'  => 'Accent 2',
			'slug'  => 'accent-2',
		);
		$this->assertSame( 'twentytwentyfive', Importer::import( $this->json_file( $tt5 ), 'tt5.json' )['theme'], 'Only Twenty Twenty-Five has accent-2.' );

		$sized = $this->brand();
		$sized['styles']['elements']['h1']['typography']['fontSize'] = 'var:preset|font-size|xx-large';
		$this->assertSame( 'twentytwentyfive', Importer::import( $this->json_file( $sized ), 'sized.json' )['theme'], 'Only Twenty Twenty-Five has xx-large.' );

		$sized['styles']['elements']['h1']['typography']['fontSize'] = 'var(--wp--preset--font-size--2-x-large)';
		$this->assertSame( 'ipsum', Importer::import( $this->json_file( $sized ), 'sized.json' )['theme'], 'Only Ipsum has 2-x-large.' );

		// A tie goes to the library site's active theme.
		$this->assertSame( 'ipsum', Importer::import( $this->json_file( $this->brand() ), 'b.json' )['theme'] );
		switch_theme( 'twentytwentyfive' );
		$this->assertSame( 'twentytwentyfive', Importer::import( $this->json_file( $this->brand() ), 'b.json' )['theme'] );
		switch_theme( 'ipsum' );

		$this->assertSame( 'windrose_livery_theme', Importer::import( $this->json_file( $this->brand() ), 'b.json', array( 'theme' => 'twentyeleven' ) )->get_error_code(), 'Only block themes.' );
	}

	public function test_export_writes_a_package_that_imports_to_the_same_variation(): void {
		$saved        = Importer::import( $this->brand_zip(), 'harbour.zip', array( 'dry_run' => false ) );
		$item         = Library::get( $saved['id'] );
		$zip          = Package::export( $item );
		$this->temp[] = $zip;

		$archive = new ZipArchive();
		$archive->open( $zip );
		$names = array();
		for ( $i = 0; $i < $archive->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$names[] = $archive->getNameIndex( $i );
		}
		sort( $names );
		$this->assertSame(
			array(
				'harbour-brand/fonts.json',
				'harbour-brand/fonts/baloo/OFL.txt',
				'harbour-brand/fonts/baloo/baloo-400-normal.woff2',
				'harbour-brand/fonts/cabin/OFL.txt',
				'harbour-brand/fonts/cabin/cabin-400-normal.woff2',
				'harbour-brand/harbour-brand.json',
			),
			$names
		);
		$variation = json_decode( (string) $archive->getFromName( 'harbour-brand/harbour-brand.json' ), true );
		$this->assertArrayNotHasKey( 'typography', $variation['settings'], 'Fonts are in fonts.json.' );
		$archive->close();

		// Import it as a new version of itself: same data.
		$again = Importer::import(
			$zip,
			'harbour-brand.zip',
			array(
				'dry_run' => false,
				'replace' => true,
			)
		);
		$this->assertSame( 2, $again['version'] );
		$this->assertSame( $saved['hash'], $again['hash'] );
	}
}
