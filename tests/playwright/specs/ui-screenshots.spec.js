// @ts-check

/**
 * Playwright end-to-end screenshot tests for PandocUltimateConverter's Web UI.
 *
 * These tests capture screenshots of the three key UI surfaces:
 *   1. Import page  (Special:PandocUltimateConverter) — after a URL has been typed
 *   2. Export page  (Special:PandocExport)             — after a page name has been entered
 *   3. Page tools   (content page toolbar)             — with the Export action visible
 *
 * Screenshots are saved to the directory specified by UI_SCREENSHOTS_DIR (default:
 * tests/playwright/screenshots/).
 *
 * Environment variables
 * ---------------------
 * MW_BASE_URL          Base URL of the running MediaWiki instance  (default: http://localhost:8080)
 * MW_ADMIN_USER        Admin username                               (default: admin)
 * MW_ADMIN_PASS        Admin password                               (default: adminpassword)
 * UI_SCREENSHOTS_DIR   Directory to save screenshots into           (default: ./screenshots)
 */

const { test } = require( '@playwright/test' );
const path = require( 'path' );
const fs = require( 'fs' );

const BASE_URL = process.env.MW_BASE_URL || 'http://localhost:8080';
const ADMIN_USER = process.env.MW_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.MW_ADMIN_PASS || 'adminpassword';
const SCREENSHOTS_DIR =
	process.env.UI_SCREENSHOTS_DIR || path.join( __dirname, '..', 'screenshots' );

if ( !fs.existsSync( SCREENSHOTS_DIR ) ) {
	fs.mkdirSync( SCREENSHOTS_DIR, { recursive: true } );
}

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

test.describe( 'PandocUltimateConverter Web UI', () => {
	test.beforeEach( async ( { page } ) => {
		await login( page );
	} );

	test( 'import page — URLs tab with a URL entered', async ( { page } ) => {
		// ?codex=1 forces the Codex (Vue) UI on all MW versions;
		// on MW 1.43+ Codex is already the default.
		await page.goto(
			`${ BASE_URL }/index.php?title=Special:PandocUltimateConverter&codex=1`,
			{ waitUntil: 'networkidle' }
		);

		// Wait for the Vue app to mount its tabs
		await page.waitForSelector( '.mw-pandoc-codex-root .cdx-tabs', { timeout: 30000 } );

		// Switch to the URLs tab
		const urlsTab = page
			.locator( '.cdx-tabs__list button', { hasText: 'URLs' } )
			.first();
		if ( await urlsTab.isVisible() ) {
			await urlsTab.click();
		}

		// Type a sample URL to show a post-interaction state
		const textarea = page.locator( '.mw-pandoc-url-input__textarea' );
		if ( await textarea.isVisible() ) {
			await textarea.fill( 'https://en.wikipedia.org/wiki/Pandoc' );
		}

		await page.screenshot( {
			path: path.join( SCREENSHOTS_DIR, 'import-page.png' ),
			fullPage: true,
		} );
	} );

	test( 'export page — page name filled in', async ( { page } ) => {
		await page.goto( `${ BASE_URL }/index.php?title=Special:PandocExport`, {
			waitUntil: 'networkidle',
		} );

		// Wait for the Codex export app to mount
		await page.waitForSelector( '.mw-pandoc-export-root .mw-pandoc-export-app', {
			timeout: 30000,
		} );

		// Fill the page-name lookup input to show a post-interaction state
		const pageInput = page
			.locator( '.mw-pandoc-page-search__lookup input' )
			.first();
		if ( await pageInput.isVisible() ) {
			await pageInput.fill( 'Main Page' );
		}

		await page.screenshot( {
			path: path.join( SCREENSHOTS_DIR, 'export-page.png' ),
			fullPage: true,
		} );
	} );

	test( 'page tools — Export action in page toolbar', async ( { page } ) => {
		// PandocUITestPage is created by the CI setup step before Playwright runs.
		await page.goto( `${ BASE_URL }/index.php?title=PandocUITestPage`, {
			waitUntil: 'networkidle',
		} );

		// In Vector 2022 the additional page actions live in the #p-cactions dropdown.
		// Open it so the "Export" entry is visible in the screenshot.
		const cactionsToggle = page
			.locator(
				'#p-cactions .vector-menu-heading, #p-cactions-label, ' +
					'[data-event-name="ui.dropdown-p-cactions"]'
			)
			.first();
		if ( await cactionsToggle.isVisible() ) {
			await cactionsToggle.click();
			await page.waitForTimeout( 500 );
		}

		await page.screenshot( {
			path: path.join( SCREENSHOTS_DIR, 'page-tools.png' ),
			fullPage: true,
		} );
	} );
} );
