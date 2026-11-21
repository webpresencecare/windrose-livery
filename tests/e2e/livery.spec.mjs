/**
 * Windrose Livery (settings: see the README, Development).
 *
 * Works on two throwaway sites created here and deleted at the end (one on Ipsum, one on Twenty Twenty-Five), and on
 * a test variation "E2E Harbour" deleted at the end with the font files only it used. Other stored variations are
 * only previewed, never changed.
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, rmSync, writeFileSync, copyFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { test, expect } from '@playwright/test';

const HOST = process.env.WINDROSE_E2E_HOST ?? 'localhost';
const ADMIN = process.env.WINDROSE_E2E_USER ?? 'admin';
const WP_PATH = process.env.WINDROSE_E2E_WP_PATH ?? '/var/www/html/web';
const ROOT = `https://${ HOST }`;
const SITES = { ipsum: 'livery-e2e', tt5: 'livery-e2e-tt5' };
const TITLE = 'E2E Harbour';
const SCREEN = '/wp-admin/network/themes.php?page=windrose-livery';
const WORK = '/tmp/windrose-livery-e2e';
const FONT = 'besley-livery.woff2';

const wpAt = ( slug, ...args ) =>
	execFileSync( 'wp', [ ...args, `--path=${ WP_PATH }`, `--url=${ ROOT }/${ slug ? slug + '/' : '' }` ], {
		encoding: 'utf8',
	} ).trim();
const wp = ( ...args ) => wpAt( '', ...args );

/** Writes the test package: version 1 or 2 differ in their first color. */
function makePackage( version ) {
	const dir = `${ WORK }/v${ version }/e2e-harbour`;
	mkdirSync( `${ dir }/fonts/besley-livery`, { recursive: true } );
	writeFileSync(
		`${ dir }/e2e-harbour.json`,
		JSON.stringify( {
			version: 3,
			title: TITLE,
			settings: {
				color: {
					palette: [
						{ slug: 'base', name: 'Sand', color: 1 === version ? '#f0e6d2' : '#e8f4ea' },
						{ slug: 'contrast', name: 'Deep sea', color: '#1b2a3a' },
					],
				},
			},
			styles: {
				typography: { fontFamily: 'var:preset|font-family|besley-livery' },
			},
		} )
	);
	writeFileSync(
		`${ dir }/fonts.json`,
		JSON.stringify( {
			fontFamilies: [
				{
					name: 'Besley Livery',
					slug: 'besley-livery',
					fontFamily: '"Besley Livery", serif',
					fontFace: [
						{
							fontFamily: 'Besley Livery',
							fontStyle: 'normal',
							fontWeight: '400 900',
							src: [ `fonts/besley-livery/${ FONT }` ],
						},
					],
				},
			],
		} )
	);
	copyFileSync( `${ WP_PATH }/wp-content/themes/ipsum/assets/fonts/besley/besley-var-normal.woff2`, `${ dir }/fonts/besley-livery/${ FONT }` );
	writeFileSync( `${ dir }/fonts/besley-livery/OFL.txt`, 'SIL Open Font License 1.1 (test copy)\n' );
	const zip = `${ WORK }/e2e-harbour-v${ version }.zip`;
	rmSync( zip, { force: true } );
	execFileSync( 'zip', [ '-qr', zip, 'e2e-harbour' ], { cwd: `${ WORK }/v${ version }` } );
	return zip;
}

/** Deletes the test variation(s) and the font files nothing else uses (Livery's own rule). */
function removeTestVariations() {
	wp(
		'eval',
		`foreach ( Windrose\\Livery\\Library::all() as $item ) { if ( str_starts_with( $item['title'], '${ TITLE }' ) ) { $u = Windrose\\Livery\\Fonts::unused( array_column( $item['files'], 'stored' ), $item['id'] ); Windrose\\Livery\\Library::delete( $item['id'] ); Windrose\\Livery\\Fonts::delete( $u ); } }`
	);
}

/**
 * DataViews 22 draws its selection checkboxes (`@wordpress/ui` Checkbox) 16px wide with a 24px `::after` click area
 * that axe cannot see, so axe reports them under target-size although people get 24px (checked with real clicks on
 * 2026-11-10). Only those checkboxes are left out of target-size; everything else is still checked. Remove once fixed
 * upstream.
 *
 * @param {import('@playwright/test').Page} page       The page.
 * @param {Array}                           violations axe violations.
 * @return {Promise<Array>} The violations to report.
 */
async function withoutDataViewsCheckboxes( page, violations ) {
	const kept = [];
	for ( const violation of violations ) {
		if ( 'target-size' !== violation.id ) {
			kept.push( violation );
			continue;
		}
		const nodes = [];
		for ( const node of violation.nodes ) {
			const skipped = await page.evaluate( ( selector ) => {
				const element = document.querySelector( selector );
				return !! element && 'checkbox' === element.getAttribute( 'role' ) && !! element.closest( '.dataviews-wrapper' );
			}, String( node.target[ 0 ] ) );
			if ( ! skipped ) {
				nodes.push( node );
			}
		}
		if ( nodes.length ) {
			kept.push( { ...violation, nodes } );
		}
	}
	return kept;
}

/** WCAG 2.2 AA checks on part of the page, once animations have ended. */
async function expectAccessible( page, selector ) {
	await page.waitForFunction( () => document.getAnimations().every( ( a ) => 'running' !== a.playState ) );
	const results = await new AxeBuilder( { page } )
		.include( selector )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.analyze();
	const violations = await withoutDataViewsCheckboxes( page, results.violations );
	expect( violations.map( ( v ) => `${ v.id }: ${ v.nodes.map( ( n ) => n.target ).join( ', ' ) }` ) ).toEqual( [] );
}

/** Opens the import dialog, chooses a file, checks it. */
async function check( page, file ) {
	await page.goto( SCREEN );
	await page.getByRole( 'button', { name: 'Import', exact: true } ).click();
	const dialog = page.getByRole( 'dialog', { name: 'Import a style variation' } );
	await dialog.getByLabel( 'File' ).setInputFiles( file );
	await dialog.getByRole( 'button', { name: 'Check' } ).click();
	await expect( dialog.getByText( 'Title', { exact: true } ) ).toBeVisible();
	return dialog;
}

/** Front-end values of a site, as a visitor. */
async function front( browser, slug ) {
	const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] }, ignoreHTTPSErrors: true } );
	const page = await visitor.newPage();
	const external = [];
	const fonts = [];
	page.on( 'request', ( r ) => {
		const u = new URL( r.url() );
		if ( u.protocol.startsWith( 'http' ) && u.hostname !== HOST ) {
			external.push( u.hostname + u.pathname );
		}
	} );
	page.on( 'response', ( r ) => {
		if ( r.url().endsWith( '.woff2' ) ) {
			fonts.push( `${ r.status() } ${ new URL( r.url() ).pathname }` );
		}
	} );
	await page.goto( `${ ROOT }/${ slug }/` );
	await page.evaluate( () => document.fonts.ready );
	const css = await page.evaluate( () => ( {
		bg: getComputedStyle( document.documentElement ).getPropertyValue( '--wp--preset--color--base' ).trim(),
		body: getComputedStyle( document.body ).fontFamily,
	} ) );
	await visitor.close();
	return { ...css, external, fonts };
}

test.describe.configure( { mode: 'serial' } );
const ids = {};
let testFiles = [];

/** Remembers the test variation's stored files, to delete them at the end once no site uses them. */
function rememberFiles() {
	const found = JSON.parse(
		wp( 'eval', `echo wp_json_encode( array_merge( [], ...array_map( fn( $i ) => array_column( $i['files'], 'stored' ), array_filter( Windrose\\Livery\\Library::all(), fn( $i ) => '${ TITLE }' === $i['title'] ) ) ) );` )
	);
	testFiles = [ ...new Set( [ ...testFiles, ...found ] ) ];
}

test.beforeAll( () => {
	const paths = wp( 'site', 'list', '--field=path' ).split( '\n' );
	for ( const slug of Object.values( SITES ) ) {
		if ( paths.includes( `/${ slug }/` ) ) {
			wp( 'site', 'delete', `--slug=${ slug }`, '--yes' );
		}
	}
	removeTestVariations();
	ids.ipsum = wp( 'site', 'create', `--slug=${ SITES.ipsum }`, '--title=Livery e2e', '--email=livery-e2e@example.com', '--porcelain' );
	ids.tt5 = wp( 'site', 'create', `--slug=${ SITES.tt5 }`, '--title=Livery e2e TT5', '--email=livery-e2e@example.com', '--porcelain' );
	wpAt( SITES.ipsum, 'theme', 'activate', 'ipsum', '--quiet' );
	wp( 'theme', 'enable', 'twentytwentyfive', '--network' );
	wpAt( SITES.tt5, 'theme', 'activate', 'twentytwentyfive' );
	rmSync( WORK, { recursive: true, force: true } );
	makePackage( 1 );
	makePackage( 2 );
} );

test.afterAll( () => {
	for ( const slug of Object.values( SITES ) ) {
		wp( 'site', 'delete', `--slug=${ slug }`, '--yes' );
	}
	for ( const id of Object.values( ids ) ) {
		if ( /^\d+$/.test( id ) ) {
			rmSync( `${ WP_PATH }/wp-content/uploads/sites/${ id }`, { recursive: true, force: true } );
		}
	}
	removeTestVariations();
	if ( testFiles.length ) {
		wp( 'eval', `Windrose\\Livery\\Fonts::delete( Windrose\\Livery\\Fonts::unused( json_decode( '${ JSON.stringify( testFiles ) }', true ), 0 ) );` );
	}
	rmSync( WORK, { recursive: true, force: true } );
} );

test( 'import: dry run preview, then added to the list', async ( { page } ) => {
	const dialog = await check( page, `${ WORK }/e2e-harbour-v1.zip` );
	await expect( dialog.getByText( 'Ipsum and its child themes' ) ).toBeVisible();
	await expect( dialog.getByText( 'Besley Livery (1 file)' ) ).toBeVisible();
	await expect( dialog.getByRole( 'img', { name: '2 colors: Sand, Deep sea' } ) ).toBeVisible();
	await expect( dialog.getByText( 'Some parts were left out' ) ).toHaveCount( 0 );
	expect( wp( 'eval', `echo count( array_filter( Windrose\\Livery\\Library::all(), fn( $i ) => '${ TITLE }' === $i['title'] ) );` ) ).toBe( '0' );
	await expectAccessible( page, '.components-modal__frame' );

	await dialog.getByRole( 'button', { name: 'Add variation' } ).click();
	await expect( page.locator( '.windrose-livery__notice' ).getByText( `${ TITLE } saved (version 1)` ) ).toBeVisible();
	rememberFiles();
	const row = page.getByRole( 'row' ).filter( { hasText: TITLE } );
	await expect( row ).toContainText( 'Ipsum' );
	await expect( row ).toContainText( 'None' );
	await expectAccessible( page, '.windrose-livery' );
} );

test( 'Site Editor: listed on Ipsum, picked and saved; fonts local; recorded', async ( { page, browser } ) => {
	await page.goto( `/${ SITES.ipsum }/wp-admin/site-editor.php?p=%2Fstyles` );
	await page.waitForFunction( () => window.wp?.data?.select( 'core' ) );
	const listed = await page.evaluate( async () => {
		const variations = await window.wp.data.resolveSelect( 'core' ).__experimentalGetCurrentThemeGlobalStylesVariations();
		const settings = [
			window.wp.data.select( 'core/edit-site' )?.getSettings?.(),
			window.wp.data.select( 'core/editor' )?.getEditorSettings?.(),
			window.wp.data.select( 'core/block-editor' ).getSettings(),
		];
		const assets = settings.map( ( s ) => s?.__unstableResolvedAssets?.styles || '' ).join( '' );
		return { titles: variations.map( ( v ) => v.title ), fontFace: assets.includes( 'font-family:"Besley Livery"' ), size: assets.length };
	} );
	expect( listed.titles ).toContain( TITLE );
	expect( listed.titles ).toContain( 'Blue Hour' );
	expect( listed.size, 'The editor’s preview assets were found.' ).toBeGreaterThan( 0 );
	expect( listed.fontFace, 'The editor previews get the font.' ).toBe( true );

	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );
	await page.getByRole( 'button', { name: 'Browse styles' } ).click();
	await page.getByRole( 'button', { name: TITLE, exact: true } ).or( page.getByLabel( TITLE, { exact: true } ) ).first().click();

	// Before saving, the font manager reads the picked fonts (7.2 failed on fonts given as a plain list).
	await page.getByRole( 'button', { name: 'Back' } ).first().click();
	await page.getByRole( 'button', { name: /Typography/ } ).first().click();
	await page.getByRole( 'button', { name: 'Manage fonts' } ).click();
	const fonts = page.getByRole( 'dialog', { name: 'Fonts' } );
	await expect( fonts.getByText( 'Besley Livery', { exact: true } ) ).toBeVisible();
	await expect( page.getByText( /unexpected error|crashed/i ) ).toHaveCount( 0 );
	await fonts.getByRole( 'button', { name: 'Close' } ).click();
	expect( errors ).toEqual( [] );

	await page.getByRole( 'button', { name: /Review \d+ change/ } ).click();
	await page.getByRole( 'button', { name: 'Save', exact: true } ).last().click();
	await expect( page.getByRole( 'button', { name: /Review \d+ change/ } ) ).toHaveCount( 0, { timeout: 15000 } );

	const seen = await front( browser, SITES.ipsum );
	expect( seen.bg ).toBe( '#f0e6d2' );
	expect( seen.body ).toContain( 'Besley Livery' );
	expect( seen.fonts.some( ( f ) => /^200 \/wp-content\/uploads\/fonts\/[a-f0-9]{16}-besley-livery\.woff2$/.test( f ) ) ).toBe( true );
	expect( seen.external ).toEqual( [] );
	const record = JSON.parse( wpAt( SITES.ipsum, 'option', 'get', 'windrose_livery_applied', '--format=json' ) );
	expect( record.how ).toBe( 'editor' );
	expect( record.version ).toBe( 1 );

	// Not offered on a theme with other color names.
	await page.goto( `/${ SITES.tt5 }/wp-admin/site-editor.php?p=%2Fstyles` );
	await page.waitForFunction( () => window.wp?.data?.select( 'core' ) );
	const other = await page.evaluate( async () =>
		( await window.wp.data.resolveSelect( 'core' ).__experimentalGetCurrentThemeGlobalStylesVariations() ).map( ( v ) => v.title )
	);
	expect( other ).not.toContain( TITLE );
} );

test( 'new version: the screen offers it to the site, applies it, results', async ( { page, browser } ) => {
	const dialog = await check( page, `${ WORK }/e2e-harbour-v2.zip` );
	const add = dialog.getByRole( 'button', { name: 'Add variation' } );
	await expect( add ).toBeDisabled();
	await dialog.getByRole( 'checkbox', { name: `Replace ${ TITLE } (it becomes version 2)` } ).check();
	await add.click();
	await expect( page.locator( '.windrose-livery__notice' ).getByText( `${ TITLE } saved (version 2)` ) ).toBeVisible();
	rememberFiles();

	const notice = page.locator( '.components-notice' ).filter( { hasText: `1 site uses an older version of ${ TITLE } (now version 2).` } );
	await expect( notice ).toBeVisible();
	await notice.getByRole( 'button', { name: 'Apply the new version' } ).click();
	const apply = page.getByRole( 'dialog', { name: `Apply ${ TITLE }` } );
	await expect( apply.getByRole( 'checkbox', { name: /^Livery e2e \(/ } ) ).toBeChecked();
	await expect( apply.getByText( 'Uses version 1.' ) ).toBeVisible();
	// Its own site's reason only: other Twenty Twenty-Five sites of the network have the same line.
	const tt5 = apply.getByRole( 'checkbox', { name: /^Livery e2e TT5/ } );
	await expect( tt5 ).toBeDisabled();
	await expect( tt5 ).toHaveAccessibleDescription( 'Not available: the site uses Twenty Twenty-Five.' );
	await expectAccessible( page, '.components-modal__frame' );
	await apply.getByRole( 'button', { name: 'Apply', exact: true } ).click();

	const results = page.getByRole( 'dialog', { name: 'Results' } );
	await expect( results ).toContainText( 'Livery e2e: Applied' );
	await expect( results ).toContainText( 'Applied (version 2). The previous styles are in the Site Editor, Styles, Revisions.' );
	await results.getByRole( 'button', { name: 'Done' } ).click();
	await expect( notice ).toHaveCount( 0 );

	const seen = await front( browser, SITES.ipsum );
	expect( seen.bg ).toBe( '#e8f4ea' );
	const record = JSON.parse( wpAt( SITES.ipsum, 'option', 'get', 'windrose_livery_applied', '--format=json' ) );
	expect( record ).toMatchObject( { how: 'livery', version: 2, changed: false } );
} );

test( 'Site Editor menu: save styles as a variation', async ( { page } ) => {
	const copy = `${ TITLE } menu copy`;
	await page.goto( `/${ SITES.ipsum }/wp-admin/site-editor.php?canvas=edit` );
	await page.waitForFunction( () => window.wp?.data?.select( 'core' ) );
	// WordPress 7.2 lists plugin items in the Options menu (⋮) under Panels.
	await page.getByRole( 'button', { name: 'Options' } ).first().click();
	await page.getByRole( 'menuitem', { name: 'Panels' } ).hover();
	await page.getByRole( 'menuitem', { name: 'Save styles as a variation' } ).click();
	const dialog = page.getByRole( 'dialog', { name: 'Save styles as a variation' } );
	await dialog.getByLabel( 'Title' ).fill( copy );
	await dialog.getByRole( 'button', { name: 'Check' } ).click();
	await expect( dialog.getByText( 'Ipsum and its child themes' ) ).toBeVisible();
	await expect( dialog.getByRole( 'img', { name: '2 colors: Sand, Deep sea' } ) ).toBeVisible();
	await expect( dialog.getByText( 'Besley Livery (1 file)' ) ).toBeVisible();
	await expectAccessible( page, '.components-modal__frame' );
	await dialog.getByRole( 'button', { name: 'Add variation' } ).click();
	await expect( page.locator( '.components-snackbar' ).getByText( `${ copy } saved as a style variation (version 1)` ) ).toBeVisible();

	const listed = await page.evaluate( async () =>
		( await window.wp.data.resolveSelect( 'core' ).__experimentalGetCurrentThemeGlobalStylesVariations() ).map( ( v ) => v.title )
	);
	expect( listed ).toContain( copy );
	expect( wp( 'eval', `echo count( array_filter( Windrose\\Livery\\Library::all(), fn( $i ) => '${ copy }' === $i['title'] ) );` ) ).toBe( '1' );
	wp( 'eval', `foreach ( Windrose\\Livery\\Library::all() as $i ) { if ( '${ copy }' === $i['title'] ) { Windrose\\Livery\\Library::delete( $i['id'] ); } }` );
} );

test( 'export: a zip with the variation, fonts.json, the font and its license', async ( { page } ) => {
	await page.goto( SCREEN );
	const row = page.getByRole( 'row' ).filter( { hasText: TITLE } );
	await row.getByRole( 'button', { name: 'Actions' } ).click();
	const download = page.waitForEvent( 'download' );
	await page.getByRole( 'menuitem', { name: 'Export' } ).click();
	const file = await download;
	expect( file.suggestedFilename() ).toBe( 'e2e-harbour.zip' );
	const path = `${ WORK }/export.zip`;
	await file.saveAs( path );
	const names = execFileSync( 'unzip', [ '-Z1', path ], { encoding: 'utf8' } ).trim().split( '\n' ).sort();
	expect( names ).toEqual( [
		'e2e-harbour/e2e-harbour.json',
		'e2e-harbour/fonts.json',
		'e2e-harbour/fonts/besley-livery/OFL.txt',
		`e2e-harbour/fonts/besley-livery/${ FONT }`,
	] );
	const json = JSON.parse( execFileSync( 'unzip', [ '-p', path, 'e2e-harbour/e2e-harbour.json' ], { encoding: 'utf8' } ) );
	expect( json.settings.color.palette[ 0 ].color ).toBe( '#e8f4ea' );
} );

test( 'delete: gone from the Site Editor, the site keeps its styles and fonts', async ( { page, browser } ) => {
	await page.goto( SCREEN );
	const row = page.getByRole( 'row' ).filter( { hasText: TITLE } );
	await row.getByRole( 'button', { name: 'Actions' } ).click();
	await page.getByRole( 'menuitem', { name: 'Delete' } ).click();
	const dialog = page.getByRole( 'dialog', { name: 'Delete style variation' } );
	await expect( dialog ).toContainText( 'Livery can no longer offer those sites a new version of it.' );
	await dialog.getByRole( 'button', { name: 'Delete', exact: true } ).click();
	await expect( page.locator( '.windrose-livery__notice' ).getByText( `${ TITLE } deleted. 1 font file is kept, with its license, because a site still uses it.` ) ).toBeVisible();
	await expect( page.getByRole( 'row' ).filter( { hasText: TITLE } ) ).toHaveCount( 0 );

	const listed = JSON.parse(
		wpAt( SITES.ipsum, 'eval', 'echo wp_json_encode( array_column( rest_get_server()->response_to_data( rest_do_request( new WP_REST_Request( "GET", "/wp/v2/global-styles/themes/ipsum/variations" ) ), false ), "title" ) );', `--user=${ ADMIN }` )
	);
	expect( listed ).not.toContain( TITLE );
	const seen = await front( browser, SITES.ipsum );
	expect( seen.bg ).toBe( '#e8f4ea' );
	expect( seen.fonts.some( ( f ) => f.startsWith( '200 /wp-content/uploads/fonts/' ) ) ).toBe( true );
} );
