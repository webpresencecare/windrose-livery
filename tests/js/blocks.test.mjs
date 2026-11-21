/**
 * Style Mermaid's per-block looks (src/mermaid/blocks.js, 0.5).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
	BLOCK_ORDER,
	BLOCK_STYLES,
	CORNER_BLOCKS,
	applyBlocks,
} from '../../src/mermaid/blocks.js';
import { fromStyle, paletteMap, toRecipe } from '../../src/mermaid/recipe.js';
import { checkContrast } from '../../src/mermaid/contrast.js';

const styles = JSON.parse(
	readFileSync( new URL( '../../assets/mermaid/styles.json', import.meta.url ) )
);

/** Every choice of an entry, one option changed at a time from the defaults. */
function choicesOf( id ) {
	const entry = BLOCK_STYLES[ id ];
	const list = [ { ...entry.defaults } ];
	const options = {
		button: { shape: [ 'square', 'rounded', 'pill' ], fill: [ 'filled', 'outlined' ], color: [ 'text', 'accent', 'strong' ], hover: [ 'text', 'accent', 'strong' ], size: [ 'small', 'medium', 'large' ] },
		corners: { radius: [ 'square', 'soft', 'round' ] },
		images: { frame: [ 'none', 'line', 'shadow' ] },
		quotes: { look: [ 'soft', 'lines', 'plain' ] },
		separator: { color: [ 'lines', 'accent', 'text' ], thickness: [ 'thin', 'thick' ] },
		menu: { case: [ 'as-written', 'capitals' ], weight: [ 'regular', 'bold' ], gap: [ 'small', 'medium', 'large' ] },
		code: { look: [ 'line', 'soft' ] },
	}[ id ];
	Object.entries( options ).forEach( ( [ key, values ] ) =>
		values.forEach( ( v ) => list.push( { ...entry.defaults, [ key ]: v } ) )
	);
	return list;
}

test( 'every entry has defaults, a preview target and the blocks it changes', () => {
	assert.deepEqual( Object.keys( BLOCK_STYLES ).sort(), [ ...BLOCK_ORDER ].sort() );
	BLOCK_ORDER.forEach( ( id ) => {
		const entry = BLOCK_STYLES[ id ];
		assert.ok( entry.defaults && 'object' === typeof entry.defaults, id );
		assert.match( entry.focus, /^\.wp-block-/, id );
		assert.ok( entry.blocks.length > 0, id );
	} );
} );

test( 'looks never change the input and write only theme.json shapes', () => {
	const style = fromStyle( styles[ 1 ] );
	const before = JSON.stringify( style.styles );
	BLOCK_ORDER.forEach( ( id ) => {
		choicesOf( id ).forEach( ( value ) => {
			const out = BLOCK_STYLES[ id ].apply( style.styles, value, paletteMap( style.settings ) );
			assert.equal( JSON.stringify( style.styles ), before );
			assert.ok( ! JSON.stringify( out ).includes( '"css"' ), `${ id } writes no CSS` );
		} );
	} );
} );

test( 'buttons: focus like hover, pressed like the button, no states in the Outline style', () => {
	const style = fromStyle( styles[ 0 ] );
	const out = BLOCK_STYLES.button.apply( {}, { shape: 'pill', fill: 'filled', color: 'accent', hover: 'text', size: 'large' }, paletteMap( style.settings ) );
	const block = out.blocks[ 'core/button' ];
	assert.equal( block[ ':hover' ].color.background, 'var:preset|color|contrast', 'The chosen hover color' );
	const outlined = BLOCK_STYLES.button.apply( {}, { shape: 'pill', fill: 'outlined', color: 'accent', hover: 'strong', size: 'medium' }, paletteMap( style.settings ) );
	assert.equal( outlined.blocks[ 'core/button' ][ ':hover' ].color.background, 'var:preset|color|accent-3', 'Outlined buttons fill with it too' );
	assert.equal( outlined.blocks[ 'core/button' ].color.background, 'transparent' );
	assert.deepEqual( block[ ':focus' ].color, block[ ':hover' ].color );
	assert.deepEqual( block[ ':active' ].color, block.color );
	assert.equal( block.color.background, 'var:preset|color|accent-1' );
	assert.deepEqual( block.spacing.padding, { top: 'var:preset|spacing|40', bottom: 'var:preset|spacing|40', left: 'var:preset|spacing|50', right: 'var:preset|spacing|50' } );
	assert.deepEqual( out.elements.button.spacing.padding, block.spacing.padding );
	const outline = block.variations.outline;
	[ ':hover', ':focus', ':focus-visible', ':active' ].forEach( ( k ) => assert.equal( outline[ k ], undefined ) );
	assert.equal( out.blocks[ 'core/search' ].elements.button.border.radius, '100px' );
} );

test( 'corners: one radius on every listed block', () => {
	const out = BLOCK_STYLES.corners.apply( {}, { radius: 'round' } );
	CORNER_BLOCKS.forEach( ( name ) =>
		assert.deepEqual( out.blocks[ name ].border.radius, { topLeft: '16px', topRight: '16px', bottomLeft: '16px', bottomRight: '16px' } )
	);
} );

test( 'quotes: lines above and below only, no box corners; soft keeps the Corners choice', () => {
	const lines = applyBlocks( {}, { corners: { radius: 'round' }, quotes: { look: 'lines' } }, {} );
	const q = lines.blocks[ 'core/quote' ];
	assert.equal( q.color.background, 'transparent' );
	assert.equal( q.border.top.width, '1px' );
	assert.equal( q.border.bottom.width, '1px' );
	assert.equal( q.border.left.width, '0' );
	assert.equal( q.border.right.width, '0' );
	assert.equal( q.border.radius.topLeft, '0' );
	const soft = applyBlocks( {}, { corners: { radius: 'round' }, quotes: { look: 'soft' } }, {} );
	assert.equal( soft.blocks[ 'core/pullquote' ].border.radius.topLeft, '16px' );
	assert.equal( soft.blocks[ 'core/pullquote' ].color.background, 'var:preset|color|accent-5' );
} );

test( 'images, separator, menu and code', () => {
	const out = applyBlocks(
		{},
		{
			corners: { radius: 'square' },
			images: { frame: 'shadow' },
			separator: { color: 'accent', thickness: 'thick' },
			menu: { case: 'capitals', weight: 'bold', gap: 'small' },
			code: { look: 'soft' },
		},
		{}
	);
	assert.equal( out.blocks[ 'core/image' ].shadow, 'var:preset|shadow|soft' );
	assert.equal( out.blocks[ 'core/image' ].border.radius.topLeft, '0' );
	assert.equal( out.blocks[ 'core/post-featured-image' ].border.width, '0' );
	assert.equal( out.blocks[ 'core/separator' ].color.text, 'var:preset|color|accent-1' );
	assert.equal( out.blocks[ 'core/separator' ].border.width, '0 0 3px 0' );
	assert.deepEqual( out.blocks[ 'core/navigation' ].typography, { textTransform: 'uppercase', letterSpacing: '0.06em', fontWeight: '700' } );
	assert.equal( out.blocks[ 'core/navigation' ].spacing.blockGap, 'var:preset|spacing|30' );
	assert.equal( out.blocks[ 'core/code' ].color.background, 'var:preset|color|accent-5' );
	assert.equal( out.blocks[ 'core/code' ].border.radius.topLeft, '0' );
	const line = applyBlocks( {}, { images: { frame: 'line' } }, {} );
	assert.equal( line.blocks[ 'core/image' ].border.color, 'var:preset|color|accent-6' );
	assert.equal( line.blocks[ 'core/image' ].shadow, 'none' );
} );

test( 'no look writes custom CSS, and the recipe carries looks only as styles', () => {
	const state = fromStyle( styles[ 2 ] );
	state.blocks = Object.fromEntries( BLOCK_ORDER.map( ( id ) => [ id, { ...BLOCK_STYLES[ id ].defaults } ] ) );
	const recipe = toRecipe( state );
	assert.deepEqual( Object.keys( recipe ).sort(), [ 'description', 'fonts', 'settings', 'styles', 'title' ] );
	assert.ok( ! JSON.stringify( recipe.styles ).includes( '"css"' ) );
	assert.equal( BLOCK_STYLES.list, undefined );
} );

test( 'no look makes a contrast check fail that passed before (every ready style, every choice but buttons)', () => {
	styles.forEach( ( style ) => {
		const state = fromStyle( style );
		const base = checkContrast( state.settings, toRecipe( state ).styles );
		const failing = new Set( base.filter( ( c ) => ! c.pass && ! c.warning ).map( ( c ) => c.id ) );
		BLOCK_ORDER.filter( ( id ) => 'button' !== id ).forEach( ( id ) => {
			choicesOf( id ).forEach( ( value ) => {
				state.blocks = { [ id ]: value };
				const recipe = toRecipe( state );
				checkContrast( recipe.settings, recipe.styles )
					.filter( ( c ) => ! c.pass && ! c.warning )
					.forEach( ( c ) => assert.ok( failing.has( c.id ), `${ style.slug } ${ id } ${ JSON.stringify( value ) }: ${ c.id }` ) );
			} );
		} );
	} );
} );

test( 'buttons: every choice on every ready style is checked (button and hover pairs exist)', () => {
	styles.forEach( ( style ) => {
		const state = fromStyle( style );
		choicesOf( 'button' ).forEach( ( value ) => {
			state.blocks = { button: value };
			const recipe = toRecipe( state );
			const ids = checkContrast( recipe.settings, recipe.styles ).map( ( c ) => c.id );
			assert.ok( ids.includes( 'button' ) && ids.includes( 'button-hover' ), style.slug );
		} );
	} );
} );

/** Three recipes covering every option (shared with PHPUnit, BlockLooksTest). UPDATE_FIXTURES=1 rewrites the file. */
function fixtureRecipes() {
	const sets = {
		first: {
			button: { shape: 'pill', fill: 'outlined', color: 'accent', hover: 'text', size: 'large' },
			corners: { radius: 'round' },
			images: { frame: 'shadow' },
			quotes: { look: 'lines' },
			separator: { color: 'accent', thickness: 'thick' },
			menu: { case: 'capitals', weight: 'bold', gap: 'large' },
			code: { look: 'soft' },
		},
		second: {
			button: { shape: 'square', fill: 'filled', color: 'strong', hover: 'accent', size: 'small' },
			corners: { radius: 'square' },
			images: { frame: 'line' },
			quotes: { look: 'plain' },
			separator: { color: 'text', thickness: 'thin' },
			menu: { case: 'as-written', weight: 'regular', gap: 'small' },
			code: { look: 'line' },
		},
		defaults: Object.fromEntries( BLOCK_ORDER.map( ( id ) => [ id, { ...BLOCK_STYLES[ id ].defaults } ] ) ),
	};
	return Object.entries( sets ).map( ( [ name, blocks ] ) => {
		const state = fromStyle( styles.find( ( s ) => 'harbour' === s.slug ) );
		state.title = `E2E Looks ${ name }`;
		state.blocks = blocks;
		return { name, recipe: toRecipe( state ) };
	} );
}

test( 'the shared fixture matches what the browser sends', async () => {
	const url = new URL( '../fixtures/block-looks.json', import.meta.url );
	const made = JSON.stringify( fixtureRecipes(), null, '\t' ) + '\n';
	if ( process.env.UPDATE_FIXTURES ) {
		const { writeFileSync } = await import( 'node:fs' );
		writeFileSync( url, made );
	}
	assert.equal( readFileSync( url, 'utf8' ), made );
} );
