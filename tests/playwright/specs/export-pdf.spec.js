// @ts-check

/**
 * Playwright end-to-end tests for PandocUltimateConverter PDF export.
 *
 * These tests verify that Special:PandocExport correctly produces a valid PDF
 * for a single wiki page by exercising the full web UI:
 *
 *   1. Navigate to Special:PandocExport
 *   2. Type the page name into the lookup input
 *   3. Select "PDF (.pdf)" in the format dropdown
 *   4. Click the Export button
 *   5. Capture and verify the downloaded PDF
 *
 * The downloaded PDF is saved to UI_SCREENSHOTS_DIR so CI uploads it as an
 * artifact alongside the UI screenshots.
 *
 * A test page is created via the MediaWiki API in beforeAll so the test is
 * fully self-contained and does not require pre-seeded wiki data.
 *
 * Environment variables
 * ---------------------
 * MW_BASE_URL        Base URL of the running MediaWiki instance  (default: http://localhost:8080)
 * MW_ADMIN_USER      Admin username                               (default: admin)
 * MW_ADMIN_PASS      Admin password                               (default: adminpassword)
 * UI_SCREENSHOTS_DIR Directory where the PDF artifact is saved    (default: ./screenshots)
 */

const { test, expect } = require( '@playwright/test' );
const path = require( 'path' );
const fs = require( 'fs' );

const BASE_URL = process.env.MW_BASE_URL || 'http://localhost:8080';
const ADMIN_USER = process.env.MW_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.MW_ADMIN_PASS || 'adminpassword';
const ARTIFACTS_DIR =
	process.env.UI_SCREENSHOTS_DIR || path.join( __dirname, '..', 'screenshots' );

/** Title of the wiki page created for the PDF export test. */
const PDF_TEST_PAGE = 'PandocPdfExportTestPage';

/** Wikitext content of the test page — includes headings, formatting, lists and a table. */
const PDF_TEST_CONTENT = `= PDF Export Test =

This is '''bold''' and ''italic'' text used to verify the PDF export pipeline.

== Section One ==

* Item alpha
* Item beta
* Item gamma

== Section Two ==

{| class="wikitable"
! Column A !! Column B
|-
| Value 1  || Value 2
|-
| Value 3  || Value 4
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

test.describe( 'PandocExport — PDF export (full LibreOffice pipeline)', () => {

	// Create the test page once before all tests in this suite.
	test.beforeAll( async ( { browser } ) => {
		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		await login( page );
		await createWikiPage( page, PDF_TEST_PAGE, PDF_TEST_CONTENT );
		await ctx.close();
	} );

	test.beforeEach( async ( { page } ) => {
		await login( page );
	} );

	test( 'exports a single wiki page to PDF via the web UI', async ( { page } ) => {
		// LibreOffice conversion can be slow; allow extra time for this test.
		test.setTimeout( 120_000 );

		// 1. Navigate to Special:PandocExport and wait for the Vue app to mount.
		await page.goto( `${ BASE_URL }/index.php?title=Special:PandocExport`, {
			waitUntil: 'networkidle',
		} );
		await page.waitForSelector( '.mw-pandoc-export-app', { timeout: 30_000 } );

		// 2. Type the page name into the CdxLookup input.
		await page.locator( '.mw-pandoc-page-search__lookup input' ).first().fill( PDF_TEST_PAGE );

		// 3. Open the CdxSelect format dropdown and choose PDF.
		//    CdxSelect renders a <div class="cdx-select-vue__handle" role="combobox"> that
		//    opens a listbox; options carry role="option".
		//    Note: the `id` attribute on <cdx-select> is NOT forwarded to its root DOM
		//    element in all Codex versions, so we target the handle directly by class.
		await page.locator( '.cdx-select-vue__handle' ).click();
		await page.locator( '[role="option"]' ).filter( { hasText: 'PDF (.pdf)' } ).click();

		// 4. Click Export and capture the browser download event.
		//    The Vue app uses fetch() + URL.createObjectURL() + <a download> to
		//    trigger the file save, which Playwright surfaces as a 'download' event.
		if ( !fs.existsSync( ARTIFACTS_DIR ) ) {
			fs.mkdirSync( ARTIFACTS_DIR, { recursive: true } );
		}
		const artifactPath = path.join( ARTIFACTS_DIR, 'playwright-pdf-libreoffice.pdf' );

		const downloadPromise = page.waitForEvent( 'download', { timeout: 120_000 } );
		await page.locator( '.mw-pandoc-export-app__actions button' )
			.filter( { hasText: 'Export' } )
			.click();
		const download = await downloadPromise;

		// 5. Persist the PDF so CI can upload it as an artifact.
		await download.saveAs( artifactPath );

		// 6. Verify the file is a valid PDF (starts with %PDF magic bytes).
		const buffer = fs.readFileSync( artifactPath );
		const magic = buffer.slice( 0, 4 ).toString( 'latin1' );
		expect( magic ).toBe( '%PDF' );
	} );

} );
