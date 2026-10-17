// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	workers: 1, // One PHP server and shared database; avoid concurrent imports/exports.
	testDir: './specs',
	timeout: 60000,
	use: {
		screenshot: 'off',
		video: 'off',
		trace: 'off',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
	reporter: [ [ 'list' ] ],
	outputDir: 'test-results',
} );
