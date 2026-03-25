'use strict';

const container = document.querySelector( '.mw-confluence-migration-root' );
if ( container ) {
	const Vue = require( 'vue' );
	const App = require( './App.vue' );

	Vue.createMwApp( App ).mount( container );
}
