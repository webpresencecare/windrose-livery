/**
 * Light and dark (Livery 0.4) (settings: see the README, Development).
 *
 * Two throwaway sites on Ipsum: "scheme-e2e" gets a test variation with dark twins (imported and applied with
 * WP-CLI), "scheme-e2e-editor" gets its dark palette in the Site Editor (Options, Add a dark palette). Visitors: the
 * switch after the header's menu, the choice kept in the browser and applied before the page shows, the browser
 * preference followed. Everything is deleted at the end (sites, variation).
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { test, expect } from '@playwright/test';

const HOST = process.env.WINDROSE_E2E_HOST ?? 'localhost';
const ADMIN = process.env.WINDROSE_E2E_USER ?? 'admin';
const WP_PATH = process.env.WINDROSE_E2E_WP_PATH ?? '/var/www/html/web';
const ROOT = `https://${ HOST }`;
const SITE = 'scheme-e2e';
const EDITOR_SITE = 'scheme-e2e-editor';
const TITLE = 'E2E Night and Day';
const WORK = '/tmp/windrose-livery-scheme-e2e';

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

function createSite( slug ) {
	deleteSite( slug );
	wp( 'site', 'create', `--slug=${ slug }`, `--title=${ slug }` );
	wpAt( slug, 'theme', 'activate', 'ipsum' );
}

function removeVariation() {
	wp(
		'eval',
		`foreach ( Windrose\\Livery\\Library::all() as $item ) { if ( $item['title'] === '${ TITLE }' ) { $u = Windrose\\Livery\\Fonts::unused( array_column( $item['files'], 'stored' ), $item['id'] ); Windrose\\Livery\\Library::delete( $item['id'] ); Windrose\\Livery\\Fonts::delete( $u ); } }`
	);
}

/** WCAG 2.2 AA checks on part of the page. */
async function expectAccessible( page, selector ) {
	const results = await new AxeBuilder( { page } )
		.include( selector )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.analyze();
	expect( results.violations.map( ( v ) => `${ v.id }: ${ v.nodes.map( ( n ) => n.target ).join( ', ' ) }` ) ).toEqual( [] );
}

const bodyColor = ( page ) => page.evaluate( () => getComputedStyle( document.body ).backgroundColor );

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
	removeVariation();
	createSite( SITE );
	createSite( EDITOR_SITE );
	mkdirSync( WORK, { recursive: true } );
	const file = `${ WORK }/night-and-day.json`;
	writeFileSync(
		file,
		JSON.stringify( {
			version: 3,
			title: TITLE,
			settings: {
				color: {
					palette: [
						{ slug: 'base', name: 'Background', color: '#fdfbf7' },
						{ slug: 'contrast', name: 'Text', color: '#1d1d1f' },
						{ slug: 'accent-1', name: 'Accent', color: '#9b2d20' },
						{ slug: 'windrose-livery-dark-base', name: 'Dark: Background', color: '#16181d' },
						{ slug: 'windrose-livery-dark-contrast', name: 'Dark: Text', color: '#ececf1' },
						{ slug: 'windrose-livery-dark-accent-1', name: 'Dark: Accent', color: '#ff9d8a' },
					],
				},
			},
			styles: { color: { background: 'var:preset|color|base', text: 'var:preset|color|contrast' } },
		} )
	);
	wp(
		'eval',
		`$s = Windrose\\Livery\\Importer::import( '${ file }', 'night-and-day.json', array( 'dry_run' => false, 'theme' => 'ipsum' ) ); $site = get_id_from_blogname( '${ SITE }' ); Windrose\\Livery\\Applier::apply( Windrose\\Livery\\Library::get( $s['id'] ), array( $site ) );`,
		`--user=${ ADMIN }`
	);
	wpAt( SITE, 'option', 'update', 'windrose_livery_scheme', '{"switch":true,"follow":false}', '--format=json' );
} );

test.afterAll( () => {
	removeVariation();
	deleteSite( SITE );
	deleteSite( EDITOR_SITE );
	rmSync( WORK, { recursive: true, force: true } );
} );

test( 'the twins are custom colours, apart from the style’s own', async () => {
	const palette = JSON.parse(
		wpAt( SITE, 'eval', "echo wp_json_encode( wp_get_global_settings( array( 'color', 'palette' ) ) );" )
	);
	expect( palette.theme.map( ( c ) => c.slug ) ).toEqual( [ 'base', 'contrast', 'accent-1' ] );
	expect( palette.custom.map( ( c ) => c.name ) ).toEqual( [ 'Dark: Background', 'Dark: Text', 'Dark: Accent' ] );
} );

test( 'the Site Editor lists the twins under Custom in the palette', async ( { page } ) => {
	await page.goto( `${ ROOT }/${ SITE }/wp-admin/site-editor.php?p=%2Fstyles&section=%2Fcolors%2Fpalette` );
	const custom = page.getByRole( 'heading', { name: 'Custom', exact: true } );
	await expect( custom ).toBeVisible( { timeout: 60000 } );
	await page.screenshot( { path: test.info().outputPath( 'palette-custom.png' ) } );
	await expect( page.getByRole( 'heading', { name: 'Theme', exact: true } ) ).toBeVisible();
} );

test( 'visitors switch to dark after the header menu, and keep their choice', async ( { browser } ) => {
	const context = await browser.newContext( { storageState: { cookies: [], origins: [] }, ignoreHTTPSErrors: true, colorScheme: 'light' } );
	const page = await context.newPage();
	const external = [];
	page.on( 'request', ( r ) => {
		const u = new URL( r.url() );
		if ( u.protocol.startsWith( 'http' ) && u.hostname !== HOST ) {
			external.push( u.hostname );
		}
	} );
	await page.goto( `${ ROOT }/${ SITE }/` );
	const button = page.locator( 'header [data-windrose-livery-switch]' ).first();
	await expect( button, 'Placed in the header by core’s Block Hooks' ).toBeVisible();
	await expect( button ).toHaveAccessibleName( 'Dark mode' );
	await expect( button ).toHaveAttribute( 'aria-pressed', 'false' );
	expect( await bodyColor( page ) ).toBe( 'rgb(253, 251, 247)' );
	await expect( page.locator( 'header nav.wp-block-navigation + .windrose-livery-switch' ), 'Right after the menu' ).toHaveCount( 1 );
	// Only the switch: core's fallback menu (a page list inside the menu's list) has its own axe finding.
	await expectAccessible( page, '.windrose-livery-switch' );

	// With the keyboard.
	await button.focus();
	await page.keyboard.press( 'Enter' );
	await expect( button ).toHaveAttribute( 'aria-pressed', 'true' );
	await expect( page.locator( 'html' ) ).toHaveAttribute( 'data-windrose-livery-scheme', 'dark' );
	expect( await bodyColor( page ) ).toBe( 'rgb(22, 24, 29)' );
	await expectAccessible( page, '.windrose-livery-switch' );

	// The next page opens dark: the head script sets the scheme before the body exists.
	const early = [];
	await page.exposeFunction( 'windroseEarly', ( v ) => early.push( v ) );
	await page.addInitScript( () => {
		document.addEventListener( 'readystatechange', () => {
			if ( 'interactive' === document.readyState ) {
				window.windroseEarly( document.documentElement.getAttribute( 'data-windrose-livery-scheme' ) );
			}
		} );
	} );
	await page.reload();
	expect( early[ 0 ] ).toBe( 'dark' );
	expect( await bodyColor( page ) ).toBe( 'rgb(22, 24, 29)' );
	expect( await page.evaluate( () => window.localStorage.getItem( 'windrose-livery-scheme' ) ) ).toBe( 'dark' );
	expect( ( await context.cookies() ).filter( ( c ) => c.name.includes( 'livery' ) ) ).toEqual( [] );
	// The server renders the button unpressed; the module corrects it after the reload.
	await expect( page.locator( 'header [data-windrose-livery-switch]' ).first() ).toHaveAttribute( 'aria-pressed', 'true' );

	// Back to light.
	await page.locator( 'header [data-windrose-livery-switch]' ).first().click();
	expect( await bodyColor( page ) ).toBe( 'rgb(253, 251, 247)' );
	expect( external ).toEqual( [] );
	await context.close();
} );

test( 'following the browser preference, without the switch', async ( { browser } ) => {
	wpAt( SITE, 'option', 'update', 'windrose_livery_scheme', '{"switch":false,"follow":true}', '--format=json' );
	for ( const [ scheme, color ] of [
		[ 'dark', 'rgb(22, 24, 29)' ],
		[ 'light', 'rgb(253, 251, 247)' ],
	] ) {
		const context = await browser.newContext( { storageState: { cookies: [], origins: [] }, ignoreHTTPSErrors: true, colorScheme: scheme, javaScriptEnabled: false } );
		const page = await context.newPage();
		await page.goto( `${ ROOT }/${ SITE }/` );
		expect( await page.locator( '[data-windrose-livery-switch]' ).count() ).toBe( 0 );
		expect( await bodyColor( page ), `${ scheme }, even without JavaScript` ).toBe( color );
		await context.close();
	}
	wpAt( SITE, 'option', 'update', 'windrose_livery_scheme', '{"switch":false,"follow":false}', '--format=json' );
} );

test( 'site editor: add a dark palette, check it, save it, preview it', async ( { page } ) => {
	test.setTimeout( 120000 );
	await page.goto( `${ ROOT }/${ EDITOR_SITE }/wp-admin/site-editor.php?canvas=edit` );
	await page.waitForFunction( () => window.wp?.data?.select( 'core' ) );
	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	// WordPress 7.2 lists plugin items in the Options menu (⋮) under Panels.
	const menu = async ( name ) => {
		await page.getByRole( 'button', { name: 'Options' } ).first().click();
		await page.getByRole( 'menuitem', { name: 'Panels' } ).hover();
		await page.getByRole( 'menuitem', { name } ).click();
	};
	await expect( canvas.locator( 'body' ) ).toBeVisible( { timeout: 60000 } );
	const own = await canvas.locator( 'body' ).evaluate( ( b ) => getComputedStyle( b ).backgroundColor );

	await menu( 'Add a dark palette' );
	const dialog = page.getByRole( 'dialog', { name: 'Add a dark palette' } );
	await expect( dialog.getByText( 'Dark: Base' ) ).toBeVisible();
	await expect( dialog.getByRole( 'heading', { name: 'Contrast in dark' } ) ).toBeVisible();
	await expect( dialog.locator( 'li.is-fail' ) ).toHaveCount( 0 );
	await expectAccessible( page, '[role="dialog"]' );
	await dialog.getByRole( 'button', { name: 'Add the colours' } ).click();
	await expect( page.locator( '.components-snackbar' ).getByText( 'Dark palette added to Styles, Colors, Palette (Custom). Save to keep it.' ) ).toBeVisible();

	// Saved as core's Save button saves the styles (its entity, through core's data store).
	await page.evaluate( async () => {
		const core = window.wp.data.select( 'core' );
		await window.wp.data.dispatch( 'core' ).saveEditedEntityRecord( 'root', 'globalStyles', core.__experimentalGetCurrentGlobalStylesId() );
	} );
	await expect.poll( () => wpAt( EDITOR_SITE, 'eval', `echo Windrose\\Livery\\Schemes::current()['scheme'];` ), { timeout: 30000 } ).toBe( 'dark' );

	// Preview dark: the canvas shows the twins.
	await menu( 'Preview dark' );
	await expect.poll( () => canvas.locator( 'body' ).evaluate( ( b ) => getComputedStyle( b ).backgroundColor ) ).not.toBe( own );
	await menu( 'Stop the preview' );
	await expect.poll( () => canvas.locator( 'body' ).evaluate( ( b ) => getComputedStyle( b ).backgroundColor ) ).toBe( own );
} );
