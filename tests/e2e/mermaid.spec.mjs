/**
 * Style Mermaid (settings: see the README, Development).
 *
 * Admin: makes a style from a ready one, a palette, a fix, a light palette for a dark one (0.4), a font installed in
 * the main site's Font Library (since 0.4 managers get fonts there, not from Google Fonts or Bunny Fonts here) and
 * Arabic fonts from the network's font pack (skipped without it), adds it to the library and downloads it.
 * Public: a throwaway site with the block, visited without an account: download, no cookie, no outside request,
 * download refused while a contrast check fails, nothing once switched off.
 * Everything made here is deleted at the end: the variation and its unused font files, the Font Library family it
 * installed, the fonts it fetched (cache entries that were not there before), the site.
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, rmSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { test, expect } from '@playwright/test';

const HOST = process.env.WINDROSE_E2E_HOST ?? 'localhost';
const WP_PATH = process.env.WINDROSE_E2E_WP_PATH ?? '/var/www/html/web';
const ROOT = `https://${ HOST }`;
const SCREEN = '/wp-admin/network/themes.php?page=windrose-livery';
const TITLE = 'E2E Mermaid';
const SITE = 'mermaid-e2e';
const WORK = '/tmp/windrose-livery-mermaid-e2e';
const LIBRARY_FONT = 'E2E Library Serif';

const wpAt = ( slug, ...args ) =>
	execFileSync( 'wp', [ ...args, `--path=${ WP_PATH }`, `--url=${ ROOT }/${ slug ? slug + '/' : '' }` ], {
		encoding: 'utf8',
	} ).trim();
const wp = ( ...args ) => wpAt( '', ...args );
const cacheKeys = () => JSON.parse( wp( 'eval', 'echo wp_json_encode( Windrose\\Livery\\Remote_Fonts::keys() );' ) );

/** WCAG 2.2 AA checks on part of the page, once animations have ended (not the preview: a sandboxed picture of the style). */
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
/** A computed style in the preview (null while the frame reloads after a change). */
const frameStyle = ( page, selector, prop ) =>
	preview( page )
		.locator( selector )
		.first()
		.evaluate( ( el, p ) => getComputedStyle( el )[ p ], prop, { timeout: 2000 } )
		.catch( () => null );

/** Lists a downloaded zip. */
async function zipEntries( download ) {
	mkdirSync( WORK, { recursive: true } );
	const path = `${ WORK }/${ download.suggestedFilename() }`;
	await download.saveAs( path );
	return execFileSync( 'unzip', [ '-Z1', path ], { encoding: 'utf8' } ).trim().split( '\n' );
}

test.describe.configure( { mode: 'serial' } );
let keysBefore = [];
let arabicPack = false;

/** A font installed in the main site's Font Library, as the Site Editor's Upload would. */
function installLibraryFont() {
	wp(
		'eval',
		`$dir = wp_get_font_dir(); wp_mkdir_p( $dir['path'] ); copy( '${ WP_PATH }/wp-content/themes/ipsum/assets/fonts/besley/besley-var-normal.woff2', $dir['path'] . '/e2e-library-serif.woff2' );
		$f = wp_insert_post( array( 'post_type' => 'wp_font_family', 'post_status' => 'publish', 'post_title' => '${ LIBRARY_FONT }', 'post_name' => 'e2e-library-serif', 'post_content' => wp_json_encode( array( 'fontFamily' => '"${ LIBRARY_FONT }", serif' ) ) ) );
		$s = array( 'fontFamily' => '${ LIBRARY_FONT }', 'fontStyle' => 'normal', 'fontWeight' => '400 900', 'src' => $dir['url'] . '/e2e-library-serif.woff2' );
		$x = wp_insert_post( array( 'post_type' => 'wp_font_face', 'post_status' => 'publish', 'post_parent' => $f, 'post_title' => WP_Font_Utils::get_font_face_slug( $s ), 'post_content' => wp_json_encode( $s ) ) );
		add_post_meta( $x, '_wp_font_face_file', 'e2e-library-serif.woff2' );`
	);
}

/** Removes that font (WordPress deletes its file with its face). */
function removeLibraryFont() {
	wp(
		'eval',
		"foreach ( get_posts( array( 'post_type' => 'wp_font_family', 'name' => 'e2e-library-serif', 'post_status' => 'any' ) ) as $p ) { foreach ( get_children( array( 'post_parent' => $p->ID, 'post_type' => 'wp_font_face' ) ) as $c ) { wp_delete_post( $c->ID, true ); } wp_delete_post( $p->ID, true ); }"
	);
}

test.beforeAll( () => {
	removeLibraryFont();
	installLibraryFont();
	keysBefore = cacheKeys();
	arabicPack = JSON.parse( wp( 'eval', 'echo wp_json_encode( array_keys( Windrose\\Livery\\Packs::installed() ) );' ) ).includes( 'arabic' );
	if ( wp( 'site', 'list', '--field=url' ).split( '\n' ).some( ( u ) => u.endsWith( `/${ SITE }/` ) ) ) {
		wp( 'site', 'delete', `--slug=${ SITE }`, '--yes' );
	}
} );

test.afterAll( () => {
	wp(
		'eval',
		`foreach ( Windrose\\Livery\\Library::all() as $item ) { if ( str_starts_with( $item['title'], '${ TITLE }' ) ) { $u = Windrose\\Livery\\Fonts::unused( array_column( $item['files'], 'stored' ), $item['id'] ); Windrose\\Livery\\Library::delete( $item['id'] ); Windrose\\Livery\\Fonts::delete( $u ); } }`
	);
	removeLibraryFont();
	for ( const key of cacheKeys() ) {
		if ( ! keysBefore.includes( key ) ) {
			wp( 'eval', `Windrose\\Livery\\Remote_Fonts::forget( '${ key }' );` );
		}
	}
	try {
		const id = wp( 'site', 'list', '--field=blog_id', `--path__in=/${ SITE }/` );
		if ( /^\d+$/.test( id ) ) {
			wp( 'site', 'delete', id, '--yes' );
			rmSync( `${ WP_PATH }/wp-content/uploads/sites/${ id }`, { recursive: true, force: true } );
		}
	} catch {}
	rmSync( WORK, { recursive: true, force: true } );
} );

test( 'admin: make a style with a light palette and a Font Library font, add it and download it', async ( { page } ) => {
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
	await page.goto( SCREEN );
	await page.getByRole( 'button', { name: 'Create a style' } ).click();
	await expect( page ).toHaveURL( /view=create/ );
	await expect( preview( page ).locator( 'h1' ) ).toBeVisible();
	await expectAccessible( page, '.windrose-livery-mermaid' );

	// Start: Harbour. The preview uses its heading font, from the plugin.
	await page.getByRole( 'button', { name: /^Harbour/ } ).click();
	await expect.poll( () => frameStyle( page, 'h1', 'fontFamily' ) ).toContain( 'Roboto Slab' );

	// Colors: Ipsum's Evening (dark), then a weak accent and its fix.
	await step( page, 'Colors' );
	await expectAccessible( page, '.windrose-livery-mermaid' );
	await page.getByRole( 'button', { name: /^Evening/ } ).click();
	await expect.poll( () => frameStyle( page, 'body', 'backgroundColor' ) ).toBe( 'rgb(0, 0, 0)' );
	await page.getByRole( 'button', { name: /^Change Accent/ } ).click();
	const hex = page.getByRole( 'textbox', { name: 'Hex color' } );
	await hex.fill( '330000' );
	await hex.press( 'Enter' );
	await page.keyboard.press( 'Escape' );
	const accentRow = page.locator( '.windrose-livery-mermaid__checks li', { hasText: 'Accent as text on Base' } ).first();
	// Livery 0.8: a weak accent fails (patterns put Base text on Accent sections), and Fix mends both pairs.
	await expect( accentRow ).toContainText( 'too low' );
	const surfaceRow = page.locator( '.windrose-livery-mermaid__checks li', { hasText: 'Base on Accent' } ).first();
	await expect( surfaceRow ).toContainText( 'too low' );
	await accentRow.getByRole( 'button', { name: 'Fix' } ).click();
	await expect( accentRow ).toContainText( 'passes' );
	await expect( surfaceRow ).toContainText( 'passes' );
	await expect( page.locator( '.windrose-livery-mermaid__checks li', { hasText: 'Base on Strong Contrast' } ).first() ).toContainText( 'passes' );

	// Light and dark: Evening is dark, so a light palette, checked for contrast, shown in the preview.
	await page.getByRole( 'button', { name: 'Add a light palette' } ).click();
	await expect( page.getByRole( 'heading', { name: 'Contrast in light' } ) ).toBeVisible();
	const lightChecks = page.locator( '.windrose-livery-mermaid__checks' ).nth( 1 );
	await expect( lightChecks.locator( 'li.is-fail' ) ).toHaveCount( 0 );
	await expect( page.getByText( 'Light: Base' ).first() ).toBeVisible();
	await page.getByRole( 'button', { name: 'Dark/light switch' } ).click();
	await expect.poll( async () => {
		const bg = await frameStyle( page, 'body', 'backgroundColor' );
		return bg && bg !== 'rgb(0, 0, 0)' ? 'light' : bg;
	} ).toBe( 'light' );
	await page.getByRole( 'button', { name: 'Dark/light switch' } ).click();
	await expect.poll( () => frameStyle( page, 'body', 'backgroundColor' ) ).toBe( 'rgb(0, 0, 0)' );
	await expectAccessible( page, '.windrose-livery-mermaid' );

	// Fonts: no Google Fonts or Bunny Fonts form for managers; a font of the Font Library as the heading font.
	await step( page, 'Fonts' );
	await expect( page.getByText( 'Get a font from Google Fonts or Bunny Fonts' ) ).toHaveCount( 0 );
	const heading = page.getByRole( 'group', { name: 'Heading font' } );
	await heading.getByRole( 'radio', { name: new RegExp( LIBRARY_FONT ) } ).check();
	await expect.poll( () => frameStyle( page, 'h1', 'fontFamily' ) ).toContain( LIBRARY_FONT );

	// Arabic: from the network's font pack (managers no longer fetch fonts here).
	if ( arabicPack ) {
		await page.getByRole( 'checkbox', { name: /^Arabic/ } ).check();
		await expect( page.getByLabel( 'Heading and text fonts' ) ).toBeVisible( { timeout: 60000 } );
		await expect( preview( page ).locator( 'p[dir="rtl"]' ) ).toBeVisible();
	}
	await expectAccessible( page, '.windrose-livery-mermaid' );

	// Layout and buttons.
	await step( page, 'Layout' );
	await page.getByRole( 'radio', { name: 'Wide 980px' } ).click();
	await expect( page.getByRole( 'spinbutton', { name: 'Text width, any value' } ) ).toHaveValue( '980' );
	await expectAccessible( page, '.windrose-livery-mermaid' );
	await step( page, 'Blocks' );
	await page.getByRole( 'checkbox', { name: 'Keep the preset buttons' } ).uncheck();
	await page.getByRole( 'radio', { name: 'Pill' } ).click();
	await expect.poll( () => frameStyle( page, '.wp-block-button__link', 'borderTopLeftRadius' ) ).toBe( '100px' );
	await step( page, 'Typography' );
	await expectAccessible( page, '.windrose-livery-mermaid' );

	// Finish: add to the library, then download.
	await step( page, 'Finish' );
	await page.getByLabel( 'Title' ).fill( TITLE );
	await expectAccessible( page, '.windrose-livery-mermaid' );
	await page.getByRole( 'button', { name: 'Add to the library' } ).click();
	await expect( page.locator( '.components-notice__content', { hasText: `${ TITLE } added to the library (version 1)` } ) ).toBeVisible( { timeout: 30000 } );
	const [ download ] = await Promise.all( [ page.waitForEvent( 'download' ), page.getByRole( 'button', { name: 'Download' } ).click() ] );
	const entries = await zipEntries( download );
	expect( entries ).toContain( 'e2e-mermaid/e2e-mermaid.json' );
	expect( entries ).toContain( 'e2e-mermaid/fonts.json' );
	expect( entries.some( ( e ) => /fonts\/e2e-library-serif\/e2e-library-serif\.woff2$/.test( e ) ) ).toBe( true );
	if ( arabicPack ) {
		expect( entries.some( ( e ) => /fonts\/beiruti\/beiruti-var-normal\.woff2$/.test( e ) ) ).toBe( true );
	}
	// The light twins travel in the style.
	expect( execFileSync( 'unzip', [ '-p', `${ WORK }/${ download.suggestedFilename() }`, 'e2e-mermaid/e2e-mermaid.json' ], { encoding: 'utf8' } ) ).toContain( 'windrose-livery-light-base' );

	// In the library, for Ipsum (so in Browse styles on Ipsum and its child themes).
	await page.getByRole( 'button', { name: 'Back to the list' } ).click();
	await expect( page.getByText( TITLE, { exact: true } ) ).toBeVisible();
	expect( wp( 'eval', `echo implode( ',', array_column( Windrose\\Livery\\Library::for_theme( 'ipsum', 'ipsum' ), 'title' ) );` ) ).toContain( TITLE );

	expect( external ).toEqual( [] );
	expect( errors ).toEqual( [] );
} );

test( 'public block: download only, no account, no cookie, nothing outside', async ( { browser } ) => {
	test.setTimeout( 120000 );
	wp( 'site', 'create', `--slug=${ SITE }`, '--title=Mermaid E2E' );
	wpAt( SITE, 'theme', 'activate', 'ipsum' );
	wpAt( SITE, 'option', 'update', 'windrose_livery_mermaid', '{"public":true,"remote":false}', '--format=json' );
	const pageId = wpAt( SITE, 'post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Make a style', '--post_content=<!-- wp:windrose/style-mermaid /-->', '--porcelain' );
	const url = wpAt( SITE, 'post', 'list', '--post_type=page', `--post__in=${ pageId }`, '--field=url' );

	const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] }, ignoreHTTPSErrors: true, acceptDownloads: true } );
	const page = await visitor.newPage();
	const external = [];
	page.on( 'request', ( r ) => {
		const u = new URL( r.url() );
		if ( u.protocol.startsWith( 'http' ) && u.hostname !== HOST ) {
			external.push( u.hostname );
		}
	} );
	await page.goto( url );
	await expect( preview( page ).locator( 'h1' ) ).toBeVisible( { timeout: 30000 } );
	await expectAccessible( page, '.windrose-livery-mermaid' );
	await step( page, 'Fonts' );
	await expect( page.getByText( 'Get a font from Google Fonts or Bunny Fonts' ) ).toHaveCount( 0 );
	// Without Google Fonts or Bunny Fonts, other scripts come from installed packs, or the visitor's own fonts.
	if ( arabicPack ) {
		await expect( page.getByRole( 'checkbox', { name: /^Arabic/ } ) ).toBeVisible();
	} else {
		await expect( page.getByText( /other scripts use the visitor’s own fonts/ ) ).toBeVisible();
	}

	await step( page, 'Finish' );
	await expect( page.getByRole( 'button', { name: 'Add to the library' } ) ).toHaveCount( 0 );
	const [ download ] = await Promise.all( [ page.waitForEvent( 'download' ), page.getByRole( 'button', { name: 'Download' } ).click() ] );
	expect( await zipEntries( download ) ).toContain( 'my-style/my-style.json' );

	// Second switch on: font page addresses from both services work for visitors, and the font is used at once.
	wpAt( SITE, 'option', 'update', 'windrose_livery_mermaid', '{"public":true,"remote":true}', '--format=json' );
	await page.reload();
	await expect( preview( page ).locator( 'h1' ) ).toBeVisible( { timeout: 30000 } );
	await step( page, 'Fonts' );
	await page.getByText( 'Get a font from Google Fonts or Bunny Fonts' ).click();
	const done = page.locator( '.windrose-livery-mermaid__done' );
	await page.getByLabel( 'Font name or address' ).fill( 'https://fonts.bunny.net/family/unica-one' );
	await page.getByRole( 'button', { name: 'Get the font' } ).click();
	await expect( done ).toHaveText( 'Unica One (from Bunny Fonts) is now the heading font.', { timeout: 60000 } );
	await expect.poll( () => frameStyle( page, 'h1', 'fontFamily' ) ).toContain( 'Unica One' );
	await page.getByLabel( 'Font name or address' ).fill( 'https://fonts.google.com/specimen/Lobster' );
	await page.getByLabel( 'Use it for' ).selectOption( 'text' );
	await page.getByRole( 'button', { name: 'Get the font' } ).click();
	await expect( done ).toHaveText( 'Lobster (from Google Fonts) is now the text font.', { timeout: 60000 } );
	await expect.poll( () => frameStyle( page, 'main > p', 'fontFamily' ) ).toContain( 'Lobster' );
	await expectAccessible( page, '.windrose-livery-mermaid' );

	// Text the color of the soft background: too little contrast, no download.
	await step( page, 'Colors' );
	await page.getByRole( 'combobox', { name: 'Text', exact: true } ).selectOption( { label: 'Surface' } );
	await step( page, 'Finish' );
	await expect( page.getByRole( 'button', { name: 'Download' } ) ).toBeDisabled();
	await expect( page.locator( '.components-notice__content', { hasText: /do not reach WCAG 2.2 AA yet/ } ) ).toBeVisible();

	expect( await visitor.cookies() ).toEqual( [] );
	expect( external ).toEqual( [] );

	// Switched off: the block shows nothing, the routes and the download refuse.
	wpAt( SITE, 'option', 'update', 'windrose_livery_mermaid', '{"public":false,"remote":false}', '--format=json' );
	await page.goto( url );
	await expect( page.locator( '[data-windrose-livery-mermaid]' ) ).toHaveCount( 0 );
	const catalog = await page.request.get( `${ ROOT }/${ SITE }/wp-json/windrose-livery/v1/mermaid/catalog` );
	expect( [ 401, 403 ] ).toContain( catalog.status() );
	const refused = await page.request.post( `${ ROOT }/${ SITE }/wp-admin/admin-post.php?action=windrose_livery_mermaid`, { form: { recipe: '{}' } } );
	expect( refused.status() ).toBe( 403 );
	await visitor.close();
} );
