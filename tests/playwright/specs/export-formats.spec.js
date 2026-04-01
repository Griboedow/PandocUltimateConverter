// @ts-check

/**
 * Playwright end-to-end tests for PandocUltimateConverter export formats.
 *
 * Each test exercises the full web UI export pipeline for one format:
 *   1. Navigate to Special:PandocExport
 *   2. Type the page name into the CdxLookup input
 *   3. Select the target format in the CdxSelect dropdown
 *   4. Click the Export button and capture the browser download event
 *   5. Verify the downloaded file has the expected structure
 *
 * Formats covered
 * ---------------
 *   DOCX  — valid ZIP containing word/document.xml
 *   ODT   — valid ZIP with opendocument.text mimetype
 *   EPUB  — valid ZIP containing mimetype entry
 *   HTML  — text response beginning with <!DOCTYPE or <html
 *   RTF   — binary stream beginning with {\rtf
 *   TXT   — plain-text with expected content
 *
 * PDF is covered separately in export-pdf.spec.js because it requires
 * LibreOffice and a much longer timeout.
 *
 * A test page is created via the MediaWiki API in beforeAll so the tests are
 * fully self-contained and do not require pre-seeded wiki data.
 *
 * Environment variables
 * ---------------------
 * MW_BASE_URL        Base URL of the running MediaWiki instance  (default: http://localhost:8080)
 * MW_ADMIN_USER      Admin username                               (default: admin)
 * MW_ADMIN_PASS      Admin password                               (default: adminpassword)
 * UI_SCREENSHOTS_DIR Directory where downloaded artifacts are saved (default: ./screenshots)
 */

const { test, expect } = require( '@playwright/test' );
const path = require( 'path' );
const fs = require( 'fs' );

const BASE_URL = process.env.MW_BASE_URL || 'http://localhost:8080';
const ADMIN_USER = process.env.MW_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.MW_ADMIN_PASS || 'adminpassword';
const ARTIFACTS_DIR =
	process.env.UI_SCREENSHOTS_DIR || path.join( __dirname, '..', 'screenshots' );

/** Title of the wiki page used for all export tests in this file. */
const EXPORT_TEST_PAGE = 'PandocFormatExportTestPage';

/** Unique sentinel string embedded in the test page content. */
const EXPORT_SENTINEL = 'PandocFormatExportSentinel42';

/** Wikitext content of the test page. */
const EXPORT_TEST_CONTENT = `= Export Format Test =

This is '''bold''' and ''italic'' text.

${ EXPORT_SENTINEL }

== Section ==

* Item one
* Item two

{| class="wikitable"
! Column A !! Column B
|-
| Value 1  || Value 2
|}
`;

/**
 * Log in to MediaWiki as admin.
 *
 * @param {import('@playwright/test').Page} page
 */
async function login( page ) {
	await page.goto( `${ BASE_URL }/index.php?title=Special:UserLogin` );
	await page.locator( '#wpName1' ).fill( ADMIN_USER );
	await page.locator( '#wpPassword1' ).fill( ADMIN_PASS );
	await page.locator( '#wpLoginAttempt' ).click();
	await page.waitForLoadState( 'networkidle', { timeout: 30_000 } );
}

/**
 * Retrieve a CSRF token from the MediaWiki API.
 *
 * @param {import('@playwright/test').Page} page Logged-in page.
 * @returns {Promise<string>}
 */
async function getCsrfToken( page ) {
	const resp = await page.request.get(
		`${ BASE_URL }/api.php?action=query&meta=tokens&type=csrf&format=json`
	);
	const body = await resp.json();
	return body.query.tokens.csrftoken;
}

/**
 * Create (or overwrite) a wiki page via the MediaWiki API.
 *
 * @param {import('@playwright/test').Page} page Logged-in page.
 * @param {string} title   Page title.
 * @param {string} content Wikitext content.
 */
async function createWikiPage( page, title, content ) {
	const token = await getCsrfToken( page );
	const resp = await page.request.post( `${ BASE_URL }/api.php`, {
		form: {
			action: 'edit',
			title,
			text: content,
			token,
			format: 'json',
		},
	} );
	const body = await resp.json();
	if ( !body.edit || ( body.edit.result !== 'Success' && body.edit.nochange === undefined ) ) {
		throw new Error( `Failed to create page "${ title }": ${ JSON.stringify( body ) }` );
	}
}

/**
 * Export a single wiki page via the Special:PandocExport web UI and return
 * the downloaded file content as a Buffer.
 *
 * @param {import('@playwright/test').Page} page    Logged-in page.
 * @param {string}                          title   Wiki page title to export.
 * @param {string}                          format  Format label shown in the dropdown (e.g. "DOCX (.docx)").
 * @param {string}                          artifact Filename to save the downloaded file under in ARTIFACTS_DIR.
 * @returns {Promise<Buffer>}
 */
async function exportViaWebUi( page, title, format, artifact ) {
	// Navigate to Special:PandocExport and wait for the Vue app to mount.
	await page.goto( `${ BASE_URL }/index.php?title=Special:PandocExport`, {
		waitUntil: 'networkidle',
	} );
	await page.waitForSelector( '.mw-pandoc-export-app', { timeout: 30_000 } );

	// Type the page name into the CdxLookup input.
	await page.locator( '.mw-pandoc-page-search__lookup input' ).first().fill( title );

	// Open the CdxSelect format dropdown and choose the requested format.
	await page.locator( '.cdx-select-vue__handle' ).click();
	await page.locator( '[role="option"]' ).filter( { hasText: format } ).click();

	// Click Export and capture the download.
	if ( !fs.existsSync( ARTIFACTS_DIR ) ) {
		fs.mkdirSync( ARTIFACTS_DIR, { recursive: true } );
	}
	const artifactPath = path.join( ARTIFACTS_DIR, artifact );
	const downloadPromise = page.waitForEvent( 'download', { timeout: 60_000 } );
	await page.locator( '.mw-pandoc-export-app__actions button' )
		.filter( { hasText: 'Export' } )
		.click();
	const download = await downloadPromise;
	await download.saveAs( artifactPath );

	return fs.readFileSync( artifactPath );
}

test.describe( 'PandocExport — format exports via web UI', () => {

	test.beforeAll( async ( { browser } ) => {
		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		await login( page );
		await createWikiPage( page, EXPORT_TEST_PAGE, EXPORT_TEST_CONTENT );
		await ctx.close();
	} );

	test.beforeEach( async ( { page } ) => {
		await login( page );
	} );

	// ------------------------------------------------------------------
	// DOCX
	// ------------------------------------------------------------------

	test( 'exports a page to DOCX via the web UI', async ( { page } ) => {
		const buf = await exportViaWebUi(
			page, EXPORT_TEST_PAGE, 'Microsoft Word (.docx)', 'export-output.docx'
		);

		expect( buf.length ).toBeGreaterThan( 0 );

		// DOCX is a ZIP — verify the PK magic bytes.
		const magic = buf.slice( 0, 2 ).toString( 'latin1' );
		expect( magic ).toBe( 'PK' );

		// Verify it contains the mandatory DOCX entry.
		const content = buf.toString( 'latin1' );
		expect( content ).toContain( 'word/document.xml' );
	} );

	// ------------------------------------------------------------------
	// ODT
	// ------------------------------------------------------------------

	test( 'exports a page to ODT via the web UI', async ( { page } ) => {
		const buf = await exportViaWebUi(
			page, EXPORT_TEST_PAGE, 'OpenDocument Text (.odt)', 'export-output.odt'
		);

		expect( buf.length ).toBeGreaterThan( 0 );

		// ODT is a ZIP — verify PK magic bytes.
		const magic = buf.slice( 0, 2 ).toString( 'latin1' );
		expect( magic ).toBe( 'PK' );

		// ODT ZIP must contain a 'mimetype' entry with 'opendocument.text'.
		const content = buf.toString( 'latin1' );
		expect( content ).toContain( 'opendocument.text' );
	} );

	// ------------------------------------------------------------------
	// EPUB
	// ------------------------------------------------------------------

	test( 'exports a page to EPUB via the web UI', async ( { page } ) => {
		const buf = await exportViaWebUi(
			page, EXPORT_TEST_PAGE, 'EPUB (.epub)', 'export-output.epub'
		);

		expect( buf.length ).toBeGreaterThan( 0 );

		// EPUB is a ZIP — verify PK magic bytes.
		const magic = buf.slice( 0, 2 ).toString( 'latin1' );
		expect( magic ).toBe( 'PK' );

		// EPUB ZIP must contain a 'mimetype' entry.
		const content = buf.toString( 'latin1' );
		expect( content ).toContain( 'mimetype' );
	} );

	// ------------------------------------------------------------------
	// HTML
	// ------------------------------------------------------------------

	test( 'exports a page to HTML via the web UI', async ( { page } ) => {
		const buf = await exportViaWebUi(
			page, EXPORT_TEST_PAGE, 'HTML (.html)', 'export-output.html'
		);

		expect( buf.length ).toBeGreaterThan( 0 );

		const text = buf.toString( 'utf8' );

		// Must start with an HTML declaration or tag.
		expect( text.trimStart() ).toMatch( /^<!DOCTYPE|^<html/i );

		// Must contain the sentinel string from the test page.
		expect( text ).toContain( EXPORT_SENTINEL );
	} );

	// ------------------------------------------------------------------
	// RTF
	// ------------------------------------------------------------------

	test( 'exports a page to RTF via the web UI', async ( { page } ) => {
		const buf = await exportViaWebUi(
			page, EXPORT_TEST_PAGE, 'Rich Text Format (.rtf)', 'export-output.rtf'
		);

		expect( buf.length ).toBeGreaterThan( 0 );

		// RTF files always start with the magic string "{\rtf".
		const header = buf.slice( 0, 5 ).toString( 'latin1' );
		expect( header ).toBe( '{\\rtf' );
	} );

	// ------------------------------------------------------------------
	// TXT
	// ------------------------------------------------------------------

	test( 'exports a page to plain text via the web UI', async ( { page } ) => {
		const buf = await exportViaWebUi(
			page, EXPORT_TEST_PAGE, 'Plain Text (.txt)', 'export-output.txt'
		);

		expect( buf.length ).toBeGreaterThan( 0 );

		// Plain text must contain the sentinel string.
		const text = buf.toString( 'utf8' );
		expect( text ).toContain( EXPORT_SENTINEL );
	} );

} );
