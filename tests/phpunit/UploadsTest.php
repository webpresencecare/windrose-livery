<?php
/**
 * Font files found from their addresses whatever the uploads folder is called (WordPress's own, the UPLOADS
 * constant, the test library's `test-suite-uploads`), on the main site and on a subdirectory site (whose addresses
 * start with its own path, /ipsum-site/wp-content/...).
 *
 * @package Windrose\Livery
 */

use Windrose\Livery\Fonts;

/**
 * @covers \Windrose\Livery\Fonts
 */
class UploadsTest extends Livery_TestCase {

	public function test_files_found_from_their_addresses_on_every_site(): void {
		$main = wp_upload_dir( null, false );
		$root = Fonts::uploads_root();
		$this->assertSame( untrailingslashit( wp_normalize_path( $main['basedir'] ) ), $root['path'] );
		$this->assertStringEndsWith( '/', $root['marker'] );
		$this->assertStringContainsString( $root['marker'], trailingslashit( $main['baseurl'] ) );

		switch_to_blog( self::$ipsum_site );
		$this->assertSame( $root, Fonts::uploads_root(), 'The same folder and marker from a subdirectory site.' );
		$dir = wp_upload_dir( null, false );
		wp_mkdir_p( $dir['basedir'] . '/fonts' );
		$file = $dir['basedir'] . '/fonts/uploads-test.woff2';
		file_put_contents( $file, $this->woff2( 'uploads' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A test file.
		$this->temp[] = $file;
		// As a request on that site writes it (WP_CONTENT_URL carries the site's path), and as the main site does.
		$url = untrailingslashit( get_site_url( self::$ipsum_site ) ) . $root['marker'] . 'sites/' . self::$ipsum_site . '/fonts/uploads-test.woff2';
		$this->assertStringContainsString( '/ipsum-site/', $url );
		$this->assertSame( realpath( $file ), Fonts::local_path( $url ) );
		$this->assertSame( realpath( $file ), Fonts::local_path( $dir['baseurl'] . '/fonts/uploads-test.woff2' ) );
		$this->assertSame( '', Fonts::local_path( $dir['baseurl'] . '/fonts/../../../../wp-config.php' ), 'Never outside the uploads folder.' );
		$this->assertSame( '', Fonts::local_path( 'https://elsewhere.example/wp-content/uploads/fonts/uploads-test.woff2' ), 'Never another host.' );
		restore_current_blog();
	}
}
