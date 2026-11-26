<?php
/**
 * Livery's fonts in WordPress's Font Library: the fonts of a stored variation are in the folder
 * where the Font Library puts fonts (`Fonts::dir()`), and registered there as WordPress registers an installed font:
 * a `wp_font_family` post (title = name, slug, content = `fontFamily`) and a `wp_font_face` post per face (title =
 * `WP_Font_Utils::get_font_face_slug()`, content = the face settings with the file's address, meta
 * `_wp_font_face_file` = the file name in the fonts folder). So they show in Styles, Typography, Fonts, on the library
 * site, like any installed font. Posts Livery made carry `_windrose_livery_font`; a family or face already installed
 * (same slug, same face) is used as it is. `WP_Font_Utils` is marked private in core, but its face slug is the only
 * way to match core's own duplicate check, so it is used for that and for the family stack's quoting.
 *
 * Deleting a font family (or face) in Manage fonts deletes its files (core). When a stored variation or a site's
 * saved styles use them, the first DELETE is refused with a message that core's dialog shows ("…Delete it again
 * within 10 minutes to delete it anyway."); a second DELETE of the same font by the same person within 10 minutes
 * goes through.
 *
 * @package Windrose\Livery
 */

declare( strict_types=1 );

namespace Windrose\Livery;

use WP_Error;
use WP_Font_Utils;
use WP_Post;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Font Library registration and the delete warning.
 */
final class Font_Library {

	public const META       = '_windrose_livery_font';
	public const CONFIRM    = 'windrose_livery_font_delete_';
	public const CONFIRM_TO = 600;

	/**
	 * Face settings WordPress keeps (`WP_REST_Font_Faces_Controller` schema).
	 */
	private const FACE_KEYS = array( 'fontFamily', 'fontStyle', 'fontWeight', 'fontDisplay', 'fontStretch', 'ascentOverride', 'descentOverride', 'lineGapOverride', 'sizeAdjust', 'unicodeRange' );

	/**
	 * Hooks the delete warning.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_delete' ), 10, 3 );
	}

	/**
	 * Registers font families (theme.json shape, sources `windrose-livery:<file>`) in the Font Library of the scope's
	 * library site.
	 *
	 * @param array<mixed> $families Font families.
	 * @return int Faces added.
	 */
	public static function register( array $families ): int {
		$dir = Fonts::dir();
		return Library::on_library_site(
			static function () use ( $families, $dir ): int {
				$added = 0;
				foreach ( $families as $family ) {
					if ( ! is_array( $family ) || ! is_string( $family['slug'] ?? null ) || ! is_array( $family['fontFace'] ?? null ) ) {
						continue;
					}
					$faces = array();
					foreach ( $family['fontFace'] as $face ) {
						$stored = self::stored_of( $face );
						if ( '' !== $stored && is_file( $dir['path'] . '/' . $stored ) && is_array( $face ) ) {
							$faces[] = array( $face, $stored );
						}
					}
					if ( array() === $faces ) {
						continue;
					}
					$parent = self::find_or_add_family( $family );
					if ( 0 === $parent ) {
						continue;
					}
					foreach ( $faces as [ $face, $stored ] ) {
						$added += self::add_face( $parent, $face, $stored, $dir['url'] ) ? 1 : 0;
					}
				}//end foreach
				return $added;
			}
		);
	}

	/**
	 * The stored name of a face's first `windrose-livery:` source.
	 *
	 * @param mixed $face Face.
	 * @return string
	 */
	private static function stored_of( $face ): string {
		foreach ( is_array( $face ) ? (array) ( $face['src'] ?? array() ) : array() as $one ) {
			if ( is_string( $one ) && str_starts_with( $one, Fonts::PREFIX ) && Fonts::valid_name( substr( $one, strlen( Fonts::PREFIX ) ) ) ) {
				return substr( $one, strlen( Fonts::PREFIX ) );
			}
		}
		return '';
	}

	/**
	 * The Font Library family for a family slug on the current site: the existing one, or a new one.
	 *
	 * @param array<mixed> $family Family.
	 * @return int Post ID, 0 on failure.
	 */
	private static function find_or_add_family( array $family ): int {
		$slug     = sanitize_title( (string) $family['slug'] );
		$existing = get_posts(
			array(
				'post_type'   => 'wp_font_family',
				'name'        => $slug,
				'post_status' => 'publish',
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);
		if ( array() !== $existing ) {
			return (int) $existing[0];
		}
		$name  = is_string( $family['name'] ?? null ) && '' !== $family['name'] ? $family['name'] : $slug;
		$stack = is_string( $family['fontFamily'] ?? null ) && '' !== $family['fontFamily'] ? $family['fontFamily'] : '"' . $name . '"';
		$id    = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'wp_font_family',
					'post_status'  => 'publish',
					'post_title'   => sanitize_text_field( $name ),
					'post_name'    => $slug,
					'post_content' => (string) wp_json_encode( array( 'fontFamily' => WP_Font_Utils::sanitize_font_family( $stack ) ) ),
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		update_post_meta( $id, self::META, 1 );
		return $id;
	}

	/**
	 * Adds a face to a Font Library family, unless the same face is there.
	 *
	 * @param int          $family_id Family post.
	 * @param array<mixed> $face      Face (theme.json shape).
	 * @param string       $stored    Its file.
	 * @param string       $url       Fonts folder address.
	 * @return bool Added.
	 */
	private static function add_face( int $family_id, array $face, string $stored, string $url ): bool {
		$settings = array();
		foreach ( self::FACE_KEYS as $key ) {
			if ( isset( $face[ $key ] ) && is_scalar( $face[ $key ] ) && '' !== (string) $face[ $key ] ) {
				$settings[ $key ] = (string) $face[ $key ];
			}
		}
		$settings['fontFamily'] = WP_Font_Utils::sanitize_font_family( $settings['fontFamily'] ?? '' );
		$settings['src']        = $url . '/' . rawurlencode( $stored );
		$title                  = WP_Font_Utils::get_font_face_slug( $settings );
		$same                   = get_posts(
			array(
				'post_type'   => 'wp_font_face',
				'post_parent' => $family_id,
				'title'       => $title,
				'post_status' => 'publish',
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);
		if ( array() !== $same ) {
			return false;
		}
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'wp_font_face',
					'post_status'  => 'publish',
					'post_parent'  => $family_id,
					'post_title'   => $title,
					'post_name'    => sanitize_title( $title ),
					'post_content' => (string) wp_json_encode( $settings ),
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return false;
		}
		add_post_meta( $id, '_wp_font_face_file', $stored );
		update_post_meta( $id, self::META, 1 );
		return true;
	}

	/**
	 * Removes the faces Livery registered for these files (scope's library site), and its families left empty. Core
	 * deletes a face's file with it; callers delete the files anyway.
	 *
	 * @param array $names Stored names.
	 * @phpstan-param list<string> $names
	 * @return void
	 */
	public static function forget( array $names ): void {
		if ( array() === $names ) {
			return;
		}
		Library::on_library_site(
			static function () use ( $names ): void {
				$faces   = get_posts(
					array(
						'post_type'   => 'wp_font_face',
						'post_status' => 'any',
						'numberposts' => -1,
						'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Only when Livery deletes files.
							array(
								'key'     => '_wp_font_face_file',
								'value'   => $names,
								'compare' => 'IN',
							),
						),
					)
				);
				$parents = array();
				foreach ( $faces as $face ) {
					if ( $face instanceof WP_Post && '' !== (string) get_post_meta( $face->ID, self::META, true ) ) {
						$parents[ $face->post_parent ] = true;
						wp_delete_post( $face->ID, true );
					}
				}
				foreach ( array_keys( $parents ) as $parent ) {
					$left = get_children(
						array(
							'post_parent' => $parent,
							'post_type'   => 'wp_font_face',
							'fields'      => 'ids',
						)
					);
					if ( array() === $left && '' !== (string) get_post_meta( (int) $parent, self::META, true ) ) {
						wp_delete_post( (int) $parent, true );
					}
				}
			}
		);
	}

	/**
	 * The fonts installed in the Font Library of the scope's library site (through the Site Editor, Install Fonts or
	 * Upload), for Style Mermaid; not those Livery registered itself (Style Mermaid offers those as bundled fonts).
	 *
	 * @return list<array{id: int, slug: string, name: string, generic: string, italic: bool, weights: string, faces: list<array{id: int, fontWeight: string, fontStyle: string, unicodeRange: string, src: string}>}>
	 */
	public static function installed(): array {
		return Library::on_library_site(
			static function (): array {
				$out = array();
				foreach ( get_posts(
					array(
						'post_type'   => 'wp_font_family',
						'post_status' => 'publish',
						'numberposts' => 100,
						'orderby'     => 'title',
						'order'       => 'ASC',
					)
				) as $post ) {
					if ( ! $post instanceof WP_Post || '' !== (string) get_post_meta( $post->ID, self::META, true ) ) {
						continue;
					}
					$family = self::installed_family( $post->ID );
					if ( null !== $family && array() !== $family['faces'] ) {
						$out[] = $family;
					}
				}
				return $out;
			}
		);
	}

	/**
	 * One installed family of the scope's library site, with its faces whose files are in the fonts folder.
	 *
	 * @param int $id Family post.
	 * @return array{id: int, slug: string, name: string, generic: string, italic: bool, weights: string, faces: list<array{id: int, fontWeight: string, fontStyle: string, unicodeRange: string, src: string}>}|null
	 */
	public static function installed_family( int $id ): ?array {
		$dir = Fonts::dir();
		return Library::on_library_site(
			static function () use ( $id, $dir ): ?array {
				$post = get_post( $id );
				if ( ! $post instanceof WP_Post || 'wp_font_family' !== $post->post_type || 'publish' !== $post->post_status ) {
					return null;
				}
				$settings = json_decode( $post->post_content, true );
				$stack    = is_array( $settings ) && is_string( $settings['fontFamily'] ?? null ) ? $settings['fontFamily'] : '';
				$generic  = 'sans-serif';
				foreach ( Builder::GENERICS as $one ) {
					if ( 1 === preg_match( '/(^|,)\s*' . preg_quote( $one, '/' ) . '\s*$/', $stack ) ) {
						$generic = $one;
					}
				}
				$faces = array();
				foreach ( get_children(
					array(
						'post_parent' => $post->ID,
						'post_type'   => 'wp_font_face',
						'post_status' => 'publish',
						'orderby'     => 'ID',
						'order'       => 'ASC',
					)
				) as $face ) {
					$one = self::face( $face, $dir );
					if ( null !== $one ) {
						$faces[] = $one;
					}
				}
				$weights = array_values( array_unique( array_column( $faces, 'fontWeight' ) ) );
				return array(
					'id'      => $post->ID,
					'slug'    => $post->post_name,
					'name'    => $post->post_title,
					'generic' => $generic,
					'italic'  => in_array( 'italic', array_column( $faces, 'fontStyle' ), true ),
					'weights' => implode( ', ', $weights ),
					'faces'   => $faces,
				);
			}
		);
	}

	/**
	 * A face whose file is in the fonts folder.
	 *
	 * @param mixed                            $face Face post.
	 * @param array{path: string, url: string} $dir  Fonts folder.
	 * @return array{id: int, fontWeight: string, fontStyle: string, unicodeRange: string, src: string}|null
	 */
	private static function face( $face, array $dir ): ?array {
		if ( ! $face instanceof WP_Post ) {
			return null;
		}
		$file     = (string) get_post_meta( $face->ID, '_wp_font_face_file', true );
		$settings = json_decode( $face->post_content, true );
		if ( '' === $file || str_contains( $file, '..' ) || ! is_array( $settings ) || ! is_file( $dir['path'] . '/' . $file ) ) {
			return null;
		}
		return array(
			'id'           => $face->ID,
			'fontWeight'   => is_scalar( $settings['fontWeight'] ?? null ) ? (string) $settings['fontWeight'] : '400',
			'fontStyle'    => is_string( $settings['fontStyle'] ?? null ) ? $settings['fontStyle'] : 'normal',
			'unicodeRange' => is_string( $settings['unicodeRange'] ?? null ) ? $settings['unicodeRange'] : '',
			'src'          => $dir['url'] . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $file ) ) ),
		);
	}

	/**
	 * The file of an installed face (scope's library site), or empty.
	 *
	 * @param int $id Face post.
	 * @return string Path.
	 */
	public static function face_path( int $id ): string {
		$dir = Fonts::dir();
		return Library::on_library_site(
			static function () use ( $id, $dir ): string {
				$post = get_post( $id );
				$file = $post instanceof WP_Post && 'wp_font_face' === $post->post_type ? (string) get_post_meta( $id, '_wp_font_face_file', true ) : '';
				if ( '' === $file || str_contains( $file, '..' ) ) {
					return '';
				}
				$path = realpath( $dir['path'] . '/' . $file );
				$root = realpath( $dir['path'] );
				return false !== $path && false !== $root && str_starts_with( $path, $root . DIRECTORY_SEPARATOR ) && is_file( $path ) ? $path : '';
			}
		);
	}

	/**
	 * The address of an installed face (scope's library site), or empty.
	 *
	 * @param int $id Face post.
	 * @return string
	 */
	public static function face_url( int $id ): string {
		$dir = Fonts::dir();
		return Library::on_library_site(
			static function () use ( $id, $dir ): string {
				$one = self::face( get_post( $id ), $dir );
				return null === $one || 'wp_font_face' !== get_post_type( $id ) ? '' : $one['src'];
			}
		);
	}

	/**
	 * Before WordPress deletes a Font Library family or face that a stored variation or a site's saved styles use:
	 * refused once with a warning, then allowed for 10 minutes.
	 *
	 * @param mixed           $result  Response so far.
	 * @param mixed           $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return mixed
	 */
	public static function guard_delete( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request || 'DELETE' !== $request->get_method() ) {
			return $result;
		}
		if ( 1 !== preg_match( '#^/wp/v2/font-families/(\d+)(?:/font-faces/(\d+))?$#', $request->get_route(), $m ) ) {
			return $result;
		}
		// Only for people who may delete fonts here; anyone else gets WordPress's own answer.
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $result;
		}
		$target = ! empty( $m[2] ) ? (int) $m[2] : (int) $m[1];
		$files  = self::files_of( $target );
		if ( array() === $files ) {
			return $result;
		}
		$usage = self::usage( $files );
		if ( array() === $usage['variations'] && array() === $usage['sites'] ) {
			return $result;
		}
		$key = self::CONFIRM . get_current_user_id() . '_' . get_current_blog_id() . '_' . $target;
		if ( false !== get_transient( $key ) ) {
			delete_transient( $key );
			return $result;
		}
		set_transient( $key, 1, self::CONFIRM_TO );
		return new WP_Error( 'windrose_livery_font_in_use', ' ' . self::message( $usage ), array( 'status' => 409 ) );
	}

	/**
	 * File names of a font family's faces, or of one face (current site).
	 *
	 * @param int $id Family or face post.
	 * @return list<string>
	 */
	public static function files_of( int $id ): array {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post ) {
			return array();
		}
		$faces = 'wp_font_face' === $post->post_type
			? array( $post->ID )
			: ( 'wp_font_family' === $post->post_type
				? get_children(
					array(
						'post_parent' => $post->ID,
						'post_type'   => 'wp_font_face',
						'fields'      => 'ids',
					)
				)
				: array() );
		$files = array();
		// One query for every face's files, not one per face.
		update_postmeta_cache( array_map( 'intval', $faces ) );
		foreach ( $faces as $face ) {
			foreach ( get_post_meta( (int) $face, '_wp_font_face_file', false ) as $file ) {
				if ( is_string( $file ) && Fonts::valid_name( $file ) ) {
					$files[] = $file;
				}
			}
		}
		return array_values( array_unique( $files ) );
	}

	/**
	 * Who uses these files of the current site's fonts folder: variations stored on this site (any scope), and sites
	 * whose saved styles name them (every site of the network for the main site's fonts, this site otherwise).
	 *
	 * @param array $files File names.
	 * @phpstan-param list<string> $files
	 * @return array{variations: list<string>, sites: list<string>}
	 */
	public static function usage( array $files ): array {
		$here       = get_current_blog_id();
		$variations = Library::with_scope(
			is_multisite() && Library::network_site_id() !== $here ? $here : 0,
			static function () use ( $files ): array {
				$titles = array();
				foreach ( Library::all_on_site() as $item ) {
					if ( array() !== array_intersect( $files, array_column( $item['files'], 'stored' ) ) ) {
						$titles[] = $item['title'];
					}
				}
				return $titles;
			}
		);
		$sites      = is_multisite() && Library::network_site_id() === $here
			? get_sites(
				array(
					'network_id' => get_current_network_id(),
					'fields'     => 'ids',
					'number'     => 0,
				)
			)
			: array( $here );
		$names      = array();
		foreach ( $sites as $site ) {
			$name = Library::on_site(
				(int) $site,
				static function () use ( $files ): ?string {
					foreach ( $files as $file ) {
						if ( self::in_saved_styles( $file ) ) {
							return (string) get_bloginfo( 'name' );
						}
					}
					return null;
				}
			);
			if ( null !== $name ) {
				$names[] = $name;
			}
		}
		return array(
			'variations' => array_values( array_unique( $variations ) ),
			'sites'      => $names,
		);
	}

	/**
	 * Whether the current site's saved styles (not revisions) name a file.
	 *
	 * @param string $file File name.
	 * @return bool
	 */
	private static function in_saved_styles( string $file ): bool {
		global $wpdb;
		// The few global styles rows of a site; run only when someone deletes a font.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'wp_global_styles' AND post_status = 'publish' AND post_content LIKE %s LIMIT 1",
				'%' . $wpdb->esc_like( $file ) . '%'
			)
		);
		return null !== $found;
	}

	/**
	 * The warning.
	 *
	 * @param array{variations: list<string>, sites: list<string>} $usage Usage.
	 * @return string
	 */
	public static function message( array $usage ): string {
		$quoted = array_map(
			/* translators: %s: style variation title. */
			static fn( string $title ): string => sprintf( __( '“%s”', 'windrose-livery' ), $title ),
			$usage['variations']
		);
		$count = count( $usage['sites'] );
		if ( array() !== $quoted && $count > 0 ) {
			$used = sprintf(
				/* translators: 1: list of style variation titles, 2: number of sites. */
				_n( 'This font is used by the style variations %1$s, and by %2$d site.', 'This font is used by the style variations %1$s, and by %2$d sites.', $count, 'windrose-livery' ),
				wp_sprintf( '%l', $quoted ),
				$count
			);
		} elseif ( array() !== $quoted ) {
			/* translators: %s: list of style variation titles. */
			$used = sprintf( __( 'This font is used by the style variations %s.', 'windrose-livery' ), wp_sprintf( '%l', $quoted ) );
		} else {
			$used = sprintf(
				/* translators: 1: number of sites, 2: list of site names. */
				_n( 'This font is used by %1$d site: %2$s.', 'This font is used by %1$d sites: %2$s.', $count, 'windrose-livery' ),
				$count,
				wp_sprintf( '%l', $usage['sites'] )
			);
		}
		return $used . ' ' . __( 'Delete it again within 10 minutes to delete it anyway.', 'windrose-livery' );
	}
}
