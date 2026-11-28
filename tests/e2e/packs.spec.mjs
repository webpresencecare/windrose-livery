/**
 * Font packs and language rules (settings: see the README, Development).
 *
 * Needs the packs built by `bin/font-packs.py build <dir>`, with WINDROSE_E2E_PACKS set to that folder (skipped without them).
 * Admin: uploads the Arabic pack on Livery's screen, makes a style with its fonts (no request to Google Fonts or Bunny
 * Fonts, nor anywhere else), a Persian font and a size, checks the preview (only the fonts it shows are downloaded),
 * adds it to the library.
 * Front end: a throwaway site with that style and a page with English and Persian text: the Persian paragraph uses
 * Vazirmatn, the English page part downloads no script font, Beiruti carries its size-adjust.
 * Everything made here is deleted at the end: the variation and its unused font files, the site, and the pack unless
 * it was installed before.
 */
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { test, expect } from '@playwright/test';

const HOST = process.env.WINDROSE_E2E_HOST ?? 'localhost';
const ADMIN = process.env.WINDROSE_E2E_USER ?? 'admin';
const WP_PATH = process.env.WINDROSE_E2E_WP_PATH ?? '/var/www/html/web';
const ROOT = `https://${ HOST }`;
const SCREEN = '/wp-admin/network/themes.php?page=windrose-livery';
const TITLE = 'E2E Packs';
const SITE = 'packs-e2e';
const PACK = process.env.WINDROSE_E2E_PACKS ? `${ process.env.WINDROSE_E2E_PACKS }/windrose-livery-fonts-arabic.zip` : '';

const wpAt = ( slug, ...args ) =>
	execFileSync( 'wp', [ ...args, `--path=${ WP_PATH }`, `--url=${ ROOT }/${ slug ? slug + '/' : '' }` ], {
		encoding: 'utf8',
	} ).trim();
const wp = ( ...args ) => wpAt( '', ...args );
const installed = () => JSON.parse( wp( 'eval', 'echo wp_json_encode( array_keys( Windrose\\Livery\\Packs::installed() ) );' ) );

async function expectAccessible( page, selector ) {
	await page.waitForFunction( () => document.getAnimations().every( ( a ) => 'running' !== a.playState ) );
	const results = await new AxeBuilder( { page } )
		.include( selector )
		.exclude( 'iframe' )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.analyze();
	expect( results.violations.map( ( v ) => `${ v.id }: ${ v.nodes.map( ( n ) => n.target ).join( ', ' ) }` ) ).toEqual( [] );
}

const step = ( page, name ) =>
	page.getByRole( 'navigation', { name: 'Steps' } ).getByRole( 'button', { name } ).click();
const preview = ( page ) => page.frameLocator( 'iframe[title="Preview of the style"]' );

test.describe.configure( { mode: 'serial' } );
test.skip( ! existsSync( PACK ), 'Font packs not built (bin/font-packs.py build <dir>, then WINDROSE_E2E_PACKS=<dir>)' );

let hadArabic = false;
let siteId = '';

test.beforeAll( () => {
	hadArabic = installed().includes( 'arabic' );
	if ( wp( 'site', 'list', '--field=url' ).split( '\n' ).some( ( u ) => u.endsWith( `/${ SITE }/` ) ) ) {
		wp( 'site', 'delete', `--slug=${ SITE }`, '--yes' );
	}
} );

test.afterAll( () => {
	try {
		if ( /^\d+$/.test( siteId ) ) {
			wp( 'site', 'delete', siteId, '--yes' );
			wp( 'eval', `Windrose\\Common\\Files::direct()->rmdir( '${ WP_PATH }/wp-content/uploads/sites/${ siteId }', true );` );
		}
	} catch {}
	wp(
		'eval',
		`foreach ( Windrose\\Livery\\Library::all() as $item ) { if ( str_starts_with( $item['title'], '${ TITLE }' ) ) { $u = Windrose\\Livery\\Fonts::unused( array_column( $item['files'], 'stored' ), $item['id'] ); Windrose\\Livery\\Library::delete( $item['id'] ); Windrose\\Livery\\Fonts::delete( $u ); } }`
	);
	if ( ! hadArabic ) {
		wp( 'eval', "Windrose\\Livery\\Packs::remove( 'arabic' );" );
	}
} );

test( 'admin: upload a pack, make a style with its fonts, a Persian font and a size', async ( { page } ) => {
	test.setTimeout( 180000 );
	const external = [];
	const errors = [];
	page.on( 'request', ( r ) => {
		const u = new URL( r.url() );
		if ( u.protocol.startsWith( 'http' ) && u.hostname !== HOST ) {
			external.push( u.hostname );
		}
	} );
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );

	// Livery's screen: the Font packs card, upload the Arabic pack.
	await page.goto( SCREEN );
	const card = page.locator( '.windrose-livery__card', { has: page.getByRole( 'heading', { name: 'Font packs' } ) } );
	await expect( card.getByRole( 'row', { name: /Arabic/ } ) ).toBeVisible();
	await expectAccessible( page, '.windrose-livery__packs' );
	const chooser = page.waitForEvent( 'filechooser' );
	await card.getByRole( 'button', { name: 'Upload a pack' } ).click();
	await ( await chooser ).setFiles( PACK );
	await expect( page.locator( '.components-notice__content', { hasText: 'windrose-livery-fonts-arabic.zip is installed.' } ) ).toBeVisible( { timeout: 30000 } );
	await expect( card.getByRole( 'row', { name: /Arabic/ } ) ).toContainText( 'Installed (version' );
	await expect( card.getByRole( 'button', { name: 'Remove the Arabic (also Persian, Urdu) pack' } ) ).toBeVisible();

	// Style Mermaid: Arabic from the pack at once (no fetch), Persian with Vazirmatn, size 125.
	await page.getByRole( 'button', { name: 'Create a style' } ).click();
	await expect( preview( page ).locator( 'h1' ) ).toBeVisible();
	await page.getByRole( 'button', { name: /^Harbour/ } ).click();
	await step( page, 'Fonts' );
	await page.getByRole( 'checkbox', { name: /^Arabic/ } ).check();
	await expect( page.getByLabel( 'Heading and text fonts' ) ).toHaveValue( '0' );
	const size = page.getByRole( 'spinbutton', { name: 'Size next to Latin text' } );
	await expect( size ).toHaveValue( '115' );
	await size.fill( '125' );
	await page.getByLabel( 'Font for Persian' ).selectOption( 'Vazirmatn' );
	await expect( preview( page ).locator( 'span[lang="fa"]' ) ).toBeVisible();
	await expectAccessible( page, '.windrose-livery-mermaid' );

	// The preview: the Persian line uses Vazirmatn, loaded from this website; Beiruti carries the size.
	await expect
		.poll( () =>
			preview( page )
				.locator( 'span[lang="fa"]' )
				.evaluate( ( el ) => getComputedStyle( el ).fontFamily, null, { timeout: 2000 } )
				.catch( () => '' )
		)
		.toMatch( /^"?Roboto Slab"?, "?Vazirmatn"?|^"?Source Sans 3"?, "?Vazirmatn"?/ );
	const frameFonts = await preview( page )
		.locator( 'body' )
		.evaluate( async () => {
			await document.fonts.ready;
			return {
				loaded: [ ...document.fonts ].filter( ( f ) => 'loaded' === f.status ).map( ( f ) => f.family.replace( /"/g, '' ) ),
				css: [ ...document.querySelectorAll( 'style' ) ].map( ( s ) => s.textContent ).join( '' ),
			};
		} );
	expect( frameFonts.loaded ).toContain( 'Vazirmatn' );
	expect( frameFonts.loaded ).toContain( 'Noto Sans Arabic' );
	expect( frameFonts.loaded, 'The heading font only for Arabic headings, and the sample has none' ).not.toContain( 'Beiruti' );
	expect( frameFonts.css ).toContain( 'size-adjust:125%' );
	expect( frameFonts.css ).toContain( '/windrose-livery/packs/arabic/vazirmatn/' );

	// Add to the library.
	await step( page, 'Finish' );
	await page.getByLabel( 'Title' ).fill( TITLE );
	await page.getByRole( 'button', { name: 'Add to the library' } ).click();
	await expect( page.locator( '.components-notice__content', { hasText: `${ TITLE } added to the library (version 1)` } ) ).toBeVisible( { timeout: 30000 } );

	expect( external ).toEqual( [] );
	expect( errors ).toEqual( [] );
} );

test( 'front end: the Persian paragraph uses Vazirmatn, the English text no script font', async ( { browser } ) => {
	test.setTimeout( 120000 );
	// A throwaway site on Ipsum with the style applied, and a page in English with one Persian paragraph.
	wp( 'site', 'create', `--slug=${ SITE }`, '--title=Packs E2E', '--email=packs-e2e@example.com' );
	siteId = wp( 'site', 'list', '--field=blog_id', `--path__in=/${ SITE }/` );
	wpAt( SITE, 'theme', 'activate', 'ipsum' );
	// Applied as a network administrator: Livery checks the rights on the site.
	const applied = wp(
		'eval',
		`--user=${ ADMIN }`,
		`foreach ( Windrose\\Livery\\Library::all() as $item ) { if ( '${ TITLE }' === $item['title'] ) { $r = Windrose\\Livery\\Applier::apply( $item, array( ${ siteId } ) ); echo $r[0]['status']; } }`
	);
	expect( applied ).toBe( 'applied' );
	const english = wpAt(
		SITE,
		'post',
		'create',
		'--post_type=page',
		'--post_status=publish',
		'--post_title=English only',
		'--post_content=<!-- wp:paragraph --><p>Only Latin letters here.</p><!-- /wp:paragraph -->',
		'--porcelain'
	);
	const mixed = wpAt(
		SITE,
		'post',
		'create',
		'--post_type=page',
		'--post_status=publish',
		'--post_title=Mixed',
		'--post_content=<!-- wp:paragraph --><p>English first.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p lang="fa" dir="rtl">به وب‌سایت ما خوش آمدید</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>مرحبًا بكم في موقعنا</p><!-- /wp:paragraph -->',
		'--porcelain'
	);
	const context = await browser.newContext( { storageState: { cookies: [], origins: [] }, ignoreHTTPSErrors: true } );
	const page = await context.newPage();
	const fontFiles = [];
	page.on( 'request', ( r ) => {
		if ( /\.woff2(\?|$)/.test( r.url() ) ) {
			fontFiles.push( r.url() );
		}
	} );

	await page.goto( `${ ROOT }/${ SITE }/?page_id=${ english }` );
	await page.evaluate( () => document.fonts.ready );
	expect( fontFiles.filter( ( u ) => /beiruti|vazirmatn|arabic/.test( u ) ) ).toEqual( [] );
	const rules = await page.locator( 'style#windrose-livery-languages-inline-css' ).textContent();
	expect( rules ).toContain( ':root:is(:lang(fa))' );

	fontFiles.length = 0;
	await page.goto( `${ ROOT }/${ SITE }/?page_id=${ mixed }` );
	const persian = page.locator( 'p[lang="fa"]' );
	await expect( persian ).toBeVisible();
	expect( await persian.evaluate( ( el ) => getComputedStyle( el ).fontFamily ) ).toMatch( /Vazirmatn/ );
	expect( await page.locator( 'p', { hasText: 'مرحبًا' } ).evaluate( ( el ) => getComputedStyle( el ).fontFamily ) ).not.toMatch( /Vazirmatn/ );
	await page.evaluate( () => document.fonts.ready );
	expect( fontFiles.some( ( u ) => /vazirmatn/.test( u ) ) ).toBe( true );
	expect( fontFiles.some( ( u ) => /noto-sans-arabic/.test( u ) ) ).toBe( true );
	expect( fontFiles.some( ( u ) => /beiruti/.test( u ) ), 'Beiruti is for Arabic headings only' ).toBe( false );
	// WordPress itself prints the faces from the style, with size-adjust.
	const faces = await page.evaluate( () => [ ...document.querySelectorAll( 'style' ) ].map( ( s ) => s.textContent ).join( '' ) );
	expect( faces ).toMatch( /font-family:\s*"?Noto Sans Arabic"?;[^}]*size-adjust:\s*125%/ );
	await context.close();
} );
