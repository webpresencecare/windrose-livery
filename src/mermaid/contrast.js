/**
 * Style Mermaid: the contrast checks of includes/class-contrast.php, live in the browser (same rules, same shared
 * fixtures in tests/fixtures/contrast.json). Each check also says which palette color its foreground comes from, so
 * "Fix" can change that color.
 */
import { over, parse, ratio, toHex } from './color.js';

const DEFAULTS = {
	background: 'base',
	text: 'contrast',
	link: 'contrast',
	linkHover: 'accent-3',
	buttonBg: 'contrast',
	buttonText: 'base',
	buttonHoverBg: 'accent-3',
	buttonHoverText: 'base',
};

const at = ( obj, path ) =>
	path.reduce(
		( node, key ) =>
			node && 'object' === typeof node ? node[ key ] : undefined,
		obj
	);

/**
 * The palette slug a value points to, if any.
 *
 * @param {string} value Value.
 * @return {?string} Slug.
 */
export function presetSlug( value ) {
	if ( 'string' !== typeof value ) {
		return null;
	}
	const m =
		value.match( /^var:preset\|color\|([a-z0-9-]+)$/i ) ||
		value.match( /^var\(\s*--wp--preset--color--([a-z0-9-]+)\s*\)$/i );
	return m ? m[ 1 ] : null;
}

/**
 * Runs every check.
 *
 * @param {Object} settings Settings (palette).
 * @param {Object} styles   Styles.
 * @return {Object[]} Checks: id, ratio, min, pass, warning, fg, bg, slug (the palette color to fix, if any).
 */
export function checkContrast( settings, styles ) {
	const palette = {};
	( settings?.color?.palette ?? [] ).forEach( ( c ) => {
		if ( c && 'string' === typeof c.slug && 'string' === typeof c.color ) {
			palette[ c.slug ] = c.color;
		}
	} );
	const resolve = ( value ) => {
		let v = value;
		for ( let i = 0; i < 3; i++ ) {
			const slug = presetSlug( v );
			if ( ! slug ) {
				break;
			}
			v = palette[ slug ] ?? '';
		}
		return v;
	};
	const get = ( path, fallback ) => {
		const value = at( styles, path );
		const raw =
			'string' === typeof value && '' !== value
				? value
				: `var:preset|color|${ fallback }`;
		return { color: resolve( raw ), slug: presetSlug( raw ) };
	};
	const white = { r: 255, g: 255, b: 255, a: 1 };
	const solid = ( color, page ) => {
		const c = parse( color );
		return c && page ? over( c, page ) : null;
	};

	const bgInfo = get( [ 'color', 'background' ], DEFAULTS.background );
	const bg = solid( bgInfo.color, white );
	const text = get( [ 'color', 'text' ], DEFAULTS.text );
	const softColor = palette[ 'accent-5' ];
	const soft = softColor ? solid( resolve( softColor ), bg ) : null;

	const buttonSet =
		'object' === typeof at( styles, [ 'blocks', 'core/button', 'color' ] );
	const bp = buttonSet
		? [ 'blocks', 'core/button' ]
		: [ 'elements', 'button' ];
	const btnBg = get( [ ...bp, 'color', 'background' ], DEFAULTS.buttonBg );
	const btnText = get( [ ...bp, 'color', 'text' ], DEFAULTS.buttonText );
	const btnHoverBg = get(
		[ ...bp, ':hover', 'color', 'background' ],
		DEFAULTS.buttonHoverBg
	);
	const btnHoverText = get(
		[ ...bp, ':hover', 'color', 'text' ],
		DEFAULTS.buttonHoverText
	);
	const headingRaw = at( styles, [ 'elements', 'heading', 'color', 'text' ] );
	const heading =
		'string' === typeof headingRaw && '' !== headingRaw
			? { color: resolve( headingRaw ), slug: presetSlug( headingRaw ) }
			: text;

	const pair = ( id, fg, back, min, warning = false ) => {
		const f = parse( fg.color );
		if ( ! f || ! back ) {
			return null;
		}
		const r = Math.round( ratio( f, back ) * 100 ) / 100;
		return {
			id,
			ratio: r,
			min,
			pass: r >= min,
			warning,
			fg: toHex( over( f, back ) ),
			bg: toHex( back ),
			slug: fg.slug,
		};
	};

	const checks = [
		pair( 'text', text, bg, 4.5 ),
		soft ? pair( 'text-soft', text, soft, 4.5 ) : null,
		pair(
			'link',
			get( [ 'elements', 'link', 'color', 'text' ], DEFAULTS.link ),
			bg,
			4.5
		),
		pair(
			'link-hover',
			get(
				[ 'elements', 'link', ':hover', 'color', 'text' ],
				DEFAULTS.linkHover
			),
			bg,
			4.5
		),
		pair( 'heading', heading, bg, 3 ),
		pair( 'button', btnText, solid( btnBg.color, bg ), 4.5 ),
		pair(
			'button-hover',
			btnHoverText,
			solid( btnHoverBg.color, bg ),
			4.5
		),
	];
	const caption = at( styles, [ 'elements', 'caption', 'color', 'text' ] );
	if ( 'string' === typeof caption && '' !== caption ) {
		checks.push(
			pair(
				'caption',
				{ color: resolve( caption ), slug: presetSlug( caption ) },
				bg,
				4.5
			)
		);
	}
	if ( palette[ 'accent-1' ] ) {
		checks.push(
			pair(
				'accent',
				{ color: resolve( palette[ 'accent-1' ] ), slug: 'accent-1' },
				bg,
				4.5
			)
		);
	}
	// Patterns put Background text on Accent and on Strong sections.
	const base = palette.base ? solid( resolve( palette.base ), white ) : null;
	[
		[ 'accent-surface', 'accent-1' ],
		[ 'strong', 'accent-3' ],
	].forEach( ( [ id, slug ] ) => {
		if ( base && palette[ slug ] ) {
			checks.push(
				pair(
					id,
					{ color: resolve( palette[ slug ] ), slug },
					base,
					4.5
				)
			);
		}
	} );
	return checks.filter( Boolean );
}

/**
 * Whether every check that is not a warning passes.
 *
 * @param {Object[]} checks Checks.
 * @return {boolean} Passes.
 */
export function passes( checks ) {
	return checks.every( ( c ) => c.pass || c.warning );
}
