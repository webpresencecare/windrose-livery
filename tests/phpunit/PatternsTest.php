<?php
/**
 * The pattern browser (0.6): who may see what, which patterns each person gets, the style in place of the saved
 * styles (the same stylesheet as really applying it), and sample content that shows nothing of the site and saves
 * nothing.
 *
 * @package Windrose\Livery
 */

use Windrose\Livery\Applier;
use Windrose\Livery\Importer;
use Windrose\Livery\Library;
use Windrose\Livery\Mermaid;
use Windrose\Livery\Network;
use Windrose\Livery\Patterns;
use Windrose\Livery\Sample;

/**
 * @covers \Windrose\Livery\Patterns
 * @covers \Windrose\Livery\Sample
 */
class PatternsTest extends Livery_TestCase {

	private const PLUGIN_PATTERN = 'windrose-test/plugin-pattern';

	public function set_up(): void {
		parent::set_up();
		register_block_pattern(
			self::PLUGIN_PATTERN,
			array(
				'title'      => 'A plugin pattern',
				'categories' => array( 'text' ),
				'content'    => '<!-- wp:paragraph --><p>From a plugin</p><!-- /wp:paragraph -->',
			)
		);
		// The test suite switches to Ipsum after WordPress registered the starting theme's patterns.
		_register_theme_block_patterns();
		$_POST = array();
	}

	/**
	 * A valid recipe (Style Mermaid's shared fixture).
	 */
	private function recipe(): string {
		$list = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/block-looks.json' ), true );
		$this->assertIsArray( $list );
		return (string) wp_json_encode( $list[0]['recipe'] );
	}

	public function tear_down(): void {
		unregister_block_pattern( self::PLUGIN_PATTERN );
		Patterns::stop_data();
		Sample::stop();
		remove_all_filters( Patterns::ITEMS_FILTER );
		remove_all_filters( Patterns::VISITORS_FILTER );
		$_POST = array();
		delete_option( Mermaid::OPTION );
		parent::tear_down();
	}

	/**
	 * Imports the brand into the shared library.
	 *
	 * @return array<string, mixed> Stored variation.
	 */
	private function stored(): array {
		$result = Importer::import( $this->json_file( $this->brand() ), 'brand.json', array( 'dry_run' => false ) );
		$this->assertIsArray( $result );
		$item = Library::get( (int) $result['id'] );
		$this->assertIsArray( $item );
		return $item;
	}

	/**
	 * Every pattern name of a list of groups.
	 *
	 * @param list<array{patterns: list<array{name: string}>}> $groups Groups.
	 * @return list<string>
	 */
	private function names( array $groups ): array {
		return array_values( array_unique( array_merge( ...array_map( static fn( array $g ): array => array_column( $g['patterns'], 'name' ), $groups ) ) ) );
	}

	public function test_administrators_get_every_pattern_of_the_inserter(): void {
		$groups = Patterns::groups( false );
		$names  = $this->names( $groups );
		$this->assertContains( self::PLUGIN_PATTERN, $names );
		$this->assertContains( 'ipsum/about-row', $names );
		$this->assertNotContains( 'ipsum/hidden-404', $names, 'Hidden from the inserter.' );
		$labels = array_column( $groups, 'label' );
		$this->assertSame( count( $labels ), count( array_unique( $labels ) ), 'One group per label (core has two "Posts").' );
		$sorted = $labels;
		natcasesort( $sorted );
		$this->assertSame( array_values( $sorted ), $labels );
	}

	public function test_visitors_get_only_the_themes_and_wordpress_patterns(): void {
		$names = $this->names( Patterns::groups( true ) );
		$this->assertNotContains( self::PLUGIN_PATTERN, $names );
		$this->assertNotEmpty( $names );
		foreach ( $names as $name ) {
			$this->assertMatchesRegularExpression( '#^(core|ipsum)/#', $name );
		}
		$this->assertTrue( Patterns::is_default( array( 'source' => 'core' ) ) );
		$this->assertTrue( Patterns::is_default( array( 'filePath' => get_template_directory() . '/patterns/x.php' ) ) );
		$this->assertFalse( Patterns::is_default( array( 'filePath' => WP_PLUGIN_DIR . '/x/patterns/x.php' ) ) );
	}

	public function test_list_route_rights(): void {
		$answer = rest_do_request( new WP_REST_Request( 'GET', '/windrose-livery/v1/patterns' ) );
		$this->assertSame( 200, $answer->get_status() );
		$this->assertFalse( $answer->get_data()['visitor'] );
		$this->assertContains( self::PLUGIN_PATTERN, $this->names( $answer->get_data()['groups'] ) );

		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( new WP_REST_Request( 'GET', '/windrose-livery/v1/patterns' ) )->get_status() );
		update_option( Mermaid::OPTION, array( 'public' => true ) );
		$answer = rest_do_request( new WP_REST_Request( 'GET', '/windrose-livery/v1/patterns' ) );
		$this->assertSame( 200, $answer->get_status() );
		$this->assertTrue( $answer->get_data()['visitor'] );
		$this->assertNotContains( self::PLUGIN_PATTERN, $this->names( $answer->get_data()['groups'] ) );
	}

	public function test_visitors_only_with_the_public_switch_an_unsaved_style_and_sample_content(): void {
		$item = $this->stored();
		wp_set_current_user( 0 );
		$_POST = array( 'recipe' => $this->recipe() );
		$this->assertSame( 403, Patterns::context()->get_error_data()['status'] );

		update_option( Mermaid::OPTION, array( 'public' => true ) );
		$context = Patterns::context();
		$this->assertIsArray( $context );
		$this->assertTrue( $context['visitor'] );
		$this->assertTrue( $context['sample'] );

		$_POST = array(
			'style'  => (string) $item['id'],
			'sample' => '',
		);
		$this->assertSame( 403, Patterns::context()->get_error_data()['status'], 'Stored styles: administrators only.' );

		// A logged-in administrator without the nonce is a visitor too.
		wp_set_current_user( self::$admin );
		$this->assertSame( 403, Patterns::context()->get_error_data()['status'] );
	}

	public function test_administrators_with_the_nonce_see_stored_styles_that_fit(): void {
		$item    = $this->stored();
		$_POST   = array(
			'style'    => (string) $item['id'],
			'scope'    => '0',
			'_wpnonce' => wp_create_nonce( Patterns::NONCE ),
			'category' => 'about',
		);
		$context = Patterns::context();
		$this->assertIsArray( $context );
		$this->assertFalse( $context['visitor'] );
		$this->assertFalse( $context['sample'] );
		$this->assertSame( 'about', $context['category'] );
		$this->assertSame( Library::resolved( $item ), $context['data'] );

		$_POST['sample'] = '1';
		$this->assertTrue( Patterns::context()['sample'] );

		// Another theme: refused; a site's own style elsewhere than on its site: refused.
		switch_theme( 'twentytwentyfive' );
		$this->assertSame( 409, Patterns::context()->get_error_data()['status'] );
		switch_theme( 'ipsum' );
		$_POST['scope'] = (string) self::$ipsum_site;
		$this->assertSame( 409, Patterns::context()->get_error_data()['status'] );

		// Style Mermaid's style needs Ipsum.
		$_POST = array(
			'recipe'   => $this->recipe(),
			'_wpnonce' => wp_create_nonce( Patterns::NONCE ),
		);
		$this->assertIsArray( Patterns::context() );
		switch_theme( 'twentytwentyfive' );
		$this->assertSame( 409, Patterns::context()->get_error_data()['status'] );
	}

	public function test_site_administrators_need_livery_rights(): void {
		$item = $this->stored();
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$_POST = array(
			'style'    => (string) $item['id'],
			'_wpnonce' => wp_create_nonce( Patterns::NONCE ),
		);
		$this->assertFalse( Patterns::manages(), 'Site administrators while the network switch is off.' );
		$this->assertSame( 403, Patterns::context()->get_error_data()['status'] );
		update_site_option( Network::OPTION, array( 'site_admins' => true ) );
		$this->assertTrue( Patterns::manages() );
		// The shared library stays the network administrators'.
		$this->assertSame( 403, Patterns::context()->get_error_data()['status'] );
	}

	public function test_the_style_in_place_of_the_saved_styles_is_what_applying_gives(): void {
		$item = $this->stored();
		Patterns::use_data( Library::resolved( $item ) );
		$shown = wp_get_global_stylesheet();
		$fonts = wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
		Patterns::stop_data();
		$this->assertStringContainsString( '--wp--preset--color--base: #fff7f5', $shown );
		$this->assertStringNotContainsString( '#fff7f5', wp_get_global_stylesheet(), 'Nothing saved.' );

		$results = Applier::apply( $item, array( get_current_blog_id() ) );
		$this->assertSame( 'applied', $results[0]['status'] );
		wp_clean_theme_json_cache();
		$this->assertSame( wp_get_global_stylesheet(), $shown );
		$this->assertSame( wp_get_global_settings( array( 'typography', 'fontFamilies' ) ), $fonts );
	}

	public function test_markup_labels_and_core_pattern_blocks(): void {
		$groups = array(
			array(
				'key'      => 'about',
				'label'    => 'About',
				'patterns' => array(
					array(
						'name'  => 'ipsum/about-row',
						'title' => 'About <Row>',
					),
				),
			),
			array(
				'key'      => 'text',
				'label'    => 'Text',
				'patterns' => array(
					array(
						'name'  => 'core/x',
						'title' => 'X',
					),
				),
			),
		);
		$all    = Patterns::markup( $groups, '' );
		$this->assertStringContainsString( '<!-- wp:pattern {"slug":"ipsum/about-row"} /-->', $all );
		$this->assertStringContainsString( 'data-windrose-livery-pattern="ipsum/about-row">About &lt;Row&gt;</p>', $all );
		$this->assertStringContainsString( 'core/x', $all );
		$this->assertStringNotContainsString( 'core/x', Patterns::markup( $groups, 'about' ) );
		$this->assertStringContainsString( 'No patterns', Patterns::markup( $groups, 'none' ) );
	}

	public function test_sample_content_shows_nothing_of_the_site_and_saves_nothing(): void {
		update_option( 'blogname', 'Real Name 42' );
		$real = self::factory()->post->create(
			array(
				'post_title'  => 'Real post 42',
				'post_status' => 'publish',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Real page 42',
				'post_status' => 'publish',
			)
		);
		$this->assertStringContainsString( 'Real post 42', do_blocks( '<!-- wp:pattern {"slug":"core/query-standard-posts"} /-->' ) );
		$block = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_title'  => 'My pattern',
				'post_status' => 'publish',
			)
		);
		$posts = (int) wp_count_posts()->publish;
		$menus = count(
			get_posts(
				array(
					'post_type'   => 'wp_navigation',
					'post_status' => 'any',
					'fields'      => 'ids',
				)
			)
		);

		Sample::start(
			array(
				array(
					'slug'  => 'accent-1',
					'color' => '#a12700',
				),
			)
		);
		$this->assertSame( 'Site Title', get_bloginfo( 'name' ) );
		$query = do_blocks( '<!-- wp:pattern {"slug":"core/query-standard-posts"} /-->' );
		$this->assertStringContainsString( 'A walk along the harbour', $query );
		$this->assertStringNotContainsString( 'Real post 42', $query );
		$thumbnail = get_the_post_thumbnail( Sample::posts()[0]->ID );
		$this->assertStringContainsString( 'data:image/svg+xml', $thumbnail );
		$this->assertStringContainsString( 'alt=""', $thumbnail, 'The sample picture is decorative.' );
		$this->assertSame( 'Stories', get_the_terms( Sample::posts()[0]->ID, 'category' )[0]->name );
		$this->assertStringStartsWith( 'data:image/svg+xml', (string) get_avatar_url( 0 ) );

		$nav = do_blocks( '<!-- wp:navigation /--><!-- wp:page-list /-->' );
		$this->assertStringContainsString( 'Contact', $nav );
		$this->assertStringNotContainsString( 'Real page 42', $nav );
		$this->assertStringNotContainsString( 'Real Name 42', do_blocks( '<!-- wp:site-title /-->' ) );

		// Other post types are left alone; template parts come from the theme's files.
		$this->assertSame(
			array( $block ),
			get_posts(
				array(
					'post_type' => 'wp_block',
					'fields'    => 'ids',
				)
			)
		);
		$parts = new WP_Query( array( 'post_type' => 'wp_template_part' ) );
		$this->assertSame( array(), $parts->posts );

		$sample_id = Sample::posts()[0]->ID;
		Sample::stop();
		$this->assertFalse( wp_cache_get( $sample_id, 'posts' ) );
		$this->assertSame( $posts, (int) wp_count_posts()->publish );
		$this->assertSame(
			$menus,
			count(
				get_posts(
					array(
						'post_type'   => 'wp_navigation',
						'post_status' => 'any',
						'fields'      => 'ids',
					)
				)
			)
		);
		$this->assertSame( 'Real post 42', get_the_title( $real ) );
	}

	/**
	 * A test source (as Windrose Knotboard will be): two offered items, one item for administrators only, and what
	 * the filter was asked.
	 *
	 * @param array<int, mixed> $asked Filled with the filter's arguments.
	 */
	private function source( array &$asked ): void {
		add_filter(
			Patterns::ITEMS_FILTER,
			static function ( $items, string $source, array $keys, bool $visitor ) use ( &$asked ) {
				if ( 'test' !== $source ) {
					return $items;
				}
				$asked = array( $keys, $visitor );
				$known = array(
					'a'     => array(
						'title'   => 'Header <A>',
						'content' => '<!-- wp:paragraph --><p>Item A</p><!-- /wp:paragraph -->',
						'public'  => true,
					),
					'b:2'   => array(
						'title'   => 'Item B',
						'content' => '<!-- wp:paragraph --><p>Item B</p><!-- /wp:paragraph -->',
						'public'  => true,
					),
					'draft' => array(
						'title'   => 'Draft',
						'content' => '<!-- wp:paragraph --><p>Not offered</p><!-- /wp:paragraph -->',
						'public'  => false,
					),
				);
				$list  = array( array( 'name' => 'broken' ) );
				foreach ( $keys as $key ) {
					if ( isset( $known[ $key ] ) && ( ! $visitor || $known[ $key ]['public'] ) ) {
						$list[] = array( 'name' => 'test/' . $key ) + $known[ $key ];
					}
				}
				return $list;
			},
			10,
			4
		);
	}

	public function test_another_plugins_items_in_their_order_as_a_list_or_a_page(): void {
		$asked = array();
		$this->source( $asked );
		$_POST   = array(
			'recipe'   => $this->recipe(),
			'_wpnonce' => wp_create_nonce( Patterns::NONCE ),
			'source'   => 'test',
			'items'    => 'b:2,draft,a,b:2,<bad>, ',
			'layout'   => 'page',
		);
		$context = Patterns::context();
		$this->assertIsArray( $context );
		$this->assertFalse( $context['visitor'] );
		$this->assertSame( array( array( 'b:2', 'draft', 'a', 'b:2' ), false ), $asked, 'Keys checked and kept in order, duplicates kept.' );
		$this->assertSame( array( 'test/b:2', 'test/draft', 'test/a', 'test/b:2' ), array_column( $context['items'], 'name' ), 'Malformed answers dropped.' );
		$this->assertSame( 'page', $context['layout'] );

		$page = Patterns::items_markup( $context['items'], 'page' );
		$this->assertStringNotContainsString( 'windrose-livery-patterns__label', $page );
		$this->assertLessThan( strpos( $page, 'Item A' ), strpos( $page, 'Item B' ) );
		$list = Patterns::items_markup( $context['items'], 'list' );
		$this->assertStringContainsString( 'data-windrose-livery-pattern="test/a">Header &lt;A&gt;</p>', $list );
		$this->assertSame( 4, substr_count( $list, 'windrose-livery-patterns__label' ) );
		$this->assertStringContainsString( '>Item B</p>', do_blocks( $page ) );

		$_POST['layout'] = 'other';
		$this->assertSame( 'list', Patterns::context()['layout'] );
	}

	public function test_items_errors(): void {
		$asked = array();
		$this->source( $asked );
		$base  = array(
			'recipe'   => $this->recipe(),
			'_wpnonce' => wp_create_nonce( Patterns::NONCE ),
		);
		$_POST = $base + array(
			'source' => 'test',
			'items'  => '<x>,',
		);
		$this->assertSame( 400, Patterns::context()->get_error_data()['status'], 'No usable key.' );
		$_POST = $base + array(
			'source' => 'nobody',
			'items'  => 'a',
		);
		$this->assertSame( 404, Patterns::context()->get_error_data()['status'], 'No plugin answers this source.' );
		$_POST = $base + array(
			'source' => 'test',
			'items'  => 'unknown',
		);
		$this->assertSame( 404, Patterns::context()->get_error_data()['status'], 'Nothing usable in the answer.' );
		add_filter( Patterns::ITEMS_FILTER, static fn() => new WP_Error( 'x', 'Refused', array( 'status' => 403 ) ), 20 );
		$_POST = $base + array(
			'source' => 'test',
			'items'  => 'a',
		);
		$this->assertSame( 'Refused', Patterns::context()->get_error_message() );

		$this->assertCount( Patterns::MAX_ITEMS, Patterns::item_keys( implode( ',', array_fill( 0, 80, 'a' ) ) ) );
		$this->assertSame( array( 'a-b_c/d:1' ), Patterns::item_keys( 'a-b_c/d:1,a b,é,' . str_repeat( 'x', 201 ) ) );
	}

	public function test_visitors_of_a_source_library(): void {
		$asked = array();
		$this->source( $asked );
		$item = $this->stored();
		wp_set_current_user( 0 );
		$_POST = array(
			'recipe' => $this->recipe(),
			'source' => 'test',
			'items'  => 'a,draft,b:2',
		);
		$this->assertFalse( Patterns::visitors( 'test' ) );
		$this->assertSame( 403, Patterns::context()->get_error_data()['status'], 'Public Style Mermaid off, no public library.' );

		add_filter( Patterns::VISITORS_FILTER, static fn( bool $open, string $source ): bool => $open || 'test' === $source, 10, 2 );
		$this->assertTrue( Patterns::visitors( 'test' ) );
		$this->assertFalse( Patterns::visitors() );
		$context = Patterns::context();
		$this->assertIsArray( $context );
		$this->assertTrue( $context['visitor'] );
		$this->assertTrue( $context['sample'] );
		$this->assertSame( array( 'test/a', 'test/b:2' ), array_column( $context['items'], 'name' ), 'The source gives visitors only what it offers.' );
		$this->assertTrue( $asked[1] );

		// The source's library opens nothing else: not the site's registered patterns, not stored styles.
		$_POST = array( 'recipe' => $this->recipe() );
		$this->assertSame( 403, Patterns::context()->get_error_data()['status'] );
		$_POST = array(
			'style'  => (string) $item['id'],
			'source' => 'test',
			'items'  => 'a',
		);
		$this->assertSame( 403, Patterns::context()->get_error_data()['status'] );
		$_POST = array(
			'recipe' => $this->recipe(),
			'source' => 'other',
			'items'  => 'a',
		);
		$this->assertSame( 403, Patterns::context()->get_error_data()['status'] );
	}

	public function test_ready_recipes_are_accepted_by_the_server(): void {
		$list = \Windrose\Livery\Catalog::ready_recipes();
		$this->assertCount( 21, $list );
		$this->assertSame( 'ipsum', $list[0]['slug'] );
		foreach ( $list as $item ) {
			$parsed = \Windrose\Livery\Builder::parse( (string) wp_json_encode( $item['recipe'] ) );
			$this->assertIsArray( $parsed, $item['slug'] );
			$this->assertIsArray( \Windrose\Livery\Builder::style_data( $parsed ), $item['slug'] );
		}
		// And the render address takes one from a visitor of a source library.
		add_filter( Patterns::VISITORS_FILTER, '__return_true' );
		add_filter(
			Patterns::ITEMS_FILTER,
			static fn() => array(
				array(
					'name'    => 'x/a',
					'title'   => 'A',
					'content' => '<!-- wp:paragraph --><p>A</p><!-- /wp:paragraph -->',
				),
			)
		);
		wp_set_current_user( 0 );
		$_POST = array(
			'recipe' => (string) wp_json_encode( $list[5]['recipe'] ),
			'source' => 'test',
			'items'  => 'a',
		);
		$this->assertIsArray( Patterns::context() );
	}

	public function test_style_mermaid_knows_the_sites_pattern_library(): void {
		$this->assertSame( '', Mermaid::pattern_library() );
		$this->assertSame( '', Mermaid::config( 'public' )['patternLibrary'] );
		add_filter( 'windrose_livery_pattern_library', static fn() => 'https://example.org/patterns/' );
		$this->assertSame( 'https://example.org/patterns/', Mermaid::config( 'public' )['patternLibrary'] );
		$this->assertSame( 'https://example.org/patterns/', Mermaid::config( 'admin' )['patternLibrary'] );
		remove_all_filters( 'windrose_livery_pattern_library' );
		add_filter( 'windrose_livery_pattern_library', static fn() => 'javascript:alert(1)' );
		$this->assertSame( '', Mermaid::pattern_library(), 'Web addresses only.' );
		remove_all_filters( 'windrose_livery_pattern_library' );
	}
}
