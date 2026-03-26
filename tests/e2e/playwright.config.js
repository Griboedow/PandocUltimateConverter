// @ts-check
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './specs',
	/* Maximum time one test can run (including pandoc conversion + MW save). */
	timeout: 90_000,
	/* Re-try once on CI to absorb transient timing issues. */
	retries: process.env.CI ? 1 : 0,
	/* Never run tests in parallel – we share a single MW instance. */
	workers: 1,
	reporter: [
		[ 'list' ],
		[ 'html', { outputFolder: 'playwright-report', open: 'never' } ]
	],
	use: {
		baseURL: process.env.MW_BASE_URL || 'http://localhost:8080',
		/* Always capture screenshots so we have visual proof regardless of outcome. */
		screenshot: 'on',
		/* Capture traces on failure to ease debugging. */
		trace: 'on-first-retry',
	},
} );
