/**
 * The pattern browser (Livery 0.6) (settings: see the README, Development).
 *
 * A throwaway site on Windrose Prow for Ipsum (child of Ipsum) with its own title and a post, and two stored test styles.
 * Admin: Browse patterns (the site's real content, then Sample content), switching styles, the other palette,
 * phone width, the jump list; the stylesheet equals the site's own after really applying the style; Style Mermaid's
 * Patterns view follows the style being made. Visitor: the public Style Mermaid shows only Ipsum's and WordPress's
 * patterns with sample content, never the site's; stored styles and the switch off are refused. Nothing is saved
 * by the browser (posts and menus counted). Another plugin's patterns (0.7, as Windrose Knotboard will send them):
 * a test must-use plugin on the throwaway site only, rendered as one page for a visitor while the public Style Mermaid
 * is off. Everything is deleted at the end.
 */
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { test, expect } from '@playwright/test';

const HOST = process.env.WINDROSE_E2E_HOST ?? 'localhost';
const ADMIN = process.env.WINDROSE_E2E_USER ?? 'admin';
const WP_PATH = process.env.WINDROSE_E2E_WP_PATH ?? '/var/www/html/web';
const ROOT = `https://${ HOST }`;
const SCREEN = '/wp-admin/network/themes.php?page=windrose-livery';
const SITE = 'patterns-e2e';
const SITE_TITLE = 'Real Site Title E2E';
const POST_TITLE = 'Livery patterns real post 7Q';
const LIGHT = 'E2E Patterns Harbour';
const NIGHT = 'E2E Patterns Night';
const WORK = '/tmp/windrose-livery-patterns-e2e';

const wpAt = ( slug, ...args ) =>
	execFileSync( 'wp', [ ...args, `--path=${ WP_PATH }`, `--url=${ ROOT }/${ slug ? slug + '/' : '' }` ], {
		encoding: 'utf8',
	} ).trim();
const wp = ( ...args ) => wpAt( '', ...args );

function deleteSite( slug ) {
	try {
		const id = wp( 'site', 'list', '--field=blog_id', `--path__in=/${ slug }/` );
		if ( /^\d+$/.test( id ) ) {
			wp( 'site', 'delete', id, '--yes' );
			rmSync( `${ WP_PATH }/wp-content/uploads/sites/${ id }`, { recursive: true, force: true } );
		}
	} catch {}
}

function removeStyles() {
	wp(
		'eval',
		`foreach ( Windrose\\Livery\\Library::all() as $item ) { if ( in_array( $item['title'], array( '${ LIGHT }', '${ NIGHT }' ), true ) ) { Windrose\\Livery\\Library::delete( $item['id'] ); } }`
	);
}

/** Imports a small style for Ipsum into the shared library; answers its ID. */
function addStyle( title, palette ) {
	const file = `${ WORK }/${ title.replace( /\W+/g, '-' ) }.json`;
	writeFileSync(
		file,
		JSON.stringify( {
			version: 3,
			title,
			settings: { color: { palette } },
			styles: { color: { background: 'var:preset|color|base', text: 'var:preset|color|contrast' } },
		} )
	);
	return Number(
		wp(
			'eval',
			`$s = Windrose\\Livery\\Importer::import( '${ file }', 'style.json', array( 'dry_run' => false, 'theme' => 'ipsum' ) ); echo $s['id'];`,
			`--user=${ ADMIN }`
		)
	);
}

const count = ( type ) => wpAt( SITE, 'post', 'list', `--post_type=${ type }`, '--post_status=any', '--format=count' );
const frame = ( page ) => page.frameLocator( 'iframe[title="Patterns in the chosen style"]' );
const frameBody = ( page, prop = 'backgroundColor' ) =>
	frame( page )
		.locator( 'body' )
		.evaluate( ( b, p ) => getComputedStyle( b )[ p ], prop )
		.catch( () => null );
const frameText = ( page ) =>
	frame( page )
		.locator( 'body' )
		.innerText()
		.catch( () => '' );

let lightId = 0;
let nightId = 0;

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
	mkdirSync( WORK, { recursive: true } );
	removeStyles();
	deleteSite( SITE );
	wp( 'site', 'create', `--slug=${ SITE }`, `--title=${ SITE_TITLE }` );
	wpAt( SITE, 'theme', 'activate', 'windrose-prow-ipsum' );
	wpAt( SITE, 'post', 'create', '--post_status=publish', `--post_title=${ POST_TITLE }`, '--post_content=Real content of this site.' );
	lightId = addStyle( LIGHT, [
		{ slug: 'base', name: 'Background', color: '#fdf2e9' },
		{ slug: 'contrast', name: 'Text', color: '#1d1d1f' },
		{ slug: 'accent-1', name: 'Accent', color: '#9b2d20' },
		{ slug: 'windrose-livery-dark-base', name: 'Dark: Background', color: '#16181d' },
		{ slug: 'windrose-livery-dark-contrast', name: 'Dark: Text', color: '#ececf1' },
		{ slug: 'windrose-livery-dark-accent-1', name: 'Dark: Accent', color: '#ff9d8a' },
	] );
	nightId = addStyle( NIGHT, [
		{ slug: 'base', name: 'Background', color: '#202530' },
		{ slug: 'contrast', name: 'Text', color: '#f5f5f5' },
		{ slug: 'accent-1', name: 'Accent', color: '#ffb454' },
	] );
} );

test.afterAll( () => {
	removeStyles();
	deleteSite( SITE );
	rmSync( WORK, { recursive: true, force: true } );
} );

test( 'admin: Browse patterns with stored styles, real and sample content', async ( { page } ) => {
	test.setTimeout( 180000 );
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	const hosts = new Set();
	page.on( 'request', ( r ) => {
		if ( ! r.url().startsWith( 'data:' ) ) {
			hosts.add( new URL( r.url() ).host );
		}
	} );
	const navigations = count( 'wp_navigation' );
	const postCount = count( 'post' );

	// The row action opens the view.
	await page.goto( ROOT + SCREEN );
	const row = page.getByRole( 'row' ).filter( { hasText: LIGHT } );
	await row.getByRole( 'button', { name: 'Actions' } ).click();
	await page.getByRole( 'menuitem', { name: 'Browse patterns' } ).click();
	await expect( page ).toHaveURL( new RegExp( `view=patterns&style=${ lightId }&scope=0` ) );
	await expect( page.getByRole( 'heading', { name: `Patterns in ${ LIGHT }` } ) ).toBeVisible();

	// The throwaway site, its posts and its header: the site's own content.
	const pick = async ( start ) => {
		const option = page.getByLabel( 'Category' ).locator( 'option', { hasText: new RegExp( `^${ start }` ) } ).first();
		await expect( option ).toBeAttached( { timeout: 30000 } );
		await page.getByLabel( 'Category' ).selectOption( await option.getAttribute( 'value' ) );
	};
	await page.getByLabel( 'Site', { exact: true } ).selectOption( { label: SITE_TITLE } );
	await pick( 'Posts' );
	await expect.poll( () => frameText( page ), { timeout: 30000 } ).toContain( POST_TITLE );
	await expect.poll( () => frameBody( page ) ).toBe( 'rgb(253, 242, 233)' );
	await pick( 'Headers' );
	await expect.poll( () => frameText( page ), { timeout: 30000 } ).toContain( SITE_TITLE );
	expect( await frameText( page ) ).toContain( 'Sample Page' );

	// Sample content: what visitors see.
	await page.getByLabel( 'Sample content' ).check();
	await expect.poll( () => frameText( page ), { timeout: 30000 } ).not.toContain( SITE_TITLE );
	expect( await frameText( page ) ).toContain( 'Site Title' );
	// The menu: sample links, not the site's pages.
	expect( await frameText( page ) ).not.toContain( 'Sample Page' );
	expect( await frameText( page ) ).toContain( 'Contact' );
	await pick( 'Posts' );
	await expect.poll( () => frameText( page ), { timeout: 30000 } ).toContain( 'A walk along the harbour' );
	expect( await frameText( page ) ).not.toContain( POST_TITLE );

	// Another style, then back; the other palette.
	await page.getByLabel( 'Style', { exact: true } ).selectOption( { label: NIGHT } );
	await expect.poll( () => frameBody( page ), { timeout: 30000 } ).toBe( 'rgb(32, 37, 48)' );
	await expect( page.getByRole( 'button', { name: 'Dark/light switch' } ) ).toHaveCount( 0 );
	await page.getByLabel( 'Style', { exact: true } ).selectOption( { label: LIGHT } );
	await expect.poll( () => frameBody( page ), { timeout: 30000 } ).toBe( 'rgb(253, 242, 233)' );
	await page.getByRole( 'button', { name: 'Dark/light switch' } ).click();
	await expect.poll( () => frameBody( page ) ).toBe( 'rgb(22, 24, 29)' );
	await page.getByRole( 'button', { name: 'Dark/light switch' } ).click();

	// Every pattern; the list jumps; phone width.
	await page.getByLabel( 'Category' ).selectOption( { label: 'All patterns' } );
	const list = page.getByRole( 'navigation', { name: 'Patterns' } );
	await expect.poll( () => list.getByRole( 'button' ).count(), { timeout: 30000 } ).toBeGreaterThan( 30 );
	await expect.poll( () => frame( page ).locator( '[data-windrose-livery-pattern]' ).count(), { timeout: 30000 } ).toBeGreaterThan( 30 );
	// No page layouts hidden from the inserter.
	expect( await frame( page ).locator( '[data-windrose-livery-pattern="ipsum/hidden-404"]' ).count() ).toBe( 0 );
	await list.getByRole( 'button' ).nth( 20 ).click();
	await expect.poll( () => frame( page ).locator( 'html' ).evaluate( () => window.scrollY ) ).toBeGreaterThan( 500 );
	await page.getByRole( 'button', { name: 'Phone' } ).click();
	await expect.poll( () => page.locator( 'iframe[title="Patterns in the chosen style"]' ).evaluate( ( f ) => f.getBoundingClientRect().width ) ).toBeLessThanOrEqual( 392 );
	await page.screenshot( { path: test.info().outputPath( 'patterns-browse.png' ), fullPage: true } );

	const results = await new AxeBuilder( { page } )
		.include( '.windrose-livery__patterns-view' )
		.exclude( 'iframe' )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.analyze();
	expect( results.violations.map( ( v ) => `${ v.id }: ${ v.nodes.map( ( n ) => n.target ).join( ', ' ) }` ) ).toEqual( [] );

	expect( [ ...hosts ] ).toEqual( [ HOST ] );
	expect( errors ).toEqual( [] );
	// Sample content made nothing: no menu (a site without one would get a fallback menu from a real visit), no post.
	expect( count( 'wp_navigation' ) ).toBe( navigations );
	expect( count( 'post' ) ).toBe( postCount );
} );

test( 'admin: the stylesheet is the one the site has after really applying the style', async ( { page } ) => {
	await page.goto( `${ ROOT }${ SCREEN }&view=patterns&style=${ lightId }&scope=0` );
	await page.getByLabel( 'Site', { exact: true } ).selectOption( { label: SITE_TITLE } );
	await expect.poll( () => frameBody( page ), { timeout: 30000 } ).toBe( 'rgb(253, 242, 233)' );
	await expect.poll( () => frame( page ).locator( '[data-windrose-livery-pattern]' ).count(), { timeout: 30000 } ).toBeGreaterThan( 30 );
	const shown = await frame( page )
		.locator( '#global-styles-inline-css' )
		.evaluate( ( s ) => s.textContent );
	wp( 'eval', `Windrose\\Livery\\Applier::apply( Windrose\\Livery\\Library::get( ${ lightId } ), array( get_id_from_blogname( '${ SITE }' ) ) );`, `--user=${ ADMIN }` );
	await page.goto( `${ ROOT }/${ SITE }/` );
	const applied = await page.locator( '#global-styles-inline-css' ).evaluate( ( s ) => s.textContent );
	// WordPress prints a block's styles only on pages that hold the block: every rule of the applied site's home page
	// is in the page of all patterns, and the presets (colors, fonts, sizes) are the same.
	const rules = ( css ) => css.split( '}' ).map( ( r ) => r.trim() ).filter( Boolean );
	const presets = ( css ) => css.match( /^:root\{--wp--preset[^}]*\}/m )?.[ 0 ];
	expect( shown.length ).toBeGreaterThan( 1000 );
	expect( presets( shown ) ).toBeTruthy();
	expect( presets( shown ) ).toBe( presets( applied ) );
	expect( rules( applied ).filter( ( r ) => ! rules( shown ).includes( r ) ) ).toEqual( [] );
} );

test( 'admin: Style Mermaid shows the patterns in the style being made', async ( { page } ) => {
	test.setTimeout( 120000 );
	await page.goto( ROOT + SCREEN );
	await page.getByRole( 'button', { name: 'Create a style' } ).click();
	const sample = page.frameLocator( 'iframe[title="Preview of the style"]' );
	await expect( sample.locator( 'h1' ) ).toBeVisible( { timeout: 30000 } );
	const sampleBody = () =>
		sample
			.locator( 'body' )
			.evaluate( ( b ) => getComputedStyle( b ).backgroundColor )
			.catch( () => null );
	await expect.poll( sampleBody ).not.toBeNull();
	const before = await sampleBody();
	await page.getByRole( 'button', { name: /^Harbour/ } ).click();
	await expect.poll( sampleBody, { timeout: 30000 } ).not.toBe( before );
	const made = await sampleBody();

	await page.getByRole( 'button', { name: 'Patterns', exact: true } ).click();
	await page.getByLabel( 'Site', { exact: true } ).selectOption( { label: SITE_TITLE } );
	await expect.poll( () => frameBody( page ), { timeout: 30000 } ).toBe( made );
	await expect( page.getByLabel( 'Go to a pattern' ) ).toBeVisible();
	// A stored style instead, then the one being made again.
	await page.getByLabel( 'Style', { exact: true } ).selectOption( { label: NIGHT } );
	await expect.poll( () => frameBody( page ), { timeout: 30000 } ).toBe( 'rgb(32, 37, 48)' );
	await page.getByLabel( 'Style', { exact: true } ).selectOption( { label: 'The style you are making' } );
	await expect.poll( () => frameBody( page ), { timeout: 30000 } ).toBe( made );
	await page.screenshot( { path: test.info().outputPath( 'patterns-mermaid.png' ), fullPage: true } );
} );

test( 'visitor: the theme’s and WordPress’s patterns with sample content only', async ( { browser, page } ) => {
	test.setTimeout( 120000 );
	wpAt( SITE, 'option', 'update', 'windrose_livery_mermaid', '{"public":true,"remote":false}', '--format=json' );
	const pageId = wpAt( SITE, 'post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Make a style', '--post_content=<!-- wp:windrose/style-mermaid /-->', '--porcelain' );
	const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] }, ignoreHTTPSErrors: true } );
	const v = await visitor.newPage();
	const hosts = new Set();
	v.on( 'request', ( r ) => {
		if ( ! r.url().startsWith( 'data:' ) ) {
			hosts.add( new URL( r.url() ).host );
		}
	} );
	await v.goto( `${ ROOT }/${ SITE }/?page_id=${ pageId }` );
	await expect( v.frameLocator( 'iframe[title="Preview of the style"]' ).locator( 'h1' ) ).toBeVisible( { timeout: 30000 } );
	await v.getByRole( 'button', { name: 'Patterns', exact: true } ).click();
	await expect( v.getByText( 'The patterns of the Ipsum theme and of WordPress, with sample content' ) ).toBeVisible( { timeout: 30000 } );
	await expect( v.getByLabel( 'Sample content' ) ).toHaveCount( 0 );
	await expect( v.getByLabel( 'Style', { exact: true } ) ).toHaveCount( 0 );
	await expect.poll( () => frameText( v ), { timeout: 30000 } ).toContain( 'Site Title' );
	const text = await frameText( v );
	expect( text ).not.toContain( SITE_TITLE );
	expect( text ).not.toContain( POST_TITLE );
	const names = await frame( v )
		.locator( '[data-windrose-livery-pattern]' )
		.evaluateAll( ( els ) => els.map( ( e ) => e.dataset.windroseLiveryPattern ) );
	expect( names.length ).toBeGreaterThan( 30 );
	expect( names.every( ( n ) => /^(core|ipsum)\//.test( n ) ) ).toBe( true );
	expect( [ ...hosts ] ).toEqual( [ HOST ] );

	// Refused: a stored style, a nonce from nowhere, a GET; and everything once the switch is off.
	const render = `${ ROOT }/${ SITE }/?windrose_livery_patterns=1`;
	expect( ( await v.request.post( render, { form: { style: String( lightId ), scope: '0' } } ) ).status() ).toBe( 403 );
	expect( ( await v.request.post( render, { form: { style: String( lightId ), scope: '0', _wpnonce: 'abc123' } } ) ).status() ).toBe( 403 );
	expect( ( await v.request.get( render ) ).status() ).toBe( 405 );
	wpAt( SITE, 'option', 'update', 'windrose_livery_mermaid', '{"public":false,"remote":false}', '--format=json' );
	expect( ( await v.request.post( render, { form: { recipe: '{}' } } ) ).status() ).toBe( 403 );
	expect( ( await v.request.get( `${ ROOT }/${ SITE }/wp-json/windrose-livery/v1/patterns` ) ).status() ).toBe( 401 );
	await visitor.close();
	await page.close();
} );

test( 'visitor: another plugin\'s public library renders its patterns as one page', async ( { browser, page } ) => {
	test.setTimeout( 120000 );
	const dir = `${ WP_PATH }/wp-content/mu-plugins`;
	const made = ! existsSync( dir );
	const file = `${ dir }/windrose-livery-e2e-source.php`;
	mkdirSync( dir, { recursive: true } );
	// Only on the throwaway site: a source 'e2e' with two offered items and one that is not offered.
	writeFileSync(
		file,
		`<?php
if ( ! str_ends_with( untrailingslashit( (string) parse_url( home_url(), PHP_URL_PATH ) ), '/${ SITE }' ) ) { return; }
add_filter( 'windrose_livery_visitor_previews', static fn( $open, $source ) => $open || 'e2e' === $source, 10, 2 );
add_filter( 'windrose_livery_pattern_items', static function ( $items, $source, $keys, $visitor ) {
	if ( 'e2e' !== $source ) { return $items; }
	$known = array(
		'hero'   => array( 'Hero', '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Library hero E2E</h1><!-- /wp:heading -->', true ),
		'hours'  => array( 'Hours', '<!-- wp:paragraph --><p>Library hours E2E</p><!-- /wp:paragraph -->', true ),
		'secret' => array( 'Secret', '<!-- wp:paragraph --><p>Not offered E2E</p><!-- /wp:paragraph -->', false ),
	);
	$list = array();
	foreach ( $keys as $key ) {
		if ( isset( $known[ $key ] ) && ( ! $visitor || $known[ $key ][2] ) ) {
			$list[] = array( 'name' => 'e2e/' . $key, 'title' => $known[ $key ][0], 'content' => $known[ $key ][1] );
		}
	}
	return $list;
}, 10, 4 );
`
	);
	try {
		wpAt( SITE, 'option', 'update', 'windrose_livery_mermaid', '{"public":false,"remote":false}', '--format=json' );
		const recipe = JSON.stringify(
			JSON.parse( readFileSync( new URL( '../fixtures/block-looks.json', import.meta.url ), 'utf8' ) )[ 0 ].recipe
		);
		const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] }, ignoreHTTPSErrors: true } );
		const render = `${ ROOT }/${ SITE }/?windrose_livery_patterns=1`;
		const answer = await visitor.request.post( render, { form: { recipe, source: 'e2e', items: 'hours,secret,hero', layout: 'page' } } );
		expect( answer.status() ).toBe( 200 );
		const html = await answer.text();
		expect( html.indexOf( 'Library hours E2E' ) ).toBeGreaterThan( 0 );
		expect( html.indexOf( 'Library hero E2E' ) ).toBeGreaterThan( html.indexOf( 'Library hours E2E' ) );
		expect( html ).not.toContain( 'Not offered E2E' );
		expect( html ).not.toContain( 'class="windrose-livery-patterns__label"' );
		expect( html ).not.toContain( SITE_TITLE );
		expect( html ).toContain( 'noindex' );
		// The style is the recipe's, not the site's saved one.
		const background = JSON.parse( recipe ).settings.color.palette.find( ( c ) => c.slug === 'base' ).color;
		expect( html.toLowerCase() ).toContain( `--wp--preset--color--base: ${ background.toLowerCase() }` );

		// The library opens nothing else, and only what the source offers.
		expect( ( await visitor.request.post( render, { form: { recipe, source: 'e2e', items: 'secret' } } ) ).status() ).toBe( 404 );
		expect( ( await visitor.request.post( render, { form: { recipe } } ) ).status() ).toBe( 403 );
		expect( ( await visitor.request.post( render, { form: { recipe, source: 'other', items: 'hero' } } ) ).status() ).toBe( 403 );
		expect( ( await visitor.request.post( render, { form: { style: String( lightId ), scope: '0', source: 'e2e', items: 'hero' } } ) ).status() ).toBe( 403 );
		await visitor.close();
	} finally {
		rmSync( file, { force: true } );
		if ( made && existsSync( dir ) && readdirSync( dir ).length === 0 ) {
			rmSync( dir, { recursive: true, force: true } );
		}
	}
	await page.close();
} );
