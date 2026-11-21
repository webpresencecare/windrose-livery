/**
 * Style Mermaid color math (node --test tests/js).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
	accentNear,
	fixContrast,
	fromOklch,
	parse,
	ratio,
	toHex,
	toOklch,
} from '../../src/mermaid/color.js';
import { checkContrast, passes } from '../../src/mermaid/contrast.js';
import { moods } from '../../src/mermaid/moods.js';

const NAMES = [ 'Background', 'Text', 'Accent', 'Lines', 'Soft background', 'Strong' ];

test( 'parses hex, short hex, alpha and rgb()', () => {
	assert.deepEqual( parse( '#fff' ), { r: 255, g: 255, b: 255, a: 1 } );
	assert.equal( parse( '#00000080' ).a.toFixed( 2 ), '0.50' );
	assert.deepEqual( parse( 'rgb(10, 20, 30)' ), { r: 10, g: 20, b: 30, a: 1 } );
	assert.equal( parse( 'rgba(0 0 0 / 50%)' ).a, 0.5 );
	assert.equal( parse( 'var(--x)' ), null );
} );

test( 'WCAG ratios: black on white is 21, same color is 1', () => {
	assert.equal( Math.round( ratio( '#000', '#fff' ) * 100 ) / 100, 21 );
	assert.equal( ratio( '#777', '#777' ), 1 );
	// #767676 on white is the classic 4.54.
	assert.equal( Math.round( ratio( '#767676', '#ffffff' ) * 100 ) / 100, 4.54 );
} );

test( 'OKLCH round trip keeps the color', () => {
	for ( const hex of [ '#3355ff', '#b02a1e', '#13294b', '#f7f9fc', '#ffff66' ] ) {
		assert.equal( toHex( fromOklch( toOklch( hex ) ) ), hex );
	}
} );

test( 'fixContrast reaches the minimum and keeps the hue', () => {
	const fixed = fixContrast( '#ff8c52', '#ffffff', 4.5 );
	assert.ok( ratio( fixed, '#ffffff' ) >= 4.5, fixed );
	assert.ok( Math.abs( toOklch( fixed ).h - toOklch( '#ff8c52' ).h ) < 6 );
	assert.equal( fixContrast( '#000000', '#ffffff', 4.5 ), '#000000' );
	const light = fixContrast( '#3355ff', '#101820', 4.5 );
	assert.ok( ratio( light, '#101820' ) >= 4.5 );
} );

test( 'accent filter: similar hues, greys with greys', () => {
	assert.ok( accentNear( '#b02a1e', '#d03020' ) );
	assert.ok( ! accentNear( '#b02a1e', '#3355ff' ) );
	assert.ok( accentNear( '#555555', '#777777' ) );
} );

test( 'every mood of many accents passes the contrast checks', () => {
	const accents = [ '#3355ff', '#ff8c52', '#ffff00', '#00c2a8', '#e026a0', '#777777', '#000000', '#ffffff', '#7a1000', '#47730a' ];
	for ( const accent of accents ) {
		const list = moods( accent, NAMES );
		assert.equal( list.length, 5 );
		for ( const mood of list ) {
			const styles = 'accent' === mood.buttons
				? { elements: { button: { color: { background: 'var:preset|color|accent-1', text: `var:preset|color|${ mood.buttonText }` }, ':hover': { color: { background: 'var:preset|color|accent-3', text: 'var:preset|color|base' } } } } }
				: {};
			const checks = checkContrast( { color: { palette: mood.palette } }, styles );
			assert.ok( passes( checks ), `${ accent } ${ mood.id }: ${ JSON.stringify( checks.filter( ( c ) => ! c.pass && ! c.warning ) ) }` );
			for ( const id of [ 'accent', 'accent-surface', 'strong' ] ) {
				assert.ok( checks.find( ( c ) => id === c.id ).pass, `${ accent } ${ mood.id } ${ id }` );
			}
		}
	}
} );

test( 'shared fixtures give the same results as PHP', () => {
	const fixtures = JSON.parse( readFileSync( new URL( '../fixtures/contrast.json', import.meta.url ) ) );
	for ( const fixture of fixtures ) {
		const checks = checkContrast( fixture.settings, fixture.styles ).map( ( c ) => [ c.id, c.ratio, c.pass ] );
		assert.deepEqual( checks, fixture.expected, fixture.name );
	}
} );
