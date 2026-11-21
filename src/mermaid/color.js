/**
 * Style Mermaid: color math with no WordPress dependency (tested with Node's test runner, tests/js).
 *
 * Colors are CSS strings (#rgb, #rrggbb, #rrggbbaa, rgb(), rgba(), transparent) or { r, g, b, a } with channels 0 to
 * 255 and alpha 0 to 1. OKLCH (Björn Ottosson's Oklab in polar form) moves lightness and chroma while keeping the
 * hue; contrast is WCAG 2.2's ratio.
 */

/**
 * Parses a CSS color.
 *
 * @param {string} value CSS color.
 * @return {?{r:number,g:number,b:number,a:number}} Color, or null.
 */
export function parse( value ) {
	if ( 'string' !== typeof value ) {
		return null;
	}
	const v = value.trim().toLowerCase();
	if ( 'transparent' === v ) {
		return { r: 0, g: 0, b: 0, a: 0 };
	}
	let m = v.match( /^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/ );
	if ( m ) {
		let hex = m[ 1 ];
		if ( hex.length <= 4 ) {
			hex = hex
				.split( '' )
				.map( ( c ) => c + c )
				.join( '' );
		}
		return {
			r: parseInt( hex.slice( 0, 2 ), 16 ),
			g: parseInt( hex.slice( 2, 4 ), 16 ),
			b: parseInt( hex.slice( 4, 6 ), 16 ),
			a: 8 === hex.length ? parseInt( hex.slice( 6, 8 ), 16 ) / 255 : 1,
		};
	}
	m = v.match(
		/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:\s*[,/]\s*([\d.]+)(%?))?\s*\)$/
	);
	if ( m ) {
		let a = 1;
		if ( undefined !== m[ 4 ] && '' !== m[ 4 ] ) {
			a = parseFloat( m[ 4 ] ) / ( '%' === m[ 5 ] ? 100 : 1 );
		}
		return {
			r: Math.min( 255, parseFloat( m[ 1 ] ) ),
			g: Math.min( 255, parseFloat( m[ 2 ] ) ),
			b: Math.min( 255, parseFloat( m[ 3 ] ) ),
			a: Math.max( 0, Math.min( 1, a ) ),
		};
	}
	return null;
}

const hex2 = ( n ) =>
	Math.max( 0, Math.min( 255, Math.round( n ) ) )
		.toString( 16 )
		.padStart( 2, '0' );

/**
 * A color as #rrggbb (or #rrggbbaa when not opaque).
 *
 * @param {{r:number,g:number,b:number,a:number}} c Color.
 * @return {string} Hex.
 */
export function toHex( c ) {
	const alpha = undefined === c.a || c.a >= 1 ? '' : hex2( c.a * 255 );
	return `#${ hex2( c.r ) }${ hex2( c.g ) }${ hex2( c.b ) }${ alpha }`;
}

/**
 * A color drawn over an opaque one.
 *
 * @param {Object} fore Foreground.
 * @param {Object} back Background (opaque).
 * @return {Object} Opaque color.
 */
export function over( fore, back ) {
	const a = fore.a ?? 1;
	return {
		r: fore.r * a + back.r * ( 1 - a ),
		g: fore.g * a + back.g * ( 1 - a ),
		b: fore.b * a + back.b * ( 1 - a ),
		a: 1,
	};
}

const toLinear = ( v ) => {
	const c = v / 255;
	return c <= 0.04045 ? c / 12.92 : ( ( c + 0.055 ) / 1.055 ) ** 2.4;
};
const fromLinear = ( c ) =>
	255 * ( c <= 0.0031308 ? 12.92 * c : 1.055 * c ** ( 1 / 2.4 ) - 0.055 );

/**
 * WCAG relative luminance.
 *
 * @param {Object} c Opaque color.
 * @return {number} Luminance.
 */
export function luminance( c ) {
	return (
		0.2126 * toLinear( c.r ) +
		0.7152 * toLinear( c.g ) +
		0.0722 * toLinear( c.b )
	);
}

/**
 * WCAG contrast ratio of two colors (the first drawn over the second, which is taken as opaque).
 *
 * @param {Object|string} fore Foreground.
 * @param {Object|string} back Background.
 * @return {number} Ratio, or 0 when a color cannot be read.
 */
export function ratio( fore, back ) {
	const b = 'string' === typeof back ? parse( back ) : back;
	let f = 'string' === typeof fore ? parse( fore ) : fore;
	if ( ! f || ! b ) {
		return 0;
	}
	const bo = { ...b, a: 1 };
	f = over( f, bo );
	const la = luminance( f );
	const lb = luminance( bo );
	return ( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 );
}

/**
 * A color in OKLCH.
 *
 * @param {Object|string} input Color.
 * @return {?{l:number,c:number,h:number,a:number}} L 0..1, chroma, hue in degrees.
 */
export function toOklch( input ) {
	const c = 'string' === typeof input ? parse( input ) : input;
	if ( ! c ) {
		return null;
	}
	const r = toLinear( c.r );
	const g = toLinear( c.g );
	const b = toLinear( c.b );
	const l = Math.cbrt(
		0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b
	);
	const m = Math.cbrt(
		0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b
	);
	const s = Math.cbrt(
		0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b
	);
	const L = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s;
	const A = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s;
	const B = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s;
	const chroma = Math.sqrt( A * A + B * B );
	let hue = ( Math.atan2( B, A ) * 180 ) / Math.PI;
	if ( hue < 0 ) {
		hue += 360;
	}
	return { l: L, c: chroma, h: chroma < 1e-4 ? 0 : hue, a: c.a ?? 1 };
}

/**
 * Linear sRGB of an OKLCH color (may be out of gamut).
 *
 * @param {Object} o OKLCH.
 * @return {number[]} Linear r, g, b.
 */
function oklchToLinear( o ) {
	const hr = ( o.h * Math.PI ) / 180;
	const A = o.c * Math.cos( hr );
	const B = o.c * Math.sin( hr );
	const l = ( o.l + 0.3963377774 * A + 0.2158037573 * B ) ** 3;
	const m = ( o.l - 0.1055613458 * A - 0.0638541728 * B ) ** 3;
	const s = ( o.l - 0.0894841775 * A - 1.291485548 * B ) ** 3;
	return [
		4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
		-1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
		-0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
	];
}

const inGamut = ( rgb ) => rgb.every( ( v ) => v >= -1e-4 && v <= 1 + 1e-4 );

/**
 * An OKLCH color as an sRGB color, lowering chroma until it fits (hue and lightness kept).
 *
 * @param {Object} o OKLCH.
 * @return {Object} Color { r, g, b, a }.
 */
export function fromOklch( o ) {
	const l = Math.max( 0, Math.min( 1, o.l ) );
	let rgb = oklchToLinear( { ...o, l } );
	if ( ! inGamut( rgb ) ) {
		let lo = 0;
		let hi = o.c;
		for ( let i = 0; i < 24; i++ ) {
			const mid = ( lo + hi ) / 2;
			if ( inGamut( oklchToLinear( { ...o, l, c: mid } ) ) ) {
				lo = mid;
			} else {
				hi = mid;
			}
		}
		rgb = oklchToLinear( { ...o, l, c: lo } );
	}
	const [ r, g, b ] = rgb.map( ( v ) =>
		fromLinear( Math.max( 0, Math.min( 1, v ) ) )
	);
	return { r, g, b, a: o.a ?? 1 };
}

/**
 * An OKLCH color as hex.
 *
 * @param {number} l Lightness 0..1.
 * @param {number} c Chroma.
 * @param {number} h Hue.
 * @param {number} a Alpha.
 * @return {string} Hex.
 */
export function oklchHex( l, c, h, a = 1 ) {
	return toHex( fromOklch( { l, c, h, a } ) );
}

/**
 * The closest color to `fore` (same hue, chroma lowered only if needed) that reaches `min` against `back`: lighter or
 * darker, whichever direction can reach it with the smaller change. Opaque result.
 *
 * @param {string} fore Foreground.
 * @param {string} back Background (opaque).
 * @param {number} min  Minimum ratio.
 * @return {string} Hex (the input made opaque when it already passes).
 */
export function fixContrast( fore, back, min ) {
	const b = parse( back );
	const f0 = parse( fore );
	if ( ! b || ! f0 ) {
		return fore;
	}
	const f = over( f0, { ...b, a: 1 } );
	if ( ratio( f, b ) >= min ) {
		return toHex( f );
	}
	const o = toOklch( f );
	const tryDir = ( dir ) => {
		// Search lightness between the color and the end (0 or 1) for the nearest that passes.
		const end = dir > 0 ? 1 : 0;
		if ( ratio( fromOklch( { ...o, l: end } ), b ) < min ) {
			return null;
		}
		let lo = o.l;
		let hi = end;
		for ( let i = 0; i < 30; i++ ) {
			const mid = ( lo + hi ) / 2;
			if ( ratio( fromOklch( { ...o, l: mid } ), b ) >= min ) {
				hi = mid;
			} else {
				lo = mid;
			}
		}
		return { l: hi, d: Math.abs( hi - o.l ) };
	};
	const up = tryDir( 1 );
	const down = tryDir( -1 );
	let best = up;
	if ( ! best || ( down && down.d < best.d ) ) {
		best = down;
	}
	if ( ! best ) {
		return luminance( b ) > 0.18 ? '#000000' : '#ffffff';
	}
	// Rounding to 8 bits can lose a hundredth: step on until it passes.
	let l = best.l;
	let hex = toHex( fromOklch( { ...o, l, a: 1 } ) );
	for ( let i = 0; i < 50 && ratio( hex, b ) < min; i++ ) {
		l += best.l > o.l ? 0.002 : -0.002;
		hex = toHex( fromOklch( { ...o, l, a: 1 } ) );
	}
	return hex;
}

/**
 * Hue distance in degrees (0..180).
 *
 * @param {number} a Hue.
 * @param {number} b Hue.
 * @return {number} Distance.
 */
export function hueDistance( a, b ) {
	const d = Math.abs( a - b ) % 360;
	return d > 180 ? 360 - d : d;
}

/**
 * Whether a palette's accent is near a chosen accent: similar hue for colorful accents, or both nearly grey.
 *
 * @param {string} accent Palette accent.
 * @param {string} chosen Chosen color.
 * @return {boolean} Near.
 */
export function accentNear( accent, chosen ) {
	const a = toOklch( accent );
	const b = toOklch( chosen );
	if ( ! a || ! b ) {
		return false;
	}
	if ( b.c < 0.04 ) {
		return a.c < 0.06;
	}
	return a.c >= 0.03 && hueDistance( a.h, b.h ) <= 30;
}
