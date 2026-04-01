// @ts-check

/**
 * Playwright end-to-end tests for PandocUltimateConverter PDF export.
 *
 * These tests verify that Special:PandocExport correctly produces a valid PDF
 * for a single wiki page, exercising the full production pipeline:
 *
 *   wiki page wikitext
 *     → Pandoc (mediawiki → docx)
 *     → LibreOffice (docx → pdf)
 *     → HTTP response with Content-Type: application/pdf
 *
 * A test page is created via the MediaWiki API in beforeAll so the test is
 * fully self-contained and does not require pre-seeded wiki data.
 *
 * Environment variables
 * ---------------------
 * MW_BASE_URL     Base URL of the running MediaWiki instance  (default: http://localhost:8080)
 * MW_ADMIN_USER   Admin username                               (default: admin)
 * MW_ADMIN_PASS   Admin password                               (default: adminpassword)
 */

const { test, expect } = require( '@playwright/test' );

const BASE_URL = process.env.MW_BASE_URL || 'http://localhost:8080';
const ADMIN_USER = process.env.MW_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.MW_ADMIN_PASS || 'adminpassword';

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
	await Promise.all( [
		page.waitForNavigation( { waitUntil: 'networkidle', timeout: 30000 } ),
		page.locator( '#wpLoginAttempt' ).click(),
	] );
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

	test( 'exports a single wiki page to PDF via the LibreOffice pipeline', async ( { page } ) => {
		// LibreOffice conversion can be slow; allow extra time for this test.
		test.setTimeout( 120_000 );

		const exportUrl = new URL( `${ BASE_URL }/index.php` );
		exportUrl.searchParams.set( 'title', 'Special:PandocExport' );
		exportUrl.searchParams.set( 'format', 'pdf' );
		exportUrl.searchParams.append( 'items[]', PDF_TEST_PAGE );

		const response = await page.request.get( exportUrl.toString() );

		// The export must succeed.
		expect( response.status() ).toBe( 200 );

		// The response must be delivered as a PDF.
		const contentType = response.headers()[ 'content-type' ] ?? '';
		expect( contentType ).toContain( 'application/pdf' );

		// The response body must start with the PDF magic bytes.
		const buffer = await response.body();
		const magic = buffer.slice( 0, 4 ).toString( 'latin1' );
		expect( magic ).toBe( '%PDF' );
	} );

} );
