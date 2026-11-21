<?php
/**
 * Ipsum's old color names renamed (core r65224): stored styles, saved styles, block content.
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

use Windrose\Livery\Library;
use Windrose\Livery\Rename_Colors;
use Windrose\Livery\Tracker;

/**
 * The rename command's work.
 */
final class RenameColorsTest extends Livery_TestCase {

	private const BLOCK = '<!-- wp:paragraph {"textColor":"theme-3","style":{"elements":{"link":{"color":{"text":"var:preset|color|theme-6"}}}}} --><p class="has-theme-3-color has-text-color has-link-color">The theme-1 page.</p><!-- /wp:paragraph -->';

	/**
	 * A palette with Ipsum's old names, a color of its own and twins.
	 *
	 * @return array<string, mixed>
	 */
	private function old_data(): array {
		$palette = array();
		foreach ( array(
			'1' => '#ffffff',
			'2' => '#111111',
			'3' => '#aa0000',
			'4' => '#11111133',
			'5' => '#eeeeee',
			'6' => '#000000',
		) as $n => $color ) {
			$palette[] = array(
				'slug'  => 'theme-' . $n,
				'name'  => 'Color ' . $n,
				'color' => $color,
			);
		}
		$palette[] = array(
			'slug'  => 'extra-1',
			'name'  => 'Brass',
			'color' => '#b08d57',
		);
		$palette[] = array(
			'slug'  => 'windrose-livery-dark-theme-1',
			'name'  => 'Dark: Color 1',
			'color' => '#111111',
		);
		$palette[] = array(
			'slug'  => 'windrose-livery-dark-extra-1',
			'name'  => 'Dark: Brass',
			'color' => '#81602a',
		);
		return array(
			'settings' => array( 'color' => array( 'palette' => $palette ) ),
			'styles'   => array(
				'color'    => array(
					'background' => 'var:preset|color|theme-1',
					'text'       => 'var:preset|color|theme-2',
				),
				'elements' => array( 'link' => array( ':hover' => array( 'color' => array( 'text' => 'var(--wp--preset--color--theme-6)' ) ) ) ),
			),
		);
	}

	/**
	 * A stored style on the current site.
	 *
	 * @param array<string, mixed> $data  Data.
	 * @param string               $theme Theme.
	 * @return int
	 */
	private function stored( array $data, string $theme = 'ipsum' ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => Library::POST_TYPE,
				'post_status' => 'private',
				'post_title'  => 'Old ' . $theme,
			)
		);
		update_post_meta( $id, Library::META_DATA, wp_slash( Library::encode( $data ) ) );
		update_post_meta( $id, Library::META_THEME, $theme );
		return $id;
	}

	/**
	 * Saved styles of a theme on the current site.
	 *
	 * @param string $theme Theme.
	 * @return int
	 */
	private function saved( string $theme ): int {
		$data = $this->old_data() + array(
			'isGlobalStylesUserThemeJSON' => true,
			'version'                     => 3,
		);
		$id   = wp_insert_post(
			array(
				'post_type'    => 'wp_global_styles',
				'post_status'  => 'publish',
				'post_name'    => 'wp-global-styles-' . $theme,
				'post_title'   => 'Custom Styles',
				'post_content' => wp_slash( (string) wp_json_encode( $data ) ),
			)
		);
		wp_set_object_terms( (int) $id, $theme, 'wp_theme' );
		return (int) $id;
	}

	public function test_names_are_renamed_only_where_they_name_a_color(): void {
		$this->assertSame(
			'<!-- wp:paragraph {"textColor":"accent-1","style":{"elements":{"link":{"color":{"text":"var:preset|color|accent-3"}}}}} --><p class="has-accent-1-color has-text-color has-link-color">The theme-1 page.</p><!-- /wp:paragraph -->',
			Rename_Colors::rename_content( self::BLOCK )
		);
		$this->assertSame( 'has-windrose-livery-dark-base-background-color', Rename_Colors::rename_content( 'has-windrose-livery-dark-theme-1-background-color' ) );
		$this->assertSame( '{"customGradient":"linear-gradient(var(--wp--preset--color--accent-5) 0%)"}', Rename_Colors::rename_content( '{"customGradient":"linear-gradient(var(--wp--preset--color--theme-5) 0%)"}' ) );
		$this->assertSame( 'theme-10 theme-12', Rename_Colors::rename_data( 'theme-10 theme-12' ), 'Not an Ipsum name.' );
		$count = 0;
		$this->assertSame( 'base contrast accent-1 accent-6 accent-5 accent-3', Rename_Colors::rename_data( 'theme-1 theme-2 theme-3 theme-4 theme-5 theme-6', $count ) );
		$this->assertSame( 6, $count );
	}

	public function test_a_dry_run_counts_and_writes_nothing_then_the_run_renames(): void {
		$library = $this->stored( $this->old_data() );
		$tt5     = $this->stored( $this->old_data(), 'twentytwentyfive' );

		switch_to_blog( self::$ipsum_site );
		$saved = $this->saved( 'ipsum' );
		$post  = self::factory()->post->create( array( 'post_content' => self::BLOCK ) );
		$kept  = self::factory()->post->create( array( 'post_content' => self::BLOCK ) );
		update_post_meta( $kept, '_windrose_knotboard_saved', md5( self::BLOCK ) );
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => self::BLOCK . "\n",
			)
		);
		$revisions = wp_get_post_revisions( $post );
		$this->assertNotEmpty( $revisions );
		$before = json_decode( (string) get_post( $saved )->post_content, true );
		update_option(
			Tracker::OPTION,
			array(
				'id'      => 1,
				'scope'   => 0,
				'version' => 1,
				'hash'    => Library::hash( $before ),
				'how'     => 'livery',
				'time'    => time(),
				'changed' => false,
			),
			false
		);
		restore_current_blog();

		switch_to_blog( self::$tt5_site );
		$tt5_post  = self::factory()->post->create( array( 'post_content' => self::BLOCK ) );
		$tt5_saved = $this->saved( 'twentytwentyfive' );
		restore_current_blog();

		$sites = array( get_main_site_id(), self::$ipsum_site, self::$tt5_site );
		$dry   = array_column( Rename_Colors::run( $sites ), null, 'site' );
		$this->assertSame( 1, $dry[ get_main_site_id() ]['styles'], 'The Ipsum style, not the Twenty Twenty-Five one.' );
		$this->assertSame( 1, $dry[ self::$ipsum_site ]['saved'] );
		$this->assertSame( 2, $dry[ self::$ipsum_site ]['content'], 'The posts, not their revisions.' );
		$this->assertSame( 0, $dry[ self::$tt5_site ]['saved'], 'Saved styles of other themes stay.' );
		$this->assertSame( 1, $dry[ self::$tt5_site ]['skipped'], 'Content on a site without Ipsum stays.' );
		$this->assertStringContainsString( 'theme-1', (string) get_post_meta( $library, Library::META_DATA, true ), 'Dry run: nothing written.' );

		Rename_Colors::run( $sites, false );

		$data  = json_decode( (string) get_post_meta( $library, Library::META_DATA, true ), true );
		$slugs = array_column( $data['settings']['color']['palette'], 'slug' );
		$this->assertSame( array( 'base', 'contrast', 'accent-1', 'accent-3', 'accent-5', 'accent-6', 'extra-1', 'windrose-livery-dark-base', 'windrose-livery-dark-extra-1' ), $slugs, "Ipsum's order, extras, then twins." );
		$this->assertSame( '#000000', $data['settings']['color']['palette'][3]['color'], 'theme-6 became accent-3 with its value.' );
		$this->assertSame( 'var(--wp--preset--color--accent-3)', $data['styles']['elements']['link'][':hover']['color']['text'] );
		$this->assertStringContainsString( 'theme-1', (string) get_post_meta( $tt5, Library::META_DATA, true ), 'Styles for other themes stay.' );

		switch_to_blog( self::$ipsum_site );
		$content = (string) get_post( $saved )->post_content;
		$this->assertStringNotContainsString( 'theme-', $content );
		$this->assertSame( Library::hash( (array) json_decode( $content, true ) ), Tracker::get()['hash'] ?? '', 'The record still matches the saved styles.' );
		$this->assertFalse( Tracker::get()['changed'] ?? true );
		$this->assertStringContainsString( 'has-accent-1-color', (string) get_post( $post )->post_content );
		$this->assertStringContainsString( 'The theme-1 page.', (string) get_post( $post )->post_content, 'Words stay.' );
		$this->assertSame( md5( (string) get_post( $kept )->post_content ), get_post_meta( $kept, '_windrose_knotboard_saved', true ), 'An imported library pattern still matches its import.' );
		foreach ( wp_get_post_revisions( $post ) as $revision ) {
			$this->assertStringContainsString( 'has-theme-3-color', $revision->post_content, 'Revisions stay.' );
		}
		restore_current_blog();

		switch_to_blog( self::$tt5_site );
		$this->assertStringContainsString( 'has-theme-3-color', (string) get_post( $tt5_post )->post_content );
		$this->assertStringContainsString( 'theme-1', (string) get_post( $tt5_saved )->post_content );
		restore_current_blog();

		$again = Rename_Colors::run( $sites );
		$this->assertSame( 0, array_sum( array_column( $again, 'names' ) ), 'Nothing left to rename.' );
	}

	public function test_a_palette_with_old_and_new_names_is_left_alone(): void {
		$data                                   = $this->old_data();
		$data['settings']['color']['palette'][] = array(
			'slug'  => 'base',
			'name'  => 'Base',
			'color' => '#fcfcfc',
		);
		$id                                     = $this->stored( $data );
		$report                                 = Rename_Colors::run( array( get_main_site_id() ), false )[0];
		$this->assertSame( 0, $report['styles'] );
		$this->assertCount( 1, $report['clashes'] );
		$this->assertStringContainsString( 'theme-1', (string) get_post_meta( $id, Library::META_DATA, true ) );
	}
}
