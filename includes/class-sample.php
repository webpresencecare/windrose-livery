<?php
/**
 * Sample content for the pattern browser (0.6, `Patterns`): what a pattern shows on a site that has nothing of its
 * own yet, so visitors never see the site's content and administrators can see what they see.
 *
 * Only for the one request that renders patterns, never saved:
 * - Site title, tagline: placeholders; no logo, no site icon.
 * - Posts: six posts made in memory (IDs near PHP_INT_MAX, which no site reaches), returned to every query of posts
 *   or pages (`posts_pre_query`), with terms, a picture in the style's colors and no author. They are put in the
 *   object cache for the blocks that read them by ID, and taken out again at the end of the request.
 * - Template parts: the theme's files, never the site's edited ones.
 * - Menus: a Navigation block with a saved menu, or none (which would use the site's pages), gets sample links; the
 *   Page List block shows sample pages.
 * - Avatars: a plain circle drawn here (no request to an avatar service).
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

use WP_Post;
use WP_Query;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sample content.
 */
final class Sample {

	public const COUNT = 6;

	/**
	 * First ID of the sample posts and terms (counting down from it).
	 */
	private const BASE = PHP_INT_MAX - 1000;

	/**
	 * Whether sample content is on for this request.
	 *
	 * @var bool
	 */
	private static bool $on = false;

	/**
	 * The sample posts, by ID.
	 *
	 * @var array<int, WP_Post>
	 */
	private static array $posts = array();

	/**
	 * Queries answered with sample posts (spl_object_id => number of posts).
	 *
	 * @var array<int, int>
	 */
	private static array $answered = array();

	/**
	 * Picture colors: [ background, accent ].
	 *
	 * @var array{0: string, 1: string}
	 */
	private static array $colors = array( '#eeeeee', '#3355ff' );

	/**
	 * Turns sample content on for the rest of this request.
	 *
	 * @param array<int, array{slug: string, color: string}> $palette The style's colors (for the pictures).
	 * @return void
	 */
	public static function start( array $palette ): void {
		if ( self::$on ) {
			return;
		}
		self::$on     = true;
		self::$colors = self::picture_colors( $palette );
		self::$posts  = self::make_posts();
		foreach ( self::$posts as $id => $post ) {
			wp_cache_set( $id, $post, 'posts' );
			wp_cache_set( $id, array(), 'post_meta' );
		}
		add_action( 'shutdown', array( __CLASS__, 'stop' ) );

		$text = array(
			'blogname'        => __( 'Site Title', 'windrose-livery' ),
			'blogdescription' => __( 'A tagline for your site', 'windrose-livery' ),
		);
		foreach ( $text as $option => $value ) {
			add_filter( 'pre_option_' . $option, static fn(): string => $value );
		}
		foreach ( array( 'site_logo', 'site_icon' ) as $option ) {
			add_filter( 'pre_option_' . $option, static fn(): int => 0 );
		}
		add_filter( 'pre_option_sticky_posts', static fn(): array => array() );
		add_filter( 'theme_mod_custom_logo', '__return_false' );
		add_filter( 'posts_pre_query', array( __CLASS__, 'pre_query' ), 10, 2 );
		add_filter( 'found_posts_query', array( __CLASS__, 'found_query' ), 10, 2 );
		add_filter( 'found_posts', array( __CLASS__, 'found_posts' ), 10, 2 );
		add_filter( 'get_the_terms', array( __CLASS__, 'terms' ), 10, 3 );
		add_filter( 'post_thumbnail_id', array( __CLASS__, 'thumbnail_id' ), 10, 2 );
		add_filter( 'has_post_thumbnail', array( __CLASS__, 'has_thumbnail' ), 10, 2 );
		add_filter( 'post_thumbnail_html', array( __CLASS__, 'thumbnail_html' ), 10, 5 );
		add_filter( 'post_link', array( __CLASS__, 'link' ), 10, 2 );
		add_filter( 'term_link', static fn(): string => '#' );
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'avatar' ) );
		add_filter( 'render_block_data', array( __CLASS__, 'block_data' ) );
		add_filter( 'pre_render_block', array( __CLASS__, 'pre_render' ), 10, 2 );
		add_filter( 'block_core_navigation_render_fallback', array( __CLASS__, 'sample_links' ) );
		self::main_query();
	}

	/**
	 * The page's main query ran before (WordPress runs it before the page is rendered): its posts become the sample
	 * ones too, for query loops that follow it and blocks that read the current post.
	 *
	 * @return void
	 */
	private static function main_query(): void {
		global $wp_query;
		$list = self::posts();
		if ( ! $wp_query instanceof WP_Query || array() === $list ) {
			return;
		}
		$wp_query->posts         = $list;
		$wp_query->post_count    = count( $list );
		$wp_query->found_posts   = count( $list );
		$wp_query->max_num_pages = 1;
		$wp_query->current_post  = -1;
		$wp_query->post          = $list[0];
		$wp_query->rewind_posts();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Blocks outside a loop read the current post.
		$GLOBALS['post'] = $list[0];
	}

	/**
	 * Whether sample content is on.
	 *
	 * @return bool
	 */
	public static function on(): bool {
		return self::$on;
	}

	/**
	 * Takes the sample posts out of the object cache again (a persistent cache keeps entries between requests) and
	 * ends sample content (its filters only matter during the request, which ends here).
	 *
	 * @return void
	 */
	public static function stop(): void {
		foreach ( array_keys( self::$posts ) as $id ) {
			foreach ( array( 'posts', 'post_meta', 'category_relationships', 'post_tag_relationships', 'post_format_relationships' ) as $group ) {
				wp_cache_delete( $id, $group );
			}
		}
		self::$on       = false;
		self::$posts    = array();
		self::$answered = array();
	}

	/**
	 * The sample posts (made in memory, never saved).
	 *
	 * @return list<WP_Post>
	 */
	public static function posts(): array {
		return array_values( self::$posts );
	}

	/**
	 * Whether an ID is one of the sample posts'.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	public static function is_sample( int $id ): bool {
		return isset( self::$posts[ $id ] );
	}

	/**
	 * Builds the sample posts.
	 *
	 * @return array<int, WP_Post>
	 */
	private static function make_posts(): array {
		$texts = array(
			array(
				__( 'A walk along the harbour', 'windrose-livery' ),
				__( 'The boats come in at dawn, and the quay fills with nets, gulls and the smell of the sea.', 'windrose-livery' ),
			),
			array(
				__( 'Notes from the workshop', 'windrose-livery' ),
				__( 'Small changes, made carefully, add up: this week we sanded, oiled and fitted the last pieces.', 'windrose-livery' ),
			),
			array(
				__( 'What we learned this season', 'windrose-livery' ),
				__( 'Plans change with the weather. Here is what went well, what did not, and what comes next.', 'windrose-livery' ),
			),
			array(
				__( 'A recipe worth sharing', 'windrose-livery' ),
				__( 'Simple ingredients, a little patience and good company: the dish everyone asks for again.', 'windrose-livery' ),
			),
			array(
				__( 'Meet the team', 'windrose-livery' ),
				__( 'Five people, many talents, one shared idea of doing things well. Say hello to everyone.', 'windrose-livery' ),
			),
			array(
				__( 'An evening of music', 'windrose-livery' ),
				__( 'Strings, voices and a full room: a look back at a concert that ran late into the night.', 'windrose-livery' ),
			),
		);
		$more  = __( 'This is sample text. It shows how a longer post reads in this style, with paragraphs of ordinary length and a few sentences in each, so that line length, spacing and fonts can be judged.', 'windrose-livery' );
		$posts = array();
		$now   = time();
		foreach ( $texts as $i => [ $title, $excerpt ] ) {
			$id    = self::BASE - $i;
			$date  = $now - ( $i * 4 + 1 ) * DAY_IN_SECONDS;
			$local = wp_date( 'Y-m-d H:i:s', $date );
			$gmt   = gmdate( 'Y-m-d H:i:s', $date );

			$posts[ $id ] = new WP_Post(
				(object) array(
					'ID'                => $id,
					'post_author'       => '0',
					'post_date'         => false === $local ? $gmt : $local,
					'post_date_gmt'     => $gmt,
					'post_content'      => '<!-- wp:paragraph --><p>' . esc_html( $excerpt ) . '</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>' . esc_html( $more ) . '</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>' . esc_html( $more ) . '</p><!-- /wp:paragraph -->',
					'post_title'        => $title,
					'post_excerpt'      => $excerpt,
					'post_status'       => 'publish',
					'comment_status'    => 'closed',
					'ping_status'       => 'closed',
					'post_password'     => '',
					'post_name'         => sanitize_title( $title ),
					'to_ping'           => '',
					'pinged'            => '',
					'post_modified'     => false === $local ? $gmt : $local,
					'post_modified_gmt' => $gmt,
					'post_parent'       => 0,
					'guid'              => '',
					'menu_order'        => 0,
					'post_type'         => 'post',
					'post_mime_type'    => '',
					'comment_count'     => '0',
					'filter'            => 'raw',
				)
			);
		}//end foreach
		return $posts;
	}

	/**
	 * Answers queries of posts and pages with the sample posts, and queries of template parts with none (so the theme's
	 * files are used). Other post types (styles, fonts, menus, templates) are left alone.
	 *
	 * @param mixed    $posts Posts so far (null: run the query).
	 * @param WP_Query $query Query.
	 * @return mixed
	 */
	public static function pre_query( $posts, $query ) {
		if ( null !== $posts || ! $query instanceof WP_Query ) {
			return $posts;
		}
		$types = (array) $query->get( 'post_type' );
		$types = array_values( array_filter( array_map( 'strval', $types ) ) );
		if ( in_array( 'wp_template_part', $types, true ) ) {
			return array();
		}
		if ( array() !== array_diff( $types, array( 'post', 'page', 'any' ) ) ) {
			return null;
		}
		$list     = self::posts();
		$per_page = (int) $query->get( 'posts_per_page' );
		$offset   = (int) $query->get( 'offset' );
		$page     = max( 1, (int) $query->get( 'paged' ) );
		if ( $per_page > 0 ) {
			$start = $offset > 0 ? $offset : ( $page - 1 ) * $per_page;
			$list  = array_slice( $list, $start, $per_page );
		}
		// Blocks read them by ID from the object cache (set in start()); nothing to add from the database.
		$query->query_vars['cache_results']          = false;
		$query->query_vars['update_post_meta_cache'] = false;
		$query->query_vars['update_post_term_cache'] = false;
		self::$answered[ spl_object_id( $query ) ]   = count( self::$posts );
		return 'ids' === $query->get( 'fields' ) ? array_map( static fn( WP_Post $p ): int => $p->ID, $list ) : $list;
	}

	/**
	 * No `SELECT FOUND_ROWS()` for a query answered here.
	 *
	 * @param mixed    $sql   SQL.
	 * @param WP_Query $query Query.
	 * @return mixed
	 */
	public static function found_query( $sql, $query ) {
		return $query instanceof WP_Query && isset( self::$answered[ spl_object_id( $query ) ] ) ? 'SELECT 0' : $sql;
	}

	/**
	 * The number of sample posts, for pagination.
	 *
	 * @param mixed    $found Found posts.
	 * @param WP_Query $query Query.
	 * @return mixed
	 */
	public static function found_posts( $found, $query ) {
		return $query instanceof WP_Query && isset( self::$answered[ spl_object_id( $query ) ] ) ? self::$answered[ spl_object_id( $query ) ] : $found;
	}

	/**
	 * Terms of a sample post: one category, two tags (made in memory).
	 *
	 * @param mixed  $terms    Terms.
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @return mixed
	 */
	public static function terms( $terms, $post_id, $taxonomy ) {
		if ( ! self::is_sample( (int) $post_id ) ) {
			return $terms;
		}
		$names = match ( (string) $taxonomy ) {
			'category' => array( __( 'Stories', 'windrose-livery' ) ),
			'post_tag' => array( __( 'Harbour', 'windrose-livery' ), __( 'News', 'windrose-livery' ) ),
			default    => array(),
		};
		$list = array();
		foreach ( $names as $i => $name ) {
			$list[] = new WP_Term(
				(object) array(
					'term_id'          => self::BASE - 100 - $i - ( 'post_tag' === $taxonomy ? 10 : 0 ),
					'name'             => $name,
					'slug'             => sanitize_title( $name ),
					'term_group'       => 0,
					'term_taxonomy_id' => self::BASE - 100 - $i - ( 'post_tag' === $taxonomy ? 10 : 0 ),
					'taxonomy'         => (string) $taxonomy,
					'description'      => '',
					'parent'           => 0,
					'count'            => self::COUNT,
					'filter'           => 'raw',
				)
			);
		}
		return array() === $list ? false : $list;
	}

	/**
	 * Sample posts have no attachment: their picture comes from thumbnail_html().
	 *
	 * @param mixed $id   Attachment ID.
	 * @param mixed $post Post.
	 * @return mixed
	 */
	public static function thumbnail_id( $id, $post ) {
		return $post instanceof WP_Post && self::is_sample( $post->ID ) ? 0 : $id;
	}

	/**
	 * Every sample post has a picture.
	 *
	 * @param mixed $has  Whether it has one.
	 * @param mixed $post Post.
	 * @return mixed
	 */
	public static function has_thumbnail( $has, $post ) {
		$id = $post instanceof WP_Post ? $post->ID : ( is_numeric( $post ) ? (int) $post : 0 );
		return self::is_sample( $id ) ? true : $has;
	}

	/**
	 * A sample post's picture: a gradient in the style's colors (an SVG data address, so nothing is loaded).
	 *
	 * @param mixed $html    HTML so far.
	 * @param mixed $post_id Post ID.
	 * @param mixed $thumb   Attachment ID.
	 * @param mixed $size    Size.
	 * @param mixed $attr    Attributes of the img tag.
	 * @return mixed
	 */
	public static function thumbnail_html( $html, $post_id, $thumb = 0, $size = '', $attr = array() ) {
		if ( ! self::is_sample( (int) $post_id ) || ( is_string( $html ) && '' !== $html ) ) {
			return $html;
		}
		$attr  = is_array( $attr ) ? $attr : array();
		$index = self::BASE - (int) $post_id;
		$attrs = array(
			'src'     => self::picture( $index ),
			'alt'     => (string) ( $attr['alt'] ?? '' ),
			'width'   => '1200',
			'height'  => '800',
			'class'   => trim( 'attachment-' . ( is_string( $size ) ? $size : 'post-thumbnail' ) . ' size-' . ( is_string( $size ) ? $size : 'post-thumbnail' ) . ' wp-post-image ' . (string) ( $attr['class'] ?? '' ) ),
			'style'   => (string) ( $attr['style'] ?? '' ),
			'loading' => 'lazy',
		);
		$out   = '<img';
		foreach ( $attrs as $name => $value ) {
			if ( '' !== $value || 'alt' === $name ) {
				$out .= ' ' . $name . '="' . esc_attr( $value ) . '"';
			}
		}
		return $out . '>';
	}

	/**
	 * Links of sample posts lead nowhere.
	 *
	 * @param mixed $link Link.
	 * @param mixed $post Post.
	 * @return mixed
	 */
	public static function link( $link, $post ) {
		return $post instanceof WP_Post && self::is_sample( $post->ID ) ? '#' : $link;
	}

	/**
	 * A plain avatar drawn here.
	 *
	 * @param mixed $args Avatar data.
	 * @return mixed
	 */
	public static function avatar( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		[ $back, $front ]     = self::$colors;
		$svg                  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96"><rect width="96" height="96" fill="' . $back . '"/><circle cx="48" cy="38" r="18" fill="' . $front . '"/><circle cx="48" cy="100" r="36" fill="' . $front . '"/></svg>';
		$args['url']          = 'data:image/svg+xml,' . rawurlencode( $svg );
		$args['found_avatar'] = true;
		return $args;
	}

	/**
	 * A Navigation block with a saved menu, or none, shows sample links instead.
	 *
	 * @param mixed $block Parsed block.
	 * @return mixed
	 */
	public static function block_data( $block ) {
		if ( ! is_array( $block ) || 'core/navigation' !== ( $block['blockName'] ?? '' ) ) {
			return $block;
		}
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$inner = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
		if ( isset( $attrs['ref'] ) || array() === $inner ) {
			unset( $attrs['ref'] );
			$links                 = self::sample_links();
			$block['attrs']        = $attrs;
			$block['innerBlocks']  = $links;
			$block['innerHTML']    = '';
			$block['innerContent'] = array_fill( 0, count( $links ), null );
		}
		return $block;
	}

	/**
	 * Sample menu items.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function sample_links(): array {
		$links = array();
		foreach ( self::page_names() as $label ) {
			$links[] = array(
				'blockName'    => 'core/navigation-link',
				'attrs'        => array(
					'label'          => $label,
					'url'            => '#',
					'kind'           => 'custom',
					'isTopLevelLink' => true,
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			);
		}
		return $links;
	}

	/**
	 * The Page List block lists sample pages.
	 *
	 * @param mixed $pre   Output so far (null: render).
	 * @param mixed $block Parsed block.
	 * @return mixed
	 */
	public static function pre_render( $pre, $block ) {
		if ( null !== $pre || ! is_array( $block ) || 'core/page-list' !== ( $block['blockName'] ?? '' ) ) {
			return $pre;
		}
		$items = '';
		foreach ( self::page_names() as $label ) {
			$items .= '<li class="wp-block-pages-list__item"><a class="wp-block-pages-list__item__link" href="#">' . esc_html( $label ) . '</a></li>';
		}
		return '<ul class="wp-block-page-list">' . $items . '</ul>';
	}

	/**
	 * Names of the sample pages.
	 *
	 * @return list<string>
	 */
	private static function page_names(): array {
		return array(
			__( 'Home', 'windrose-livery' ),
			__( 'About', 'windrose-livery' ),
			__( 'Blog', 'windrose-livery' ),
			__( 'Contact', 'windrose-livery' ),
		);
	}

	/**
	 * A picture for sample post number `$index`: a gradient between the style's soft background and accent.
	 *
	 * @param int $index Index.
	 * @return string Data address.
	 */
	public static function picture( int $index ): string {
		[ $back, $front ] = self::$colors;
		$angle            = array( '1', '0', '1', '0.5', '0', '1' )[ $index % 6 ];
		$svg              = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800" preserveAspectRatio="none"><defs><linearGradient id="g" x2="' . $angle . '" y2="1"><stop offset="0" stop-color="' . $back . '"/><stop offset="1" stop-color="' . $front . '"/></linearGradient></defs><rect width="1200" height="800" fill="url(#g)"/></svg>';
		return 'data:image/svg+xml,' . rawurlencode( $svg );
	}

	/**
	 * The picture colors from a palette: Ipsum's soft background (accent-5) and accent (accent-1), or the first two.
	 *
	 * @param array<int, array{slug: string, color: string}> $palette Palette.
	 * @return array{0: string, 1: string}
	 */
	private static function picture_colors( array $palette ): array {
		$by    = array();
		$valid = static fn( string $c ): bool => 1 === preg_match( '/^#[0-9a-f]{3,8}$/i', $c );
		foreach ( $palette as $color ) {
			if ( $valid( $color['color'] ) && ! str_starts_with( $color['slug'], Schemes::PREFIX ) ) {
				$by[ $color['slug'] ] = $color['color'];
			}
		}
		$list = array_values( $by );
		return array(
			$by['accent-5'] ?? $list[0] ?? '#eeeeee',
			$by['accent-1'] ?? $list[1] ?? '#3355ff',
		);
	}
}
