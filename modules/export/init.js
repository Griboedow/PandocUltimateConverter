'use strict';

const container = document.querySelector( '.mw-pandoc-export-root' );
if ( container ) {
	const Vue = require( 'vue' );
	const App = require( './App.vue' );

	Vue.createMwApp( App ).mount( container );
}
