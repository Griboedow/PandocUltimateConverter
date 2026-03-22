'use strict';

const container = document.querySelector( '.mw-pandoc-codex-root' );
if ( container ) {
	const Vue = require( 'vue' );
	const App = require( './App.vue' );
	const { createPinia } = require( 'pinia' );

	Vue.createMwApp( App )
		.use( createPinia() )
		.mount( container );
}
