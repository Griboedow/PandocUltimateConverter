const { test, expect } = require( '@playwright/test' );
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const base = process.env.MW_BASE_URL || 'http://127.0.0.1:8080';

test( 'DOCX imports through both interfaces preserve text and tables', async ( { page } ) => {
	const dir = fs.mkdtempSync( path.join( os.tmpdir(), 'pandoc-ui-import-' ) );
	const file = path.join( dir, 'Browser-import.docx' );
	try {
		execFileSync( 'pandoc', [ '-f', 'markdown', '-o', file ], {
			input: '# Imported heading\n\nPreserved browser text.\n\n| Left | Right |\n| --- | --- |\n| Alpha | Beta |\n'
		} );
		await page.goto( `${ base }/index.php?title=Special:UserLogin` );
		await page.locator( '#wpName1' ).fill( process.env.MW_ADMIN_USER || 'admin' );
		await page.locator( '#wpPassword1' ).fill( process.env.MW_ADMIN_PASS || 'adminpassword' );
		await Promise.all( [ page.waitForNavigation(), page.locator( '#wpLoginAttempt' ).click() ] );
		for ( const codex of [ 1, 0 ] ) {
			const title = `Browser import ${ codex } ${ Date.now() }`;
			await page.goto( `${ base }/index.php?title=Special:PandocUltimateConverter&codex=${ codex }`, { waitUntil: 'networkidle' } );
			await page.locator( 'input[type=file]' ).setInputFiles( file );
			if ( codex ) {
				await page.locator( '.mw-pandoc-codex-root table input' ).fill( title );
				await page.getByRole( 'button', { name: 'Convert all', exact: true } ).click();
				await expect( page.locator( '.mw-pandoc-status__done a' ) ).toBeVisible( { timeout: 30000 } );
				await page.locator( '.mw-pandoc-status__done a' ).click();
			} else {
				await expect( page.locator( 'input[name=codex]' ) ).toHaveValue( '0' );
				await page.locator( '#wpArticleTitle' ).fill( title );
				await page.getByRole( 'button', { name: 'Upload and convert', exact: true } ).click();
			}
			await expect( page.locator( '#firstHeading' ) ).toHaveText( title, { timeout: 30000 } );
			await expect( page.locator( '#mw-content-text' ) ).toContainText( 'Preserved browser text.' );
			await expect( page.locator( '#mw-content-text table' ) ).toContainText( 'Alpha' );
		}
	} finally {
		fs.rmSync( dir, { recursive: true, force: true } );
	}
} );
