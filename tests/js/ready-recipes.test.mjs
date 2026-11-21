/**
 * The ready styles as recipes (for other plugins, 0.7): the committed file is what bin/ready-recipes.mjs makes.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { readyRecipes, FILE } from '../../bin/ready-recipes.mjs';

test( 'assets/mermaid/ready-recipes.json is up to date (node bin/ready-recipes.mjs)', () => {
	assert.deepEqual( JSON.parse( readFileSync( FILE, 'utf8' ) ), JSON.parse( JSON.stringify( readyRecipes() ) ) );
} );

test( 'every ready style has a recipe with both fonts bundled', () => {
	for ( const item of readyRecipes() ) {
		assert.equal( item.recipe.fonts.heading.source, 'bundled', item.slug );
		assert.equal( item.recipe.fonts.text.source, 'bundled', item.slug );
		assert.ok( item.recipe.settings.color.palette.length >= 6, item.slug );
	}
} );
