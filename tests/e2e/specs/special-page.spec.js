// @ts-check
'use strict';

const { test, expect } = require( '@playwright/test' );

const MW_ADMIN_USER = process.env.MW_ADMIN_USER || 'WikiAdmin';
const MW_ADMIN_PASS = process.env.MW_ADMIN_PASS || 'WikiAdmin123!';

/**
 * Login to MediaWiki as the admin user.
 *
 * @param {import('@playwright/test').Page} page
 */
async function login( page ) {
	await page.goto( '/index.php/Special:UserLogin' );
	await page.locator( '#wpName1' ).fill( MW_ADMIN_USER );
	await page.locator( '#wpPassword1' ).fill( MW_ADMIN_PASS );
	await page.locator( '#wpLoginAttempt' ).click();
	// Wait until the login page is gone (redirect to Main Page or return page)
	await expect( page.locator( '#pt-logout' ).first() )
		.toBeVisible( { timeout: 30_000 } );
}

test.describe( 'PandocUltimateConverter – MediaWiki 1.39', () => {

	test.beforeEach( async ( { page } ) => {
		await login( page );
	} );

	// ------------------------------------------------------------------ //
	// Test 1 – Special page loads without PHP errors
	// ------------------------------------------------------------------ //
	test( 'Special:PandocUltimateConverter page loads correctly', async ( { page } ) => {
		await page.goto( '/index.php/Special:PandocUltimateConverter' );

		// The conversion form must be present
		await expect( page.locator( 'form#mw-pandoc-upload-form' ) )
			.toBeVisible( { timeout: 15_000 } );

		// No MediaWiki fatal-error box should appear
		await expect( page.locator( '.mw-message-box-error, .error' ) ).toHaveCount( 0 );

		await page.screenshot( { path: 'screenshots/01-special-page-loaded.png', fullPage: true } );
	} );

	// ------------------------------------------------------------------ //
	// Test 2 – Convert a URL and verify the resulting wiki page
	// ------------------------------------------------------------------ //
	test( 'Convert URL to wiki page – result page is not empty', async ( { page } ) => {
		await page.goto( '/index.php/Special:PandocUltimateConverter' );

		// Switch to URL source mode
		await page.locator( '#wpConvertSourceType' ).selectOption( 'url' );

		// Allow the hide-if JS to update field visibility
		await page.waitForTimeout( 500 );

		// The URL field should now be visible/enabled
		const urlField = page.locator( '#wpUrlToConvert' );
		await expect( urlField ).toBeVisible();

		// Use the test document served by the MW container's own Apache
		// (accessible from pandoc running inside the same container)
		await urlField.fill( 'http://localhost/test-document.html' );

		// Set the target wiki page name
		const pageNameField = page.locator( '#wpArticleTitle' );
		await pageNameField.fill( 'PandocE2ETestPage' );

		await page.screenshot( { path: 'screenshots/02-form-filled.png', fullPage: true } );

		// Submit the form and wait for the redirect to the resulting wiki page
		await Promise.all( [
			page.waitForNavigation( { waitUntil: 'domcontentloaded', timeout: 60_000 } ),
			page.locator( '#mw-pandoc-upload-form-submit' ).click(),
		] );

		// We must have been redirected to the created article
		await expect( page ).toHaveURL( /PandocE2ETestPage/ );

		// The article content must not be empty
		const content = page.locator( '#mw-content-text' );
		await expect( content ).toBeVisible();
		await expect( content ).not.toBeEmpty();

		// Verify the actual converted text includes the expected heading
		await expect( content ).toContainText( 'Test Heading' );

		// Screenshot proof – the main deliverable of this test
		await page.screenshot( { path: 'screenshots/03-result-page.png', fullPage: true } );
	} );

} );
