// @ts-check

/**
 * Playwright end-to-end tests for PandocUltimateConverter category export.
 *
 * These tests verify that Special:PandocExport correctly:
 *   1. Exports all pages from a category when a "Category:…" title is requested.
 *   2. Excludes pages that are NOT members of the requested category.
 *
 * Test pages are created via the MediaWiki API in beforeAll so the tests are
 * fully self-contained and do not require pre-seeded wiki data.
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

/** Category used for the export test. */
const TEST_CATEGORY = 'PandocExportTestCategory';

/**
 * Two pages that belong to the test category.
 * Each carries a unique sentinel string so we can assert it appears in the export output.
 */
const CAT_PAGE_1 = {
	title: 'PandocCatExportTest1',
	sentinel: 'PandocCatExportSentinelAlpha',
};
const CAT_PAGE_2 = {
	title: 'PandocCatExportTest2',
	sentinel: 'PandocCatExportSentinelBeta',
};

/**
 * A page that is NOT in the test category.
 * Its sentinel must not appear in a category export.
 */
const NON_CAT_PAGE = {
	title: 'PandocNoCatExportTest',
	sentinel: 'PandocNoCatExportSentinelGamma',
};

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

test.describe( 'PandocExport — category export', () => {

	// Create test pages once before all tests in this suite.
	test.beforeAll( async ( { browser } ) => {
		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		await login( page );

		// Two pages belonging to the test category.
		await createWikiPage(
			page,
			CAT_PAGE_1.title,
			`= ${ CAT_PAGE_1.title } =\n\n${ CAT_PAGE_1.sentinel }\n\n[[Category:${ TEST_CATEGORY }]]`
		);
		await createWikiPage(
			page,
			CAT_PAGE_2.title,
			`= ${ CAT_PAGE_2.title } =\n\n${ CAT_PAGE_2.sentinel }\n\n[[Category:${ TEST_CATEGORY }]]`
		);

		// One page that is NOT in the category.
		await createWikiPage(
			page,
			NON_CAT_PAGE.title,
			`= ${ NON_CAT_PAGE.title } =\n\n${ NON_CAT_PAGE.sentinel }`
		);

		await ctx.close();
	} );

	test.beforeEach( async ( { page } ) => {
		await login( page );
	} );

	test( 'exports both category pages and excludes non-member pages', async ( { page } ) => {
		// Request a plain-text export of the test category.
		// The extension resolves "Category:…" page names to their member pages.
		const exportUrl = new URL( `${ BASE_URL }/index.php` );
		exportUrl.searchParams.set( 'title', 'Special:PandocExport' );
		exportUrl.searchParams.set( 'format', 'txt' );
		// PHP recognises "pages%5B%5D" (the URLSearchParams-encoded form of "pages[]")
		// as an array parameter, equivalent to the literal "pages[]" form.
		exportUrl.searchParams.append( 'pages[]', `Category:${ TEST_CATEGORY }` );
		const response = await page.request.get( exportUrl.toString() );

		expect( response.status() ).toBe( 200 );

		const body = await response.text();

		// Both category members must appear in the export output.
		expect( body ).toContain( CAT_PAGE_1.sentinel );
		expect( body ).toContain( CAT_PAGE_2.sentinel );

		// The page outside the category must NOT appear in the export output.
		expect( body ).not.toContain( NON_CAT_PAGE.sentinel );
	} );

} );
