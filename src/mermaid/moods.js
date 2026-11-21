/**
 * Style Mermaid: five moods from one accent color, computed in OKLCH (same hue, lightness and chroma chosen per role),
 * each filling Ipsum's six colors by role. Every mood is adjusted until the kit's contrast rules pass: text on Base
 * and on Surface 4.5:1, Strong Contrast as hover 4.5:1, the accent as text 4.5:1 (which is also Base on the accent,
 * for Accent sections and Bold's accent buttons).
 */
import { fixContrast, oklchHex, parse, toOklch } from './color.js';
import { ROLES } from './roles.js';

export const MOODS = [ 'calm', 'warm', 'bold', 'dark', 'contrast' ];

/**
 * A palette in Ipsum's order.
 *
 * @param {Object<string, string>} colors Slug => color, for the six roles.
 * @param {string[]}               names  Six names, in the order of ROLES.
 * @return {Object[]} Palette.
 */
export function rolePalette( colors, names ) {
	return ROLES.map( ( slug, i ) => ( {
		slug,
		name: names[ i ],
		color: colors[ slug ],
	} ) );
}

/**
 * The accent made readable as text on a background (kept as it is when it already is).
 *
 * @param {string} accent     Accent.
 * @param {string} background Background.
 * @param {number} min        Minimum ratio.
 * @return {string} Hex.
 */
function readable( accent, background, min = 4.5 ) {
	return fixContrast( accent, background, min );
}

/**
 * The five moods of an accent.
 *
 * @param {string}   accent Accent color.
 * @param {string[]} names  Role names, in the order of ROLES (Base, Contrast, Accent, Strong Contrast, Surface, Border).
 * @return {Object[]} Moods: id, palette, controlScheme, buttons ('text' or 'accent'), accent (the accent as used).
 */
export function moods( accent, names ) {
	const o = toOklch( accent );
	if ( ! o ) {
		return [];
	}
	const h = o.h;
	const c = Math.min( o.c, 0.3 );
	const out = [];

	const make = ( id, { bg, text, soft, strong, scheme, buttons } ) => {
		const acc = readable( accent, bg );
		let txt = fixContrast( text, bg, 7 );
		txt = fixContrast( txt, soft, 4.5 );
		const strongFixed = fixContrast( strong, bg, 4.5 );
		const lineAlpha = 'dark' === scheme ? '40' : '33';
		let buttonText = null;
		if ( 'accent' === buttons ) {
			// The accent as a button background with the background color as its text: the same pair patterns use
			// for Accent sections (Base on Accent, Livery 0.8), so the accent is readable both ways.
			buttonText = 'base';
		}
		const palette = rolePalette(
			{
				base: bg,
				contrast: txt,
				'accent-1': acc,
				'accent-3': strongFixed,
				'accent-5': soft,
				'accent-6': txt.slice( 0, 7 ) + lineAlpha,
			},
			names
		);
		out.push( {
			id,
			palette,
			controlScheme: scheme,
			buttons,
			buttonText,
			accent: acc,
		} );
	};

	make( 'calm', {
		bg: oklchHex( 0.985, Math.min( c, 0.012 ), h ),
		text: oklchHex( 0.3, Math.min( c, 0.04 ), h ),
		soft: oklchHex( 0.955, Math.min( c, 0.025 ), h ),
		strong: oklchHex( 0.2, Math.min( c, 0.04 ), h ),
		scheme: 'light',
		buttons: 'text',
	} );
	make( 'warm', {
		bg: oklchHex( 0.975, 0.022, 80 ),
		text: oklchHex( 0.3, 0.04, 50 ),
		soft: oklchHex( 0.94, 0.035, 75 ),
		strong: oklchHex( 0.22, 0.045, 45 ),
		scheme: 'light',
		buttons: 'text',
	} );
	make( 'bold', {
		bg: oklchHex( 0.995, 0, h ),
		text: oklchHex( 0.2, Math.min( c, 0.03 ), h ),
		soft: oklchHex( 0.95, Math.min( c, 0.05 ), h ),
		strong: oklchHex( 0.15, Math.min( c, 0.05 ), h ),
		scheme: 'light',
		buttons: 'accent',
	} );
	make( 'dark', {
		bg: oklchHex( 0.2, Math.min( c, 0.03 ), h ),
		text: oklchHex( 0.93, Math.min( c, 0.015 ), h ),
		soft: oklchHex( 0.26, Math.min( c, 0.035 ), h ),
		strong: oklchHex( 0.99, Math.min( c, 0.01 ), h ),
		scheme: 'dark',
		buttons: 'text',
	} );
	make( 'contrast', {
		bg: '#ffffff',
		text: '#0a0a0a',
		soft: oklchHex( 0.965, 0, h ),
		strong: '#000000',
		scheme: 'light',
		buttons: 'text',
	} );

	// The accent as text in High contrast: at least 7:1.
	const hc = out.find( ( m ) => 'contrast' === m.id );
	if ( hc ) {
		hc.palette[ 2 ].color = fixContrast(
			hc.palette[ 2 ].color,
			'#ffffff',
			7
		);
		hc.accent = hc.palette[ 2 ].color;
	}
	return out.filter( ( m ) => parse( m.palette[ 0 ].color ) );
}
