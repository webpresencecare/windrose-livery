/**
 * Style Mermaid's Blocks step (Livery 0.5) (settings: see the README, Development).
 *
 * Admin: a style from a ready one with a look for every entry (buttons, corners, images, quotes, separator, menu,
 * code), each checked in the preview, which scrolls to the block of the open panel; added to the library.
 * Then applied (WP-CLI) to a throwaway site on Ipsum: the same looks on a real page, and the Site Editor shows them
 * as its own block styles (Styles, Blocks). Everything is deleted at the end (site, variation and its unused fonts).
 */
import { execFileSync } from 'node:child_process';
import { rmSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { test, expect } from '@playwright/test';

const HOST = process.env.WINDROSE_E2E_HOST ?? 'localhost';
const ADMIN = process.env.WINDROSE_E2E_USER ?? 'admin';
const WP_PATH = process.env.WINDROSE_E2E_WP_PATH ?? '/var/www/html/web';
const ROOT = `https://${ HOST }`;
const SCREEN = '/wp-admin/network/themes.php?page=windrose-livery';
const TITLE = 'E2E Block Looks';
const SITE = 'looks-e2e';

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

function removeVariation() {
	wp(
		'eval',
		`foreach ( Windrose\\Livery\\Library::all() as $item ) { if ( $item['title'] === '${ TITLE }' ) { $u = Windrose\\Livery\\Fonts::unused( array_column( $item['files'], 'stored' ), $item['id'] ); Windrose\\Livery\\Library::delete( $item['id'] ); Windrose\\Livery\\Fonts::delete( $u ); } }`
	);
}

/** WCAG 2.2 AA checks on part of the page, once animations have ended (not the preview frame). */
async function expectAccessible( page, selector ) {
	await page.waitForFunction( () => document.getAnimations().every( ( a ) => 'running' !== a.playState ) );
	const results = await new AxeBuilder( { page } )
		.include( selector )
		.exclude( 'iframe' )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.analyze();
	expect( results.violations.map( ( v ) => `${ v.id }: ${ v.nodes.map( ( n ) => n.target ).join( ', ' ) }` ) ).toEqual( [] );
}

const step = ( page, name ) => page.getByRole( 'navigation', { name: 'Steps' } ).getByRole( 'button', { name } ).click();
const preview = ( page ) => page.frameLocator( 'iframe[title="Preview of the style"]' );
/** A computed style in the preview (null while the frame reloads after a change). */
const frameStyle = ( page, selector, prop, pseudo = null ) =>
	preview( page )
		.locator( selector )
		.first()
		.evaluate( ( el, [ p, ps ] ) => getComputedStyle( el, ps )[ p ], [ prop, pseudo ], { timeout: 2000 } )
		.catch( () => null );
/** Opens a panel of the Blocks step and gives it its own look (unticks "Keep … of the ready style"); returns the panel. */
async function openLook( page, panel, keep ) {
	const toggle = page.getByRole( 'button', { name: panel, exact: true } );
	if ( 'true' !== ( await toggle.getAttribute( 'aria-expanded' ) ) ) {
		await toggle.click();
	}
	const body = page.locator( '.components-panel__body', { has: toggle } );
	await body.getByRole( 'checkbox', { name: keep } ).uncheck();
	return body;
}
/** Picks an option of a toggle group inside a panel. */
const pick = ( panel, group, option ) =>
	panel.getByRole( 'radiogroup', { name: group, exact: true } ).getByRole( 'radio', { name: option, exact: true } ).click();

/** The "Keep the preset …" box of each entry. */
const KEEP = Object.fromEntries(
	Object.entries( { button: 'buttons', corners: 'corners', images: 'images', quotes: 'quotes', separator: 'separator', menu: 'menu', code: 'code' } ).map( ( [ id, word ] ) => [ id, `Keep the preset ${ word }` ] )
);

/** The page content of the throwaway site (core blocks only). */
const CONTENT = `<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Go</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
<!-- wp:image {"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="${ ROOT }/wp-content/themes/ipsum/assets/images/ipsum-capital.webp" alt="A capital"/></figure><!-- /wp:image -->
<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Quoted words.</p><!-- /wp:paragraph --></blockquote><!-- /wp:quote -->
<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator -->
<!-- wp:code --><pre class="wp-block-code"><code>let x = 1;</code></pre><!-- /wp:code -->`;

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
	removeVariation();
	deleteSite( SITE );
} );

test.afterAll( () => {
	removeVariation();
	deleteSite( SITE );
} );

test( 'admin: a look for every entry, checked in the preview, added to the library', async ( { page } ) => {
	test.setTimeout( 180000 );
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	await page.goto( ROOT + SCREEN );
	await page.getByRole( 'button', { name: 'Create a style' } ).click();
	await expect( preview( page ).locator( 'h1' ) ).toBeVisible( { timeout: 30000 } );
	await page.getByRole( 'button', { name: /^Harbour/ } ).click();
	await step( page, 'Blocks' );
	await expect( page.getByRole( 'heading', { name: 'Blocks', level: 2 } ) ).toBeVisible();
	let panel;
	await expectAccessible( page, '.windrose-livery-mermaid' );

	// Buttons (open first): pill, outlined, accent, large.
	panel = await openLook( page, 'Buttons', KEEP.button );
	await pick( panel, 'Shape', 'Pill' );
	await pick( panel, 'Fill', 'Outlined' );
	await pick( panel, 'Color', 'Accent' );
	await pick( panel, 'Hover color', 'Contrast' );
	await pick( panel, 'Size', 'Large' );
	await expect.poll( () => frameStyle( page, '.wp-block-button__link', 'borderTopLeftRadius' ) ).toBe( '100px' );
	await expect.poll( () => frameStyle( page, '.wp-block-button__link', 'backgroundColor' ) ).toBe( 'rgba(0, 0, 0, 0)' );
	// On hover the outlined button fills with the hover color (Contrast).
	const textColor = await frameStyle( page, 'p', 'color' );
	await preview( page ).locator( '.wp-block-button__link' ).first().hover();
	await expect.poll( () => frameStyle( page, '.wp-block-button__link', 'backgroundColor' ) ).toBe( textColor );
	await page.mouse.move( 0, 0 );
	await expect( page.locator( '.windrose-livery-mermaid__diagram' ).first() ).toBeVisible();
	await expectAccessible( page, '.windrose-livery-mermaid' );

	// Corners and images: the preview scrolls to the picture.
	panel = await openLook( page, 'Corners', KEEP.corners );
	await pick( panel, 'Corners', 'Round' );
	await expect.poll( () => frameStyle( page, '.wp-block-image img', 'borderTopLeftRadius' ) ).toBe( '16px' );
	await expect.poll( () => preview( page ).locator( 'html' ).evaluate( () => window.scrollY ).catch( () => 0 ) ).toBeGreaterThan( 0 );
	panel = await openLook( page, 'Images', KEEP.images );
	await pick( panel, 'Frame', 'Shadow' );
	await expect.poll( () => frameStyle( page, '.wp-block-image img', 'boxShadow' ) ).not.toBe( 'none' );

	// Quotes: lines above and below, no box.
	panel = await openLook( page, 'Quotes', KEEP.quotes );
	await pick( panel, 'Look', 'Lines' );
	await expect.poll( () => frameStyle( page, '.wp-block-quote', 'borderTopWidth' ) ).toBe( '1px' );
	await expect.poll( () => frameStyle( page, '.wp-block-quote', 'borderLeftWidth' ) ).toBe( '0px' );
	await expect.poll( () => frameStyle( page, '.wp-block-pullquote', 'backgroundColor' ) ).toBe( 'rgba(0, 0, 0, 0)' );

	// Separator, menu, code.
	panel = await openLook( page, 'Separator', KEEP.separator );
	await pick( panel, 'Thickness', 'Thick' );
	await expect.poll( () => frameStyle( page, '.wp-block-separator', 'borderBottomWidth' ) ).toBe( '3px' );
	panel = await openLook( page, 'Menu', KEEP.menu );
	await pick( panel, 'Letters', 'Capitals' );
	await expect.poll( () => frameStyle( page, '.wp-block-navigation-item__content', 'textTransform' ) ).toBe( 'uppercase' );
	panel = await openLook( page, 'Code', KEEP.code );
	await pick( panel, 'Look', 'Surface' );
	const soft = await frameStyle( page, '.wp-block-group.has-accent-5-background-color', 'backgroundColor' );
	await expect.poll( () => frameStyle( page, '.wp-block-code', 'backgroundColor' ) ).toBe( soft );
	await expectAccessible( page, '.windrose-livery-mermaid' );
	await page.screenshot( { path: test.info().outputPath( 'blocks-step.png' ), fullPage: true } );

	await step( page, 'Finish' );
	await page.getByLabel( 'Title' ).fill( TITLE );
	await page.getByRole( 'button', { name: 'Add to the library' } ).click();
	await expect( page.locator( '.components-notice__content', { hasText: `${ TITLE } added to the library (version 1)` } ) ).toBeVisible( { timeout: 30000 } );
	expect( errors ).toEqual( [] );
} );

test( 'small screen: changing a look keeps the page where it is', async ( { page } ) => {
	test.setTimeout( 120000 );
	await page.setViewportSize( { width: 1280, height: 700 } );
	await page.goto( ROOT + SCREEN );
	await page.getByRole( 'button', { name: 'Create a style' } ).click();
	await expect( preview( page ).locator( 'h1' ) ).toBeVisible( { timeout: 30000 } );
	await page.getByRole( 'button', { name: /^Harbour/ } ).click();
	await step( page, 'Blocks' );
	const panel = await openLook( page, 'Menu', KEEP.menu );
	const gap = panel.getByRole( 'radiogroup', { name: 'Space between items' } );
	await gap.scrollIntoViewIfNeeded();
	await page.waitForTimeout( 1500 );
	const before = await page.evaluate( () => window.scrollY );
	expect( before ).toBeGreaterThan( 0 );
	for ( const option of [ 'Large', 'Small' ] ) {
		const was = await frameStyle( page, '.wp-block-navigation-is-layout-flex', 'columnGap' );
		await gap.getByRole( 'radio', { name: option, exact: true } ).click();
		await expect.poll( async () => { const now = await frameStyle( page, '.wp-block-navigation-is-layout-flex', 'columnGap' ); return null !== now && now !== was; } ).toBe( true );
		await page.waitForTimeout( 800 );
		expect( Math.abs( ( await page.evaluate( () => window.scrollY ) ) - before ) ).toBeLessThan( 3 );
	}
} );

test( 'applied to a site: the looks on a real page, and in the Site Editor as its own block styles', async ( { page } ) => {
	test.setTimeout( 120000 );
	wp( 'site', 'create', `--slug=${ SITE }`, '--title=Looks E2E' );
	wpAt( SITE, 'theme', 'activate', 'ipsum' );
	const applied = wp(
		'eval',
		`$item = null; foreach ( Windrose\\Livery\\Library::all() as $i ) { if ( $i['title'] === '${ TITLE }' ) { $item = Windrose\\Livery\\Library::get( $i['id'] ); } } $r = Windrose\\Livery\\Applier::apply( $item, array( get_id_from_blogname( '${ SITE }' ) ) ); echo $r[0]['status'];`,
		`--user=${ ADMIN }`
	);
	expect( applied ).toBe( 'applied' );
	const pageId = wpAt( SITE, 'post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Looks', `--post_content=${ CONTENT }`, `--user=${ ADMIN }`, '--porcelain' );
	const url = wpAt( SITE, 'post', 'list', '--post_type=page', `--post__in=${ pageId }`, '--field=url' );

	await page.goto( url );
	const css = ( selector, prop, pseudo = null ) =>
		page.locator( selector ).first().evaluate( ( el, [ p, ps ] ) => getComputedStyle( el, ps )[ p ], [ prop, pseudo ] );
	expect( await css( '.wp-block-button__link', 'borderTopLeftRadius' ) ).toBe( '100px' );
	expect( await css( '.wp-block-image img', 'borderTopLeftRadius' ) ).toBe( '16px' );
	expect( await css( '.wp-block-image img', 'boxShadow' ) ).not.toBe( 'none' );
	expect( await css( '.wp-block-quote', 'borderTopWidth' ) ).toBe( '1px' );
	expect( await css( '.wp-block-quote', 'backgroundColor' ) ).toBe( 'rgba(0, 0, 0, 0)' );
	expect( await css( '.wp-block-separator', 'borderBottomWidth' ) ).toBe( '3px' );
	expect( await css( '.wp-block-navigation-item__content', 'textTransform' ) ).toBe( 'uppercase' );

	// The Site Editor shows the Quote's border as its own (Styles, Blocks, Quote), nothing from Livery.
	await page.goto( `${ ROOT }/${ SITE }/wp-admin/site-editor.php?p=%2Fstyles&section=%2Fblocks%2Fcore%252Fquote` );
	const sidebar = page.locator( '.editor-global-styles-sidebar, .edit-site-global-styles-sidebar, [aria-label="Styles"]' ).first();
	await expect( sidebar.getByRole( 'heading', { name: 'Quote' } ) ).toBeVisible( { timeout: 60000 } );
	await expect( sidebar.getByText( 'Border', { exact: true } ).first() ).toBeVisible();
	await page.screenshot( { path: test.info().outputPath( 'site-editor-quote.png' ) } );
} );
