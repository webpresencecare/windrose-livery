/**
 * Site administrators (Livery 0.4) (settings: see the README, Development).
 *
 * A throwaway site "siteadmin-e2e" on Ipsum and a throwaway user, administrator of that site only. Off: no screen.
 * On (the network switch, set on the network screen): Appearance, Style variations on their site, an import into
 * their site's own styles with its fonts in their site's Font Library, their site's switches only, the Site Editor
 * items, the warning before deleting a font in use. Then a network administrator copies it into the shared library.
 * Everything is deleted at the end: the user, the site, the shared copy; the network switch goes back to how it was.
 */
import { execFileSync } from 'node:child_process';
import { copyFileSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { test, expect } from '@playwright/test';

const HOST = process.env.WINDROSE_E2E_HOST ?? 'localhost';
const WP_PATH = process.env.WINDROSE_E2E_WP_PATH ?? '/var/www/html/web';
const ROOT = `https://${ HOST }`;
const SITE = 'siteadmin-e2e';
const USER = 'liverye2esiteadmin';
const PASS = `e2e-${ Date.now() }-Pw!`;
const TITLE = 'E2E Site Own';
const WORK = '/tmp/windrose-livery-siteadmin-e2e';
const NETWORK_SCREEN = '/wp-admin/network/themes.php?page=windrose-livery';

const wpAt = ( slug, ...args ) =>
	execFileSync( 'wp', [ ...args, `--path=${ WP_PATH }`, `--url=${ ROOT }/${ slug ? slug + '/' : '' }` ], {
		encoding: 'utf8',
	} ).trim();
const wp = ( ...args ) => wpAt( '', ...args );

let switchBefore = '';
let siteId = 0;

function cleanUp() {
	wp( 'eval', `foreach ( Windrose\\Livery\\Library::all() as $i ) { if ( '${ TITLE }' === $i['title'] ) { $u = Windrose\\Livery\\Fonts::unused( array_column( $i['files'], 'stored' ), $i['id'] ); Windrose\\Livery\\Library::delete( $i['id'] ); Windrose\\Livery\\Fonts::delete( $u ); } }` );
	try {
		wp( 'user', 'delete', USER, '--network', '--yes' );
	} catch {}
	try {
		const id = wp( 'site', 'list', '--field=blog_id', `--path__in=/${ SITE }/` );
		if ( /^\d+$/.test( id ) ) {
			wp( 'site', 'delete', id, '--yes' );
			rmSync( `${ WP_PATH }/wp-content/uploads/sites/${ id }`, { recursive: true, force: true } );
		}
	} catch {}
}

function makePackage() {
	const dir = `${ WORK }/pkg/e2e-site-own`;
	mkdirSync( `${ dir }/fonts/besley-own`, { recursive: true } );
	writeFileSync(
		`${ dir }/e2e-site-own.json`,
		JSON.stringify( {
			version: 3,
			title: TITLE,
			settings: { color: { palette: [ { slug: 'base', name: 'Mist', color: '#eef3f6' }, { slug: 'contrast', name: 'Ink', color: '#14202b' } ] } },
			styles: { typography: { fontFamily: 'var:preset|font-family|besley-own' } },
		} )
	);
	writeFileSync(
		`${ dir }/fonts.json`,
		JSON.stringify( {
			fontFamilies: [
				{
					name: 'Besley Own',
					slug: 'besley-own',
					fontFamily: '"Besley Own", serif',
					fontFace: [ { fontFamily: 'Besley Own', fontStyle: 'normal', fontWeight: '400 900', src: [ 'fonts/besley-own/besley-own.woff2' ] } ],
				},
			],
		} )
	);
	copyFileSync( `${ WP_PATH }/wp-content/themes/ipsum/assets/fonts/besley/besley-var-normal.woff2`, `${ dir }/fonts/besley-own/besley-own.woff2` );
	writeFileSync( `${ dir }/fonts/besley-own/OFL.txt`, 'SIL Open Font License 1.1 (test copy)\n' );
	const zip = `${ WORK }/e2e-site-own.zip`;
	execFileSync( 'zip', [ '-qr', zip, 'e2e-site-own' ], { cwd: `${ WORK }/pkg` } );
	return zip;
}

async function expectAccessible( page, selector ) {
	await page.waitForFunction( () => document.getAnimations().every( ( a ) => 'running' !== a.playState ) );
	const results = await new AxeBuilder( { page } )
		.include( selector )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.analyze();
	expect( results.violations.map( ( v ) => `${ v.id }: ${ v.nodes.map( ( n ) => n.target ).join( ', ' ) }` ) ).toEqual( [] );
}

async function siteAdminPage( browser ) {
	const context = await browser.newContext( { storageState: { cookies: [], origins: [] }, ignoreHTTPSErrors: true } );
	const page = await context.newPage();
	await page.goto( `${ ROOT }/${ SITE }/wp-login.php` );
	await page.getByLabel( 'Username or Email Address' ).fill( USER );
	await page.getByLabel( 'Password', { exact: true } ).fill( PASS );
	await page.getByRole( 'button', { name: 'Log In' } ).click();
	await expect( page ).toHaveURL( /wp-admin/ );
	return { context, page };
}

test.describe.configure( { mode: 'serial' } );

test.beforeAll( () => {
	cleanUp();
	switchBefore = wp( 'eval', "echo wp_json_encode( get_site_option( 'windrose_livery_network', null ) );" );
	wp( 'eval', "delete_site_option( 'windrose_livery_network' );" );
	wp( 'site', 'create', `--slug=${ SITE }`, `--title=Site admin E2E` );
	wpAt( SITE, 'theme', 'activate', 'ipsum' );
	siteId = Number( wp( 'site', 'list', '--field=blog_id', `--path__in=/${ SITE }/` ) );
	wpAt( SITE, 'user', 'create', USER, `${ USER }@example.com`, '--role=administrator', `--user_pass=${ PASS }` );
	mkdirSync( WORK, { recursive: true } );
} );

test.afterAll( () => {
	cleanUp();
	if ( switchBefore && 'null' !== switchBefore ) {
		wp( 'eval', `update_site_option( 'windrose_livery_network', json_decode( '${ switchBefore }', true ) );` );
	} else {
		wp( 'eval', "delete_site_option( 'windrose_livery_network' );" );
	}
	rmSync( WORK, { recursive: true, force: true } );
} );

test( 'off by default: a site administrator has no Livery screen', async ( { browser } ) => {
	const { context, page } = await siteAdminPage( browser );
	await page.goto( `${ ROOT }/${ SITE }/wp-admin/themes.php` );
	await expect( page.locator( '#menu-appearance' ).getByRole( 'link', { name: 'Style variations' } ) ).toHaveCount( 0 );
	const answer = await page.goto( `${ ROOT }/${ SITE }/wp-admin/themes.php?page=windrose-livery` );
	expect( [ 403, 404 ] ).toContain( answer.status() );
	await expect( page.locator( '#windrose-livery-app' ) ).toHaveCount( 0 );
	await context.close();
} );

test( 'a network administrator turns it on', async ( { page } ) => {
	await page.goto( NETWORK_SCREEN );
	const toggle = page.getByRole( 'checkbox', { name: 'Site administrators may use Livery on their own site' } );
	await expect( toggle ).not.toBeChecked();
	await toggle.click();
	await expect( toggle ).toBeChecked();
	await expect( page.getByText( 'Site administrators now find Appearance, Style variations on their own site.' ).first() ).toBeVisible();
	expect( wp( 'eval', "echo wp_json_encode( get_site_option( 'windrose_livery_network' ) );" ) ).toContain( '"site_admins":true' );
} );

test( 'on: their own styles, fonts in their Font Library, their switches only', async ( { browser } ) => {
	test.setTimeout( 120000 );
	const { context, page } = await siteAdminPage( browser );
	await page.goto( `${ ROOT }/${ SITE }/wp-admin/themes.php` );
	await page.locator( '#menu-appearance' ).getByRole( 'link', { name: 'Style variations' } ).click();
	await expect( page.getByText( 'These are Site admin E2E’s own style variations' ) ).toBeVisible();
	await expect( page.getByRole( 'heading', { name: 'Font packs' } ) ).toHaveCount( 0 );
	await expect( page.getByRole( 'heading', { name: 'Site administrators' } ) ).toHaveCount( 0 );
	await expect( page.getByRole( 'heading', { name: 'This site’s switches' } ) ).toBeVisible();
	await expect( page.getByRole( 'checkbox', { name: 'Fonts from Google Fonts and Bunny Fonts for visitors' } ) ).toBeDisabled();
	await expectAccessible( page, '#windrose-livery-app' );

	// Import into the site's own styles.
	await page.getByRole( 'button', { name: 'Import', exact: true } ).click();
	const dialog = page.getByRole( 'dialog', { name: 'Import a style variation' } );
	await dialog.getByLabel( 'File' ).setInputFiles( makePackage() );
	await dialog.getByRole( 'button', { name: 'Check' } ).click();
	await expect( dialog.getByText( 'Besley Own (1 file)' ) ).toBeVisible();
	await dialog.getByRole( 'button', { name: 'Add variation' } ).click();
	await expect( page.getByText( TITLE, { exact: true } ) ).toBeVisible( { timeout: 30000 } );

	// Stored on their site, fonts in their site's Font Library folder, registered there; nothing in the shared library.
	const own = JSON.parse( wp( 'eval', `echo wp_json_encode( Windrose\\Livery\\Library::with_scope( ${ siteId }, fn() => array_map( fn( $i ) => array( $i['title'], $i['files'][0]['stored'] ?? '' ), Windrose\\Livery\\Library::all() ) ) );` ) );
	expect( own.map( ( o ) => o[ 0 ] ) ).toEqual( [ TITLE ] );
	expect( wp( 'eval', `echo is_file( WP_CONTENT_DIR . '/uploads/sites/${ siteId }/fonts/${ own[ 0 ][ 1 ] }' ) ? 'yes' : 'no';` ) ).toBe( 'yes' );
	expect( wpAt( SITE, 'post', 'list', '--post_type=wp_font_family', '--field=post_name' ) ).toContain( 'besley-own' );
	expect( wp( 'eval', `echo count( array_filter( Windrose\\Livery\\Library::all(), fn( $i ) => '${ TITLE }' === $i['title'] ) );` ) ).toBe( '0' );

	// Their Site Editor: offered there; "Save styles as a variation" goes to this site (no choice); light and dark.
	await page.goto( `${ ROOT }/${ SITE }/wp-admin/site-editor.php?canvas=edit` );
	await page.waitForFunction( () => window.wp?.data?.select( 'core' ) );
	const titles = await page.evaluate( async () =>
		( await window.wp.data.resolveSelect( 'core' ).__experimentalGetCurrentThemeGlobalStylesVariations() ).map( ( v ) => v.title )
	);
	expect( titles ).toContain( TITLE );
	await page.getByRole( 'button', { name: 'Options' } ).first().click();
	await page.getByRole( 'menuitem', { name: 'Panels' } ).hover();
	await expect( page.getByRole( 'menuitem', { name: 'Add a dark palette' } ) ).toBeVisible();
	await page.getByRole( 'menuitem', { name: 'Save styles as a variation' } ).click();
	const save = page.getByRole( 'dialog', { name: 'Save styles as a variation' } );
	await expect( save.getByRole( 'radio' ) ).toHaveCount( 0 );
	await page.keyboard.press( 'Escape' );

	// Deleting the font in Manage fonts: core's route answers with Livery's warning first.
	const familyId = Number( wpAt( SITE, 'post', 'list', '--post_type=wp_font_family', '--name=besley-own', '--field=ID' ) );
	const first = await page.evaluate( ( id ) => window.wp.apiFetch( { path: `/wp/v2/font-families/${ id }?force=true`, method: 'DELETE' } ).then( () => 'deleted', ( e ) => e.message ), familyId );
	expect( first ).toContain( 'This font is used by the style variations “E2E Site Own”.' );
	expect( first ).toContain( 'Delete it again within 10 minutes to delete it anyway.' );
	expect( wpAt( SITE, 'post', 'list', '--post_type=wp_font_family', '--field=post_name' ) ).toContain( 'besley-own' );
	await context.close();
} );

test( 'a network administrator copies it into the shared library', async ( { page } ) => {
	await page.goto( NETWORK_SCREEN );
	const card = page.locator( '.windrose-livery__card' ).filter( { has: page.getByRole( 'heading', { name: 'Sites’ own styles' } ) } );
	const row = card.locator( 'li' ).filter( { hasText: TITLE } );
	await expect( row ).toContainText( 'Site admin E2E' );
	await row.getByRole( 'button', { name: 'Copy to the shared library' } ).click();
	const dialog = page.getByRole( 'dialog', { name: `Copy ${ TITLE } to the shared library` } );
	await expect( dialog.getByText( 'keeps its own' ) ).toBeVisible();
	await expectAccessible( page, '[role="dialog"]' );
	await dialog.getByRole( 'button', { name: 'Copy', exact: true } ).click();
	await expect( page.getByText( `${ TITLE } is in the shared library (version 1).` ).first() ).toBeVisible();
	expect( wp( 'eval', `echo count( array_filter( Windrose\\Livery\\Library::all(), fn( $i ) => '${ TITLE }' === $i['title'] ) );` ) ).toBe( '1' );
	expect( wp( 'post', 'list', '--post_type=wp_font_family', '--field=post_name' ) ).toContain( 'besley-own' );
} );
