<?php
/**
 * Base for Windrose Livery tests: two sites next to the main site (one on Ipsum, one on Twenty Twenty-Five), a super
 * admin, helpers that build packages, and clean-up of the fonts folder. The database rolls back after each test.
 *
 * @package Windrose\Livery
 */

use Windrose\Livery\Fonts;
use Windrose\Livery\Library;
use Windrose\Livery\Themes;

/**
 * Test case with sites and packages.
 */
abstract class Livery_TestCase extends WP_UnitTestCase {

	protected static int $ipsum_site = 0;
	protected static int $tt5_site   = 0;
	protected static int $admin      = 0;

	/**
	 * Temporary files to delete.
	 *
	 * @var list<string>
	 */
	protected array $temp = array();

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$admin      = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$ipsum_site = $factory->blog->create(
			array(
				'path'    => '/ipsum-site/',
				'title'   => 'Ipsum site',
				'user_id' => self::$admin,
			)
		);
		self::$tt5_site   = $factory->blog->create(
			array(
				'path'    => '/tt5-site/',
				'title'   => 'TT5 site',
				'user_id' => self::$admin,
			)
		);
	}

	public static function wpTearDownAfterClass(): void {
		wp_delete_site( self::$ipsum_site );
		wp_delete_site( self::$tt5_site );
		self::delete_user( self::$admin );
	}

	public function set_up(): void {
		parent::set_up();
		grant_super_admin( self::$admin );
		wp_set_current_user( self::$admin );
		Library::flush();
		Fonts::flush();
		Themes::flush();
		switch_theme( 'ipsum' );
		$this->theme_on( self::$ipsum_site, 'ipsum' );
		$this->theme_on( self::$tt5_site, 'twentytwentyfive' );
		WP_Theme_JSON_Resolver::clean_cached_data();
	}

	public function tear_down(): void {
		foreach ( $this->temp as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->remove_dir( Fonts::dir()['path'] );
		foreach ( array( self::$ipsum_site, self::$tt5_site ) as $site ) {
			$this->remove_dir( Library::with_scope( $site, static fn(): string => Fonts::dir()['path'] ) );
		}
		Library::use_scope( 0 );
		delete_site_option( \Windrose\Livery\Network::OPTION );
		Library::flush();
		Fonts::flush();
		WP_Theme_JSON_Resolver::clean_cached_data();
		parent::tear_down();
	}

	/**
	 * Activates a theme on a site.
	 */
	/**
	 * The main site's Font Library folder (WordPress's own uploads folder, whatever its name).
	 *
	 * @return array{path: string, url: string}
	 */
	protected function main_fonts(): array {
		switch_to_blog( get_main_site_id() );
		$dir = wp_get_font_dir();
		restore_current_blog();
		return array(
			'path' => (string) $dir['path'],
			'url'  => (string) $dir['url'],
		);
	}

	protected function theme_on( int $site, string $stylesheet ): void {
		switch_to_blog( $site );
		switch_theme( $stylesheet );
		restore_current_blog();
	}

	/**
	 * Removes a folder and its files.
	 */
	protected function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				unlink( $file );
			}
		}
		rmdir( $dir );
		$parent = dirname( $dir );
		if ( 'windrose-livery' === basename( $parent ) && is_dir( $parent ) && array() === array_diff( (array) scandir( $parent ), array( '.', '..' ) ) ) {
			rmdir( $parent );
		}
	}

	/**
	 * Bytes that start like a WOFF2 font.
	 */
	protected function woff2( string $seed ): string {
		return 'wOF2' . str_repeat( "\0", 28 ) . hash( 'sha256', $seed, true );
	}

	/**
	 * A brand variation in theme.json shape (Ipsum's color names).
	 *
	 * @return array<string, mixed>
	 */
	protected function brand( string $title = 'Harbour Brand' ): array {
		return array(
			'$schema'  => 'https://schemas.wp.org/trunk/theme.json',
			'version'  => 3,
			'title'    => $title,
			'settings' => array(
				'color' => array(
					'palette' => array(
						array(
							'color' => '#fff7f5',
							'name'  => 'Cream',
							'slug'  => 'base',
						),
						array(
							'color' => '#032649',
							'name'  => 'Navy',
							'slug'  => 'contrast',
						),
						array(
							'color' => '#a12700',
							'name'  => 'Dark red',
							'slug'  => 'accent-1',
						),
					),
				),
			),
			'styles'   => array(
				'typography' => array( 'fontFamily' => 'var:preset|font-family|cabin' ),
				'elements'   => array(
					'heading' => array( 'typography' => array( 'fontFamily' => 'var:preset|font-family|baloo' ) ),
					'button'  => array( 'border' => array( 'radius' => '100px' ) ),
				),
			),
		);
	}

	/**
	 * The fonts.json of the brand.
	 *
	 * @return array<string, mixed>
	 */
	protected function brand_fonts(): array {
		return array(
			'fontFamilies' => array(
				array(
					'name'       => 'Baloo',
					'slug'       => 'baloo',
					'fontFamily' => 'Baloo, system-ui, sans-serif',
					'fontFace'   => array(
						array(
							'fontFamily' => 'Baloo',
							'fontStyle'  => 'normal',
							'fontWeight' => '400',
							'src'        => array( 'fonts/baloo/baloo-400-normal.woff2' ),
						),
					),
				),
				array(
					'name'       => 'Cabin',
					'slug'       => 'cabin',
					'fontFamily' => 'Cabin, system-ui, sans-serif',
					'fontFace'   => array(
						array(
							'fontFamily' => 'Cabin',
							'fontStyle'  => 'normal',
							'fontWeight' => '400',
							'src'        => array( 'fonts/cabin/cabin-400-normal.woff2' ),
						),
					),
				),
			),
		);
	}

	/**
	 * The brand as a zip in the shape Export writes (a root folder per variation).
	 *
	 * @param array<string, string> $extra Extra entries: name => content.
	 */
	protected function brand_zip( array $variation = array(), array $extra = array(), ?array $fonts = null ): string {
		$files = array(
			'harbour/harbour.json'                       => (string) wp_json_encode( array() === $variation ? $this->brand() : $variation ),
			'harbour/fonts.json'                         => (string) wp_json_encode( $fonts ?? $this->brand_fonts() ),
			'harbour/fonts/baloo/baloo-400-normal.woff2' => $this->woff2( 'baloo' ),
			'harbour/fonts/baloo/OFL.txt'                => "Copyright Baloo\nSIL Open Font License 1.1\n",
			'harbour/fonts/cabin/cabin-400-normal.woff2' => $this->woff2( 'cabin' ),
			'harbour/fonts/cabin/OFL.txt'                => "Copyright Cabin\nSIL Open Font License 1.1\n",
		);
		return $this->zip( array_merge( $files, $extra ) );
	}

	/**
	 * A zip file with these entries.
	 *
	 * @param array<string, string> $entries Name => content.
	 */
	protected function zip( array $entries ): string {
		$path = wp_tempnam( 'livery-test.zip' );
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		foreach ( $entries as $name => $content ) {
			$zip->addFromString( $name, $content );
		}
		$zip->close();
		$this->temp[] = $path;
		return $path;
	}

	/**
	 * A JSON file.
	 *
	 * @param array<string, mixed> $data Data.
	 */
	protected function json_file( array $data ): string {
		$path = wp_tempnam( 'livery-test.json' );
		file_put_contents( $path, (string) wp_json_encode( $data ) );
		$this->temp[] = $path;
		return $path;
	}

	/**
	 * The saved styles of a site for its active theme, decoded.
	 *
	 * @return array<string, mixed>
	 */
	protected function site_styles( int $site ): array {
		switch_to_blog( $site );
		WP_Theme_JSON_Resolver::clean_cached_data();
		$post = WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme() );
		restore_current_blog();
		WP_Theme_JSON_Resolver::clean_cached_data();
		$data = json_decode( (string) ( $post['post_content'] ?? '' ), true );
		return is_array( $data ) ? $data : array();
	}
}
