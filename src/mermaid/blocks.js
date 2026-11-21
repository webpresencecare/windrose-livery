/**
 * Style Mermaid: per-block looks (0.2 had the button; 0.5 the curated list below). No WordPress dependency (tested
 * with Node).
 *
 * Each entry offers a few named choices and writes them as plain theme.json styles, which the Site Editor then shows
 * and edits in Styles, Blocks: Style Mermaid makes a ready style, the Site Editor fine-tunes it. Values come
 * from the style's own presets (palette, spacing sizes, Ipsum's shadow), so light and dark twins follow them.
 *
 * - button: shape, fill, color, hover color, size. Written both on the button element and on the Buttons block (Ipsum gives the
 *   block its own radius, borders and colors, which win over the element), with the block's Outline style matched.
 *   Focus and pressed states take the hover and the normal colors (Ipsum fixes them to the Text color); the Outline
 *   style shares them (core keeps no states inside a block style variation).
 * - corners: one choice for every block with a box or a picture (CORNER_BLOCKS).
 * - images: frame (none, a line, a shadow) on images and featured images.
 * - quotes: soft background, lines above and below, or plain, on quotes and pullquotes. No line on one side only:
 *   theme.json writes left and right, which would be the wrong side on right-to-left pages.
 * - separator: color and thickness.
 * - menu: case, weight, space between items.
 * - code: a line around it or the soft background.
 *
 * Rule: only looks WordPress stores as settings, never custom CSS. WordPress keeps custom CSS
 * only for people who may edit CSS (on a network: network administrators), so a look written as CSS would be lost for
 * a site administrator who imports the style. Lists therefore keep Ipsum's look (their markers have no setting).
 *
 * Every color pair a look uses is one the contrast checks already cover: text on the page or the soft background,
 * button colors, links. Separators are decoration.
 */
import { ratio } from './color.js';

const ref = ( slug ) => `var:preset|color|${ slug }`;
const space = ( slug ) => `var:preset|spacing|${ slug }`;

const sides = ( border ) => ( {
	top: { ...border },
	right: { ...border },
	bottom: { ...border },
	left: { ...border },
} );

const corners = ( radius ) => ( {
	topLeft: radius,
	topRight: radius,
	bottomLeft: radius,
	bottomRight: radius,
} );

/**
 * A node without its hover, focus and pressed states.
 *
 * @param {Object} node Node.
 * @return {Object} Copy.
 */
function withoutStates( node ) {
	const out = { ...node };
	[ ':hover', ':focus', ':focus-visible', ':active' ].forEach(
		( key ) => delete out[ key ]
	);
	return out;
}

const copy = ( styles ) => JSON.parse( JSON.stringify( styles ?? {} ) );

/**
 * The styles of one block, created when missing.
 *
 * @param {Object} styles Styles (changed).
 * @param {string} name   Block name.
 * @return {Object} The block's node.
 */
function blockNode( styles, name ) {
	styles.blocks = styles.blocks ?? {};
	styles.blocks[ name ] = styles.blocks[ name ] ?? {};
	return styles.blocks[ name ];
}

/**
 * The palette color that reads best on a background color.
 *
 * @param {Object} palette Slug => color.
 * @param {string} slug    Background slug.
 * @return {string} Slug.
 */
export function bestTextOn( palette, slug ) {
	const back = palette[ slug ];
	let best = 'base';
	let bestRatio = -1;
	[ 'base', 'contrast', 'accent-3' ].forEach( ( candidate ) => {
		if ( candidate === slug || ! palette[ candidate ] || ! back ) {
			return;
		}
		const r = ratio( palette[ candidate ], back );
		if ( r > bestRatio ) {
			bestRatio = r;
			best = candidate;
		}
	} );
	return best;
}

export const BUTTON_SHAPES = { square: '0', rounded: '8px', pill: '100px' };
export const BUTTON_COLORS = {
	text: 'contrast',
	accent: 'accent-1',
	strong: 'accent-3',
};
/** Padding by size: [ top and bottom, left and right ] spacing slugs (Ipsum's own is medium). */
export const BUTTON_SIZES = {
	small: [ '20', '30' ],
	medium: [ '30', '40' ],
	large: [ '40', '50' ],
};

export const CORNER_RADII = { square: '0', soft: '6px', round: '16px' };
/** Blocks the Corners choice changes. */
export const CORNER_BLOCKS = [
	'core/image',
	'core/post-featured-image',
	'core/cover',
	'core/quote',
	'core/pullquote',
	'core/code',
];

export const SEPARATOR_COLORS = {
	lines: 'accent-6',
	accent: 'accent-1',
	text: 'contrast',
};
export const SEPARATOR_WIDTHS = { thin: '0 0 1px 0', thick: '0 0 3px 0' };

export const MENU_GAPS = { small: '30', medium: '40', large: '50' };

/**
 * Writes the button choices into styles.
 *
 * @param {Object} styles  Styles.
 * @param {Object} value   { shape, fill, color, size }.
 * @param {Object} palette Slug => color.
 * @return {Object} Styles.
 */
function applyButton( styles, value, palette ) {
	const out = copy( styles );
	const radius = BUTTON_SHAPES[ value.shape ] ?? '8px';
	const main = BUTTON_COLORS[ value.color ] ?? 'contrast';
	const onMain = bestTextOn( palette, main );
	// Hover color: chosen (0.5), or as before (Strong, or Text color on a Strong button).
	const hoverBg =
		BUTTON_COLORS[ value.hover ] ??
		( 'accent-3' === main ? 'contrast' : 'accent-3' );
	const onHover = bestTextOn( palette, hoverBg );
	const outlined = 'outlined' === value.fill;
	const [ vertical, horizontal ] =
		BUTTON_SIZES[ value.size ] ?? BUTTON_SIZES.medium;
	const padding = {
		top: space( vertical ),
		bottom: space( vertical ),
		left: space( horizontal ),
		right: space( horizontal ),
	};

	const filled = { background: ref( main ), text: ref( onMain ) };
	const color = outlined
		? { background: 'transparent', text: ref( main ) }
		: filled;
	// On hover a button fills with the hover color, outlined ones too.
	const hoverColor = { background: ref( hoverBg ), text: ref( onHover ) };
	// Focus looks like hover, pressed like the button itself (both checked for contrast).
	const states = ( node, normal ) => ( {
		':hover': { ...( node[ ':hover' ] ?? {} ), color: hoverColor },
		':focus': { ...( node[ ':focus' ] ?? {} ), color: hoverColor },
		':active': { ...( node[ ':active' ] ?? {} ), color: normal },
	} );
	const borderColor = outlined ? ref( main ) : 'transparent';

	out.elements = out.elements ?? {};
	const element = out.elements.button ?? {};
	out.elements.button = {
		...element,
		color,
		...states( element, color ),
		border: {
			radius,
			width: '1px',
			style: 'solid',
			color: borderColor,
		},
		spacing: { ...( element.spacing ?? {} ), padding },
	};
	out.blocks = out.blocks ?? {};
	const block = out.blocks[ 'core/button' ] ?? {};
	// Core (7.2) keeps no hover, focus or pressed states inside a block style variation: the Outline style takes
	// the button's own, set above.
	const outline = block.variations?.outline ?? {};
	const outlineColor = { background: 'transparent', text: ref( main ) };
	out.blocks[ 'core/button' ] = {
		...block,
		color,
		...states( block, color ),
		border: {
			...sides( {
				color: borderColor,
				style: 'solid',
				width: '1px',
			} ),
			radius: corners( radius ),
		},
		spacing: { ...( block.spacing ?? {} ), padding },
		variations: {
			...( block.variations ?? {} ),
			outline: {
				...withoutStates( outline ),
				color: outlineColor,
				border: sides( {
					color: ref( main ),
					style: 'solid',
					width: '1px',
				} ),
				spacing: { ...( outline.spacing ?? {} ), padding },
			},
		},
	};
	const search = out.blocks[ 'core/search' ] ?? {};
	out.blocks[ 'core/search' ] = {
		...search,
		elements: {
			...( search.elements ?? {} ),
			button: {
				...( search.elements?.button ?? {} ),
				border: { radius },
			},
		},
	};
	return out;
}

/**
 * Sets a block's border radius (all four corners).
 *
 * @param {Object} node   Block node (changed).
 * @param {string} radius Radius.
 */
function setRadius( node, radius ) {
	node.border = { ...( node.border ?? {} ), radius: corners( radius ) };
}

export const BLOCK_STYLES = {
	button: {
		defaults: {
			shape: 'rounded',
			fill: 'filled',
			color: 'text',
			hover: 'strong',
			size: 'medium',
		},
		blocks: [ 'core/button', 'core/search' ],
		focus: '.wp-block-buttons',
		apply: applyButton,
	},
	corners: {
		defaults: { radius: 'soft' },
		blocks: CORNER_BLOCKS,
		focus: '.wp-block-image',
		apply( styles, value ) {
			const out = copy( styles );
			const radius = CORNER_RADII[ value.radius ] ?? CORNER_RADII.soft;
			CORNER_BLOCKS.forEach( ( name ) =>
				setRadius( blockNode( out, name ), radius )
			);
			return out;
		},
	},
	images: {
		defaults: { frame: 'none' },
		blocks: [ 'core/image', 'core/post-featured-image' ],
		focus: '.wp-block-image',
		apply( styles, value ) {
			const out = copy( styles );
			[ 'core/image', 'core/post-featured-image' ].forEach( ( name ) => {
				const node = blockNode( out, name );
				const border = { ...( node.border ?? {} ) };
				delete border.top;
				delete border.right;
				delete border.bottom;
				delete border.left;
				if ( 'line' === value.frame ) {
					border.color = ref( 'accent-6' );
					border.style = 'solid';
					border.width = '1px';
				} else {
					border.width = '0';
					border.style = 'none';
					delete border.color;
				}
				node.border = border;
				node.shadow =
					'shadow' === value.frame
						? 'var:preset|shadow|soft'
						: 'none';
			} );
			return out;
		},
	},
	quotes: {
		defaults: { look: 'soft' },
		blocks: [ 'core/quote', 'core/pullquote' ],
		focus: '.wp-block-quote',
		apply( styles, value ) {
			const out = copy( styles );
			[ 'core/quote', 'core/pullquote' ].forEach( ( name ) => {
				const node = blockNode( out, name );
				const radius = node.border?.radius;
				if ( 'soft' === value.look ) {
					node.color = {
						...( node.color ?? {} ),
						background: ref( 'accent-5' ),
					};
					node.border = sides( {
						style: 'none',
						width: '0',
					} );
				} else {
					node.color = {
						...( node.color ?? {} ),
						background: 'transparent',
					};
					const line =
						'lines' === value.look
							? {
									color: ref( 'accent-6' ),
									style: 'solid',
									width: '1px',
								}
							: { style: 'none', width: '0' };
					node.border = {
						top: { ...line },
						bottom: { ...line },
						left: { style: 'none', width: '0' },
						right: { style: 'none', width: '0' },
					};
				}
				// Lines and plain have no box, so no rounded corners; soft keeps the Corners choice.
				if ( 'soft' === value.look && radius ) {
					node.border.radius = radius;
				} else if ( 'soft' !== value.look ) {
					node.border.radius = corners( '0' );
				}
			} );
			return out;
		},
	},
	separator: {
		defaults: { color: 'lines', thickness: 'thin' },
		blocks: [ 'core/separator' ],
		focus: '.wp-block-separator',
		apply( styles, value ) {
			const out = copy( styles );
			const node = blockNode( out, 'core/separator' );
			node.color = {
				...( node.color ?? {} ),
				text: ref( SEPARATOR_COLORS[ value.color ] ?? 'accent-6' ),
			};
			node.border = {
				...( node.border ?? {} ),
				color: 'currentColor',
				style: 'solid',
				width: SEPARATOR_WIDTHS[ value.thickness ] ?? '0 0 1px 0',
			};
			return out;
		},
	},
	menu: {
		defaults: { case: 'as-written', weight: 'regular', gap: 'medium' },
		blocks: [ 'core/navigation' ],
		focus: '.wp-block-navigation',
		apply( styles, value ) {
			const out = copy( styles );
			const node = blockNode( out, 'core/navigation' );
			const capitals = 'capitals' === value.case;
			node.typography = {
				...( node.typography ?? {} ),
				textTransform: capitals ? 'uppercase' : 'none',
				letterSpacing: capitals ? '0.06em' : '0',
				fontWeight: 'bold' === value.weight ? '700' : '500',
			};
			node.spacing = {
				...( node.spacing ?? {} ),
				blockGap: space( MENU_GAPS[ value.gap ] ?? '40' ),
			};
			return out;
		},
	},
	code: {
		defaults: { look: 'line' },
		blocks: [ 'core/code' ],
		focus: '.wp-block-code',
		apply( styles, value ) {
			const out = copy( styles );
			const node = blockNode( out, 'core/code' );
			const radius = node.border?.radius;
			if ( 'soft' === value.look ) {
				node.color = {
					...( node.color ?? {} ),
					background: ref( 'accent-5' ),
				};
				node.border = {
					color: 'transparent',
					style: 'solid',
					width: '1px',
				};
			} else {
				node.color = {
					...( node.color ?? {} ),
					background: 'transparent',
				};
				node.border = {
					color: ref( 'accent-6' ),
					style: 'solid',
					width: '1px',
				};
			}
			if ( radius ) {
				node.border.radius = radius;
			}
			return out;
		},
	},
};

/** The order entries are shown and applied in (corners before the looks that keep or reset the radius). */
export const BLOCK_ORDER = [
	'button',
	'corners',
	'images',
	'quotes',
	'separator',
	'menu',
	'code',
];

/**
 * Applies every chosen look to styles.
 *
 * @param {Object} styles  Styles.
 * @param {Object} blocks  Entry id => choices (missing or null: as the style has it).
 * @param {Object} palette Slug => color.
 * @return {Object} Styles.
 */
export function applyBlocks( styles, blocks, palette ) {
	let out = styles;
	BLOCK_ORDER.forEach( ( id ) => {
		if ( blocks?.[ id ] ) {
			out = BLOCK_STYLES[ id ].apply( out, blocks[ id ], palette );
		}
	} );
	return out;
}
