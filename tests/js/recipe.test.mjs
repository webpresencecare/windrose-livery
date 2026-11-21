/**
 * Style Mermaid's recipe: what the browser sends for script fonts (node --test tests/js).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fromStyle, toRecipe } from '../../src/mermaid/recipe.js';

const styles = JSON.parse(
	readFileSync( new URL( '../../assets/mermaid/styles.json', import.meta.url ) )
);

test( 'script fonts from a pack go by reference, with their size and language fonts', () => {
	const state = fromStyle( styles.find( ( s ) => 'harbour' === s.slug ) );
	const pack = ( slug, name ) => ( {
		source: 'pack',
		pack: 'arabic',
		slug,
		name,
		generic: 'sans-serif',
	} );
	state.fonts.scripts = {
		arabic: {
			pair: 0,
			heading: pack( 'beiruti', 'Beiruti' ),
			text: pack( 'noto-sans-arabic', 'Noto Sans Arabic' ),
			size: 125,
			languages: { fa: pack( 'vazirmatn', 'Vazirmatn' ), ur: undefined },
		},
		hebrew: undefined,
	};
	const recipe = toRecipe( state, 'My style' );
	assert.deepEqual( recipe.fonts.scripts, {
		arabic: {
			heading: { source: 'pack', pack: 'arabic', slug: 'beiruti' },
			text: { source: 'pack', pack: 'arabic', slug: 'noto-sans-arabic' },
			size: 125,
			languages: {
				fa: { source: 'pack', pack: 'arabic', slug: 'vazirmatn' },
			},
		},
	} );
} );

test( 'a pair without a size is sent at 100%', () => {
	const state = fromStyle( styles[ 0 ] );
	state.fonts.scripts = {
		hebrew: {
			heading: { source: 'remote', key: 'k1', generic: 'serif', name: 'Frank Ruhl Libre' },
			text: { source: 'remote', key: 'k2', generic: 'sans-serif', name: 'Heebo' },
		},
	};
	const sent = toRecipe( state, 'x' ).fonts.scripts.hebrew;
	assert.equal( sent.size, 100 );
	assert.deepEqual( sent.languages, {} );
	assert.deepEqual( sent.text, { source: 'remote', key: 'k2', generic: 'sans-serif' } );
} );
