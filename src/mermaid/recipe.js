/**
 * Style Mermaid: the style being made, and the recipe sent to the server. No WordPress dependency (tested with Node).
 *
 * State: { base, title, description, settings, styles, fonts, blocks }
 * - settings, styles: theme.json shape; font references by role: `var:preset|font-family|@heading`, `@text`, `@labels`.
 * - fonts: { heading: Ref, text: Ref, labels: 'manrope' | 'heading' | 'text',
 *   scripts: { <slug>: { heading, text, size, languages: { <tag>: Ref } } } }
 *   with Ref = { source: 'bundled', slug }, { source: 'remote', key, slug, name, generic, covers }
 *   or { source: 'pack', pack, slug, name, generic } (a font of an installed font pack).
 * - blocks: per-block looks, entry id => choices (missing: as the style has it; see blocks.js).
 */
import { applyBlocks } from './blocks.js';

export const FONT_PREFIX = 'var:preset|font-family|';

/**
 * Blocks Ipsum sets in Manrope ("labels": menus, dates, buttons, pagination...); Style Mermaid sets them all to the
 * labels font. From the design kit (reference/manrope-spots.md).
 */
export const LABEL_BLOCKS = [
	'core/accordion-heading',
	'core/comment-author-name',
	'core/comment-date',
	'core/comment-edit-link',
	'core/comment-reply-link',
	'core/comments-pagination',
	'core/post-author-name',
	'core/post-comments-link',
	'core/post-date',
	'core/post-navigation-link',
	'core/post-terms',
	'core/post-time-to-read',
	'core/query-no-results',
	'core/query-pagination',
	'core/read-more',
	'core/search',
	'core/site-tagline',
	'core/tab-list',
	'core/navigation',
];

/** Blocks that take the heading font. */
export const HEADING_BLOCKS = [
	'core/site-title',
	'core/query-title',
	'core/comments-title',
];

/** Text widths and wide widths offered first. */
export const CONTENT_SIZES = {
	narrow: '640px',
	medium: '860px',
	wide: '980px',
};
export const WIDE_SIZES = {
	small: '1080px',
	medium: '1280px',
	large: '1440px',
};

/** Ipsum's spacing sizes (theme.json), scaled by the spacing choice. */
export const IPSUM_SPACING = [
	{ name: '2X-Small', slug: '20', size: '8px' },
	{ name: 'X-Small', slug: '30', size: '16px' },
	{ name: 'Small', slug: '40', size: 'clamp(24px, 2.33vw, 32px)' },
	{ name: 'Regular', slug: '50', size: 'clamp(32px, 3.49vw, 48px)' },
	{ name: 'Large', slug: '60', size: 'clamp(48px, 4.65vw, 64px)' },
	{ name: 'X-Large', slug: '70', size: 'clamp(64px, 6.98vw, 96px)' },
	{ name: '2X-Large', slug: '80', size: 'clamp(96px, 9.31vw, 128px)' },
];
export const SPACING_SCALES = { compact: 0.75, ipsum: 1, airy: 1.25 };

/** Ipsum's font sizes (theme.json). */
export const IPSUM_FONT_SIZES = [
	{ name: 'Small', slug: 'small', size: '0.875rem' },
	{ name: 'Medium', slug: 'medium', size: '1.125rem' },
	{ name: 'Large', slug: 'large', size: '1.4375rem' },
	{ name: 'X-Large', slug: 'x-large', size: '1.75rem' },
	{ name: '2X-Large', slug: '2-x-large', size: '2.1875rem' },
];

/** Ipsum's gradients (theme.json), used when a style has none of its own. */
export const IPSUM_GRADIENTS = [
	{
		name: 'Vertical soft wash to base',
		slug: 'gradient-1',
		gradient:
			'linear-gradient(to bottom, var(--wp--preset--color--accent-5) 0%, var(--wp--preset--color--base) 100%)',
	},
	{
		name: 'Vertical hard wash to base',
		slug: 'gradient-2',
		gradient:
			'linear-gradient(to bottom, var(--wp--preset--color--accent-5) 90%, var(--wp--preset--color--base) 90%)',
	},
];

export const clone = ( value ) =>
	undefined === value ? undefined : JSON.parse( JSON.stringify( value ) );

/**
 * A value at a path.
 *
 * @param {Object}   obj  Object.
 * @param {string[]} path Path.
 * @return {unknown} Value.
 */
export function getAt( obj, path ) {
	return path.reduce(
		( node, key ) =>
			node && 'object' === typeof node ? node[ key ] : undefined,
		obj
	);
}

/**
 * A copy of obj with a value set at a path (undefined or '' removes it, and empty parents go too).
 *
 * @param {Object}   obj   Object.
 * @param {string[]} path  Path.
 * @param {unknown}  value Value.
 * @return {Object} New object.
 */
export function setAt( obj, path, value ) {
	const root = clone( obj ) ?? {};
	const stack = [ root ];
	let node = root;
	for ( let i = 0; i < path.length - 1; i++ ) {
		if ( ! node[ path[ i ] ] || 'object' !== typeof node[ path[ i ] ] ) {
			node[ path[ i ] ] = {};
		}
		node = node[ path[ i ] ];
		stack.push( node );
	}
	const last = path[ path.length - 1 ];
	if ( undefined === value || '' === value || null === value ) {
		delete node[ last ];
		for ( let i = path.length - 2; i >= 0; i-- ) {
			if ( 0 === Object.keys( stack[ i + 1 ] ).length ) {
				delete stack[ i ][ path[ i ] ];
			} else {
				break;
			}
		}
	} else {
		node[ last ] = value;
	}
	return root;
}

/**
 * A new state from a ready style.
 *
 * @param {Object} style Ready style (assets/mermaid/styles.json).
 * @return {Object} State.
 */
export function fromStyle( style ) {
	return {
		base: style.slug,
		title: '',
		description: '',
		settings: clone( style.settings ) ?? {},
		styles: clone( style.styles ) ?? {},
		fonts: {
			heading: { source: 'bundled', slug: style.fonts.heading },
			text: { source: 'bundled', slug: style.fonts.text },
			labels: style.fonts.labels,
			scripts: {},
		},
		blocks: {},
		missing: clone( style.missing ) ?? {},
	};
}

/**
 * The palette of a state, as slug => color.
 *
 * @param {Object} settings Settings.
 * @return {Object} Map.
 */
export function paletteMap( settings ) {
	const map = {};
	( settings?.color?.palette ?? [] ).forEach( ( c ) => {
		map[ c.slug ] = c.color;
	} );
	return map;
}

/**
 * Ipsum's spacing sizes scaled (numbers in px and vw multiplied, rounded to two decimals).
 *
 * @param {number} factor Factor.
 * @return {Object[]} Spacing sizes.
 */
export function scaledSpacing( factor ) {
	const round = ( n ) => Math.round( n * 100 ) / 100;
	return IPSUM_SPACING.map( ( s ) => ( {
		...s,
		size: s.size.replace(
			/([\d.]+)(px|vw)/g,
			( m, n, unit ) => `${ round( parseFloat( n ) * factor ) }${ unit }`
		),
	} ) );
}

/**
 * The spacing scale a state uses.
 *
 * @param {Object} settings Settings.
 * @return {string} compact, ipsum, airy or custom.
 */
export function spacingScale( settings ) {
	const sizes = settings?.spacing?.spacingSizes;
	if ( ! sizes ) {
		return 'ipsum';
	}
	for ( const [ id, factor ] of Object.entries( SPACING_SCALES ) ) {
		if (
			JSON.stringify( scaledSpacing( factor ) ) ===
			JSON.stringify( sizes )
		) {
			return id;
		}
	}
	return 'custom';
}

/**
 * The slug a font choice gets in the variation.
 *
 * @param {Object} ref Font choice.
 * @return {string} Slug.
 */
export function refSlug( ref ) {
	return ref?.slug ?? '';
}

/**
 * Replaces role placeholders by family slugs, everywhere in a node.
 *
 * @param {unknown} node  Node.
 * @param {Object}  roles Role => slug.
 * @return {unknown} Node.
 */
function resolveRoles( node, roles ) {
	if ( Array.isArray( node ) ) {
		return node.map( ( n ) => resolveRoles( n, roles ) );
	}
	if ( node && 'object' === typeof node ) {
		const out = {};
		Object.entries( node ).forEach( ( [ key, value ] ) => {
			out[ key ] = resolveRoles( value, roles );
		} );
		return out;
	}
	if ( 'string' === typeof node && node.startsWith( FONT_PREFIX + '@' ) ) {
		return (
			FONT_PREFIX +
			( roles[ node.slice( FONT_PREFIX.length + 1 ) ] ?? 'manrope' )
		);
	}
	return node;
}

/**
 * Sets the labels and heading fonts on the blocks Ipsum styles by hand.
 *
 * @param {Object} styles Styles (placeholders).
 * @return {Object} Styles.
 */
function setBlockFonts( styles ) {
	let out = styles;
	const labels = FONT_PREFIX + '@labels';
	LABEL_BLOCKS.forEach( ( block ) => {
		out = setAt(
			out,
			[ 'blocks', block, 'typography', 'fontFamily' ],
			labels
		);
	} );
	out = setAt(
		out,
		[
			'blocks',
			'core/search',
			'elements',
			'button',
			'typography',
			'fontFamily',
		],
		labels
	);
	[ 'core/quote', 'core/pullquote' ].forEach( ( block ) => {
		out = setAt(
			out,
			[ 'blocks', block, 'elements', 'cite', 'typography', 'fontFamily' ],
			labels
		);
	} );
	out = setAt(
		out,
		[ 'elements', 'button', 'typography', 'fontFamily' ],
		labels
	);
	out = setAt(
		out,
		[ 'blocks', 'core/button', 'typography', 'fontFamily' ],
		labels
	);
	HEADING_BLOCKS.forEach( ( block ) => {
		out = setAt(
			out,
			[ 'blocks', block, 'typography', 'fontFamily' ],
			FONT_PREFIX + '@heading'
		);
	} );
	return out;
}

/**
 * The recipe the server builds the variation from.
 *
 * @param {Object} state    State.
 * @param {string} fallback Title when none was given.
 * @return {Object} Recipe.
 */
export function toRecipe( state, fallback = 'My style' ) {
	const heading = refSlug( state.fonts.heading );
	const text = refSlug( state.fonts.text );
	const labelsBy = { manrope: 'manrope', heading, text };
	const roles = {
		heading,
		text,
		labels: labelsBy[ state.fonts.labels ] ?? 'manrope',
	};
	const styles = applyBlocks(
		setBlockFonts( state.styles ?? {} ),
		state.blocks,
		paletteMap( state.settings )
	);
	const strip = ( ref ) => {
		if ( 'remote' === ref?.source ) {
			return { source: 'remote', key: ref.key, generic: ref.generic };
		}
		if ( 'pack' === ref?.source ) {
			return { source: 'pack', pack: ref.pack, slug: ref.slug };
		}
		if ( 'library' === ref?.source ) {
			return { source: 'library', id: ref.id };
		}
		return { source: 'bundled', slug: ref?.slug };
	};
	const scripts = {};
	Object.entries( state.fonts.scripts ?? {} ).forEach( ( [ slug, pair ] ) => {
		if ( pair?.heading && pair?.text ) {
			const languages = {};
			Object.entries( pair.languages ?? {} ).forEach(
				( [ tag, ref ] ) => {
					if ( ref ) {
						languages[ tag ] = strip( ref );
					}
				}
			);
			scripts[ slug ] = {
				heading: strip( pair.heading ),
				text: strip( pair.text ),
				size: pair.size ?? 100,
				languages,
			};
		}
	} );
	return {
		title: ( state.title ?? '' ).trim() || fallback,
		description: ( state.description ?? '' ).trim(),
		settings: state.settings,
		styles: resolveRoles( styles, roles ),
		fonts: {
			heading: strip( state.fonts.heading ),
			text: strip( state.fonts.text ),
			scripts,
		},
	};
}
