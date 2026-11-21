/**
 * Light and dark twins (node --test tests/js): found by slug, made for every ready style with passing contrast, the
 * same rules as includes/class-schemes.php and Contrast::check_twins().
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
	findTwins,
	makeTwins,
	ownScheme,
	twinChecks,
	twinSlug,
	withoutTwins,
} from '../../src/mermaid/twins.js';
import { passes } from '../../src/mermaid/contrast.js';

const styles = JSON.parse(
	readFileSync(
		new URL( '../../assets/mermaid/styles.json', import.meta.url )
	)
);

test( 'twins are found by slug when their colour exists', () => {
	const palette = [
		{ slug: 'base', color: '#ffffff' },
		{ slug: 'windrose-livery-dark-base', color: '#111111' },
		{ slug: 'windrose-livery-dark-gone', color: '#000000' },
	];
	assert.deepEqual( findTwins( palette ), {
		scheme: 'dark',
		map: { 'base': 'windrose-livery-dark-base' },
	} );
	assert.deepEqual( findTwins( palette.slice( 0, 1 ) ), {
		scheme: '',
		map: {},
	} );
	assert.equal( withoutTwins( palette ).length, 1 );
	assert.equal(
		twinSlug( 'light', 'accent-1' ),
		'windrose-livery-light-accent-1'
	);
} );

test( 'every ready style gets twins in the other scheme that pass contrast', () => {
	for ( const style of styles ) {
		const made = makeTwins( style.settings ?? {}, style.styles ?? {} );
		const own = ownScheme( style.settings ?? {}, style.styles ?? {} );
		assert.notEqual( made.scheme, own, style.slug );
		assert.equal(
			made.twins.length,
			withoutTwins( style.settings?.color?.palette ).length,
			style.slug
		);
		assert.ok(
			made.passes,
			`${ style.slug }: ${ JSON.stringify( made.checks.filter( ( c ) => ! c.pass && ! c.warning ) ) }`
		);
		// The checks of the result, made again from the palette, agree.
		const again = twinChecks(
			{
				...style.settings,
				color: { ...style.settings.color, palette: made.palette },
			},
			style.styles ?? {}
		);
		assert.ok( passes( again ), style.slug );
	}
} );

test( 'twins keep the transparency of their colour, and old twins are replaced', () => {
	const ipsum = styles.find( ( s ) => 'ipsum' === s.slug );
	const made = makeTwins( ipsum.settings, ipsum.styles ?? {} );
	const lines = made.twins.find(
		( t ) => t.slug === 'windrose-livery-dark-accent-6'
	);
	assert.match(
		lines.color,
		/^#[0-9a-f]{8}$/,
		'Lines are see-through in Ipsum: their twin too.'
	);
	const twice = makeTwins(
		{
			...ipsum.settings,
			color: { ...ipsum.settings.color, palette: made.palette },
		},
		ipsum.styles ?? {}
	);
	assert.equal( twice.palette.length, made.palette.length );
} );
