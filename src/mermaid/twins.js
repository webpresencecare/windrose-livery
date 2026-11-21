/**
 * Light and dark twins (includes/class-schemes.php): for each colour of a style, a twin in the other scheme, kept in
 * the same palette with the slug `windrose-livery-dark-<slug>` (or `-light-`) and a name such as "Dark: Background".
 * Shared by Style Mermaid and the Site Editor's "Add a dark palette".
 *
 * Calculating them: each colour's OKLCH lightness is turned around within the palette's range (the lightest colour
 * becomes the darkest end of the other scheme, and the darkest the lightest), hue and transparency kept, chroma a
 * little lower on dark backgrounds. Then Livery's contrast checks run with the twins in place, and each twin a
 * failing check points to is moved to the nearest colour that passes (as "Fix" does), a few rounds.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	fixContrast,
	fromOklch,
	over,
	parse,
	toHex,
	toOklch,
} from './color.js';
import { checkContrast, passes, presetSlug } from './contrast.js';

export const TWIN_PREFIX = 'windrose-livery-';
const TWIN_RE = /^windrose-livery-(dark|light)-([a-z0-9-]+)$/;

/**
 * The twin slug of a colour.
 *
 * @param {string} scheme dark or light.
 * @param {string} slug   Colour slug.
 * @return {string} Slug.
 */
export const twinSlug = ( scheme, slug ) =>
	`${ TWIN_PREFIX }${ scheme }-${ slug }`;

/**
 * Whether a palette colour is a twin.
 *
 * @param {Object} color Colour.
 * @return {boolean} Twin.
 */
export const isTwin = ( color ) => TWIN_RE.test( color?.slug ?? '' );

/**
 * The twins of a palette: the other scheme and, for each colour with a twin, its twin's slug.
 *
 * @param {Object[]} palette Palette.
 * @return {{scheme: string, map: Object}} Scheme ('' without twins), base slug => twin slug.
 */
export function findTwins( palette ) {
	const slugs = new Set( ( palette ?? [] ).map( ( c ) => c.slug ) );
	const found = { dark: {}, light: {} };
	slugs.forEach( ( slug ) => {
		const m = TWIN_RE.exec( slug ?? '' );
		if ( m && slugs.has( m[ 2 ] ) && ! m[ 2 ].startsWith( TWIN_PREFIX ) ) {
			found[ m[ 1 ] ][ m[ 2 ] ] = slug;
		}
	} );
	const scheme =
		Object.keys( found.dark ).length >= Object.keys( found.light ).length
			? 'dark'
			: 'light';
	return Object.keys( found[ scheme ] ).length
		? { scheme, map: found[ scheme ] }
		: { scheme: '', map: {} };
}

/**
 * Settings with each colour that has a twin replaced by its twin (the other scheme, for checks and previews).
 *
 * @param {Object} settings Settings.
 * @return {?Object} Settings, or null without twins.
 */
export function twinSettings( settings ) {
	const palette = settings?.color?.palette ?? [];
	const { scheme, map } = findTwins( palette );
	if ( ! scheme ) {
		return null;
	}
	const value = Object.fromEntries(
		palette.map( ( c ) => [ c.slug, c.color ] )
	);
	return {
		...settings,
		color: {
			...settings.color,
			palette: palette.map( ( c ) =>
				map[ c.slug ] ? { ...c, color: value[ map[ c.slug ] ] } : c
			),
		},
	};
}

/**
 * The contrast checks of the other scheme.
 *
 * @param {Object} settings Settings.
 * @param {Object} styles   Styles.
 * @return {?Object[]} Checks, or null without twins.
 */
export function twinChecks( settings, styles ) {
	const twin = twinSettings( settings );
	return twin ? checkContrast( twin, styles ) : null;
}

/**
 * The scheme of a style's own colours, from its page background.
 *
 * @param {Object} settings Settings.
 * @param {Object} styles   Styles.
 * @return {string} dark or light.
 */
export function ownScheme( settings, styles ) {
	const palette = Object.fromEntries(
		( settings?.color?.palette ?? [] ).map( ( c ) => [ c.slug, c.color ] )
	);
	let value = styles?.color?.background || 'var:preset|color|base';
	for ( let i = 0; i < 3; i++ ) {
		const slug = presetSlug( value );
		if ( ! slug ) {
			break;
		}
		value = palette[ slug ] ?? '';
	}
	const color = parse( value );
	const back = color
		? over( color, { r: 255, g: 255, b: 255, a: 1 } )
		: { r: 255, g: 255, b: 255, a: 1 };
	const o = toOklch( back );
	return o && o.l < 0.5 ? 'dark' : 'light';
}

/**
 * A palette without twins.
 *
 * @param {Object[]} palette Palette.
 * @return {Object[]} Palette.
 */
export const withoutTwins = ( palette ) =>
	( palette ?? [] ).filter( ( c ) => ! isTwin( c ) );

/**
 * A twin's name.
 *
 * @param {string} scheme dark or light.
 * @param {string} name   The colour's name.
 * @return {string} Name.
 */
export function twinName( scheme, name ) {
	return 'dark' === scheme
		? sprintf(
				/* translators: %s: colour name. */
				__( 'Dark: %s', 'windrose-livery' ),
				name
			)
		: sprintf(
				/* translators: %s: colour name. */
				__( 'Light: %s', 'windrose-livery' ),
				name
			);
}

/**
 * Calculates twins for a style and fixes those that fail a contrast check.
 *
 * @param {Object} settings Settings (palette without or with twins: old twins are replaced).
 * @param {Object} styles   Styles.
 * @return {{scheme: string, own: string, twins: Object[], palette: Object[], checks: Object[], passes: boolean}} Result.
 */
export function makeTwins( settings, styles ) {
	const base = withoutTwins( settings?.color?.palette );
	const own = ownScheme(
		{ ...settings, color: { ...settings?.color, palette: base } },
		styles
	);
	const scheme = 'dark' === own ? 'light' : 'dark';
	const lights = base
		.map( ( c ) => toOklch( c.color )?.l )
		.filter( ( l ) => 'number' === typeof l );
	const min = lights.length ? Math.min( ...lights ) : 0;
	const max = lights.length ? Math.max( ...lights ) : 1;
	const span = max - min > 0.05 ? max - min : 1;
	// The other scheme's ends: a deep background and light text, or a near-white background and dark text.
	const [ lo, hi ] = 'dark' === scheme ? [ 0.2, 0.95 ] : [ 0.2, 0.985 ];

	const twins = base.map( ( c ) => {
		const o = toOklch( c.color );
		let color = c.color;
		if ( o ) {
			const t = ( o.l - min ) / span;
			const l = hi - t * ( hi - lo );
			const chroma = 'dark' === scheme && l < 0.35 ? o.c * 0.6 : o.c;
			color = toHex( fromOklch( { l, c: chroma, h: o.h, a: o.a } ) );
		}
		return {
			slug: twinSlug( scheme, c.slug ),
			name: twinName( scheme, c.name ?? c.slug ),
			color,
		};
	} );

	const paletteOf = ( list ) => [ ...base, ...list ];
	// The page background is never moved to fix another pair (it would move every pair).
	const bgSlug = presetSlug( styles?.color?.background ) || 'base';
	let current = twins;
	let checks = [];
	for ( let round = 0; round < 4; round++ ) {
		const twin = twinSettings( {
			...settings,
			color: { ...settings?.color, palette: paletteOf( current ) },
		} );
		checks = checkContrast( twin, styles );
		const failing = checks.filter(
			( c ) => ! c.pass && ! c.warning && c.slug && c.slug !== bgSlug
		);
		if ( ! failing.length ) {
			break;
		}
		current = current.map( ( t ) => {
			const check = failing.find(
				( c ) => twinSlug( scheme, c.slug ) === t.slug
			);
			return check
				? { ...t, color: fixContrast( t.color, check.bg, check.min ) }
				: t;
		} );
	}
	return {
		scheme,
		own,
		twins: current,
		palette: paletteOf( current ),
		checks,
		passes: passes( checks ),
	};
}
