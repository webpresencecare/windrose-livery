/**
 * Writes assets/mermaid/ready-recipes.json: each ready style (assets/mermaid/styles.json) as the recipe Style
 * Mermaid sends when it is picked unchanged (fromStyle, then toRecipe), so other plugins (Windrose Knotboard) can
 * preview patterns in a ready style without Style Mermaid's code. `node bin/ready-recipes.mjs` (after changing the
 * ready styles or the recipe code); tests/js/ready-recipes.test.mjs checks the committed file is up to date.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { fromStyle, toRecipe } from '../src/mermaid/recipe.js';

export function readyRecipes() {
	const styles = JSON.parse( readFileSync( new URL( '../assets/mermaid/styles.json', import.meta.url ) ) );
	return styles.map( ( style ) => ( {
		slug: style.slug,
		title: style.title,
		description: style.description ?? '',
		recipe: toRecipe( fromStyle( style ), style.title ),
	} ) );
}

export const FILE = new URL( '../assets/mermaid/ready-recipes.json', import.meta.url );

if ( import.meta.url === `file://${ process.argv[ 1 ] }` ) {
	writeFileSync( FILE, JSON.stringify( readyRecipes(), null, '\t' ) + '\n' );
	console.log( 'assets/mermaid/ready-recipes.json written' );
}
