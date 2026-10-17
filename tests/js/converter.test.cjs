const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const { webcrypto } = require( 'node:crypto' );
const { JSDOM } = require( 'jsdom' );

function fixture( options = {} ) {
	const window = new JSDOM( '' ).window;
	const $ = require( 'jquery' )( window );
	const calls = [];
	const resolve = ( value ) => $.Deferred().resolve( value ).promise();
	const reject = ( code ) => $.Deferred().reject( code, { error: { info: code } } ).promise();
	let failures = options.failures || 0;
	const api = {
		upload( file, params ) {
			calls.push( { action: 'upload', ...params } );
			return options.uploadFailure ? reject( options.uploadFailure ) : resolve( { upload: { filekey: 'private-key.docx', result: 'Success' } } );
		},
		postWithEditToken( params ) {
			calls.push( params );
			if ( failures-- > 0 ) { return reject( 'protectedpage' ); }
			return resolve( { [ params.action ]: { result: options.queued ? 'queued' : 'success', pagename: params.pagename } } );
		},
		get() { return resolve( { query: { pages: [ { missing: true } ] } } ); }
	};
	const context = {
		module: { exports: {} }, exports: {}, $, window, crypto: webcrypto, URL,
		setTimeout: () => 1, clearTimeout() {},
		mw: {
			config: { get: ( key ) => key === 'pandocCodexLlmAvailable' ? true : null },
			Api: function () { return api; }, msg: ( key ) => key,
			Title: { newFromText: ( title ) => ( { getUrl: () => '/wiki/' + title } ) }
		},
		require: ( name ) => name === 'pinia'
			? { defineStore: ( name, create ) => create }
			: { ref: ( value ) => ( { value } ), computed: ( get ) => ( { get value() { return get(); } } ) }
	};
	vm.runInNewContext( fs.readFileSync( require.resolve( '../../modules/codex/stores/converter.js' ), 'utf8' ), context );
	const store = context.module.exports();
	store.addFiles( [ { name: 'Document.docx' } ] );
	return { store, calls, window };
}

test( 'private document import never publishes or deletes a File page', async () => {
	const { store, calls, window } = fixture();
	await store.convertAll();
	assert.equal( calls[ 0 ].stash, true );
	assert.equal( calls[ 1 ].filekey, 'private-key.docx' );
	assert.equal( calls[ 1 ].sourcefilename, 'Document.docx' );
	assert.deepEqual( calls.map( ( c ) => c.action ), [ 'upload', 'pandocconvert' ] );
	assert.equal( store.items.value[ 0 ].status, 'done' );
	window.close();
} );

test( 'moderated import is terminal but never starts LLM polishing', async () => {
	const { store, calls, window } = fixture( { queued: true } );
	store.llmPolish.value = true;
	await store.convertAll();
	const item = store.items.value[ 0 ];
	assert.equal( item.pendingModeration, true );
	assert.equal( item.status, 'done' );
	assert.equal( item.uploadedFileName, '' );
	await store.convertAll();
	assert.deepEqual( calls.map( ( c ) => c.action ), [ 'upload', 'pandocconvert' ] );
	window.close();
} );

test( 'failed save retries the same private source without another upload', async () => {
	const { store, calls, window } = fixture( { failures: 1 } );
	await store.convertAll();
	assert.equal( store.items.value[ 0 ].status, 'error' );
	assert.equal( store.items.value[ 0 ].uploadedFileName, 'private-key.docx' );
	await store.retryItem( store.items.value[ 0 ].id );
	assert.equal( store.items.value[ 0 ].status, 'done' );
	assert.deepEqual( calls.map( ( c ) => c.action ), [ 'upload', 'pandocconvert', 'pandocconvert' ] );
	window.close();
} );

test( 'upload errors and duplicate warnings cannot convert someone else’s filename', async () => {
	for ( const uploadFailure of [ 'permissiondenied', 'duplicate', 'fileexists-no-change' ] ) {
		const { store, calls, window } = fixture( { uploadFailure } );
		await store.convertAll();
		assert.equal( store.items.value[ 0 ].status, 'error' );
		assert.equal( calls.length, 1 );
		window.close();
	}
} );
