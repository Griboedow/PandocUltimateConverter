'use strict';

const { defineStore } = require( 'pinia' );
const { ref, computed } = require( 'vue' );

let nextId = 1;

/**
 * Generate a UUID v4 string for temporary filenames.
 *
 * @return {string}
 */
function uuidv4() {
	return '10000000-1000-4000-8000-100000000000'.replace( /[018]/g, ( c ) =>
		// eslint-disable-next-line no-bitwise
		( c ^ ( crypto.getRandomValues( new Uint8Array( 1 ) )[ 0 ] & ( 15 >> ( c / 4 ) ) ) ).toString( 16 )
	);
}

/**
 * Derive a wiki page name from a filename.
 * Strips extension, replaces underscores/hyphens with spaces,
 * removes characters invalid in MediaWiki titles.
 *
 * @param {string} filename
 * @return {string}
 */
function pageNameFromFile( filename ) {
	// Remove extension
	let name = filename.replace( /\.[^.]+$/, '' );
	// Replace underscores and hyphens with spaces
	name = name.replace( /[_-]/g, ' ' );
	// Remove MediaWiki-invalid title characters
	name = name.replace( /[/'"$#<>[\]{}|\\]/g, '' );
	// Collapse multiple spaces and trim
	name = name.replace( /\s+/g, ' ' ).trim();
	return name;
}

/**
 * Derive a wiki page name from a URL.
 * Uses the last meaningful path segment, decoded and humanised.
 *
 * @param {string} url
 * @return {string}
 */
function pageNameFromUrl( url ) {
	try {
		const parsed = new URL( url );
		// Get last non-empty path segment
		const segments = parsed.pathname.split( '/' ).filter( Boolean );
		let name = segments.length > 0 ? segments[ segments.length - 1 ] : parsed.hostname;
		// Decode URI component
		name = decodeURIComponent( name );
		// Strip file extension if present
		name = name.replace( /\.[^.]+$/, '' );
		// Replace underscores and hyphens with spaces
		name = name.replace( /[_-]/g, ' ' );
		// Remove invalid characters
		name = name.replace( /[/'"$#<>[\]{}|\\]/g, '' );
		// Collapse whitespace
		name = name.replace( /\s+/g, ' ' ).trim();
		return name || parsed.hostname;
	} catch ( e ) {
		return 'Imported page';
	}
}

/**
 * Get the file extension from a filename.
 *
 * @param {string} filename
 * @return {string}
 */
function getExtension( filename ) {
	const parts = filename.split( '.' );
	return parts.length > 1 ? parts.pop().toLowerCase() : '';
}

module.exports = exports = defineStore( 'converter', () => {
	const TITLE_MIN = mw.config.get( 'pandocCodexTitleMinLength' ) || 4;
	const TITLE_MAX = mw.config.get( 'pandocCodexTitleMaxLength' ) || 255;

	const items = ref( [] );
	const isConverting = ref( false );
	const stopRequested = ref( false );
	const isFetchingTitles = ref( false );
	const overwriteExisting = ref( false );
	const globalErrors = ref( [] );

	// Debounce timers keyed by item id for page-existence checks
	const checkTimers = {};

	const queuedCount = computed( () =>
		items.value.filter( ( item ) => item.status !== 'done' ).length
	);

	const overwriteCount = computed( () =>
		items.value.filter( ( item ) => item.pageExists === true ).length
	);

	const canConvert = computed( () =>
		items.value.length > 0 &&
		!isConverting.value &&
		items.value.every( ( item ) => item.status !== 'uploading' && item.status !== 'converting' )
	);

	/**
	 * Ensure a page name is unique among current queue items.
	 * If the name already exists, append _1, _2, etc.
	 *
	 * @param {string} baseName
	 * @return {string}
	 */
	function deduplicatePageName( baseName ) {
		const existing = items.value.map( ( item ) => item.targetPageName.toLowerCase() );
		if ( existing.indexOf( baseName.toLowerCase() ) === -1 ) {
			return baseName;
		}
		let counter = 1;
		while ( existing.indexOf( ( baseName + '_' + counter ).toLowerCase() ) !== -1 ) {
			counter++;
		}
		return baseName + '_' + counter;
	}

	/**
	 * Add files from a FileList to the conversion queue.
	 *
	 * @param {FileList} fileList
	 */
	function addFiles( fileList ) {
		for ( let i = 0; i < fileList.length; i++ ) {
			const file = fileList[ i ];
			const id = nextId++;
			items.value.push( {
				id: id,
				sourceType: 'file',
				source: file,
				displayName: file.name,
				targetPageName: deduplicatePageName( pageNameFromFile( file.name ) ),
				userEditedPageName: false,
				pageExists: null,
				status: 'queued',
				errorMessage: '',
				resultPageUrl: '',
				uploadedFileName: ''
			} );
			schedulePageExistsCheck( id );
		}
	}

	/**
	 * Add URLs from a newline-separated string.
	 * Fetches real page titles from the backend first, then adds items to the queue.
	 *
	 * @param {string} text
	 * @return {jQuery.Promise}
	 */
	function addUrls( text ) {
		const lines = text.split( '\n' ).map( ( l ) => l.trim() ).filter( Boolean );
		if ( lines.length === 0 ) {
			return $.Deferred().resolve().promise();
		}

		isFetchingTitles.value = true;

		return fetchUrlTitles( lines ).then( ( titleMap ) => {
			for ( const line of lines ) {
				const id = nextId++;
				const fetchedTitle = titleMap[ line ];
				const baseName = fetchedTitle || pageNameFromUrl( line );
				items.value.push( {
					id: id,
					sourceType: 'url',
					source: line,
					displayName: line,
					targetPageName: deduplicatePageName( baseName ),
					userEditedPageName: false,
					pageExists: null,
					status: 'queued',
					errorMessage: '',
					resultPageUrl: '',
					uploadedFileName: ''
				} );
				schedulePageExistsCheck( id );
			}
		} ).always( () => {
			isFetchingTitles.value = false;
		} );
	}

	/**
	 * Fetch HTML <title> tags for URLs via the backend API.
	 * Makes one request per URL to avoid pipe-separator issues.
	 *
	 * @param {string[]} urls
	 * @return {jQuery.Promise} Resolves with an object mapping URL → title string.
	 */
	function fetchUrlTitles( urls ) {
		const api = new mw.Api();
		const titleMap = {};

		const promises = urls.map( ( url ) =>
			api.get( {
				action: 'pandocurltitle',
				urls: url,
				format: 'json'
			} ).then( ( data ) => {
				const results = data.pandocurltitle && data.pandocurltitle.results;
				if ( results && results[ 0 ] && results[ 0 ].title && !results[ 0 ].error ) {
					titleMap[ url ] = results[ 0 ].title;
				}
			} ).catch( () => {
				// Silently fall back to URL-based name
			} )
		);

		return $.when.apply( $, promises ).then( () => titleMap );
	}

	/**
	 * Remove an item from the queue.
	 *
	 * @param {number} id
	 */
	function removeItem( id ) {
		const idx = items.value.findIndex( ( item ) => item.id === id );
		if ( idx !== -1 ) {
			items.value.splice( idx, 1 );
		}
		if ( checkTimers[ id ] ) {
			clearTimeout( checkTimers[ id ] );
			delete checkTimers[ id ];
		}
	}

	/**
	 * Clear all items from the queue.
	 */
	function clearAll() {
		items.value = [];
		for ( const key of Object.keys( checkTimers ) ) {
			clearTimeout( checkTimers[ key ] );
			delete checkTimers[ key ];
		}
	}

	/**
	 * Schedule a debounced page-existence check for an item.
	 *
	 * @param {number} id
	 */
	function schedulePageExistsCheck( id ) {
		if ( checkTimers[ id ] ) {
			clearTimeout( checkTimers[ id ] );
		}
		checkTimers[ id ] = setTimeout( () => {
			checkPageExists( id );
		}, 500 );
	}

	/**
	 * Check whether the target page for an item already exists.
	 *
	 * @param {number} id
	 * @return {jQuery.Promise}
	 */
	function checkPageExists( id ) {
		const item = items.value.find( ( i ) => i.id === id );
		if ( !item || !item.targetPageName ) {
			return;
		}
		const api = new mw.Api();
		return api.get( {
			action: 'query',
			titles: item.targetPageName,
			formatversion: 2
		} ).then( ( data ) => {
			const pages = data.query && data.query.pages;
			if ( pages && pages.length > 0 ) {
				item.pageExists = !pages[ 0 ].missing;
			}
		} );
	}

	/**
	 * Update the target page name for an item and re-check existence.
	 *
	 * @param {number} id
	 * @param {string} newName
	 */
	function updatePageName( id, newName ) {
		const item = items.value.find( ( i ) => i.id === id );
		if ( item ) {
			item.targetPageName = newName;
			item.userEditedPageName = true;
			item.pageExists = null;
			schedulePageExistsCheck( id );
		}
	}

	/**
	 * Validate a page name.
	 *
	 * @param {string} name
	 * @return {string|null} Error message key, or null if valid.
	 */
	function validatePageName( name ) {
		if ( name.length < TITLE_MIN || name.length > TITLE_MAX ) {
			return 'pandocultimateconverter-warning-page-name-length';
		}
		const invalidChars = name.match( /[/'"$]/g );
		if ( invalidChars ) {
			return 'pandocultimateconverter-warning-page-name-invalid-character';
		}
		return null;
	}

	/**
	 * Convert all queued items sequentially.
	 *
	 * @return {jQuery.Promise}
	 */
	function convertAll() {
		isConverting.value = true;
		stopRequested.value = false;
		globalErrors.value = [];

		// Install navigation guard
		const beforeUnload = ( e ) => {
			e.preventDefault();
			e.returnValue = mw.msg( 'pandocultimateconverter-codex-navigate-warning' );
			return e.returnValue;
		};
		window.addEventListener( 'beforeunload', beforeUnload );

		const itemsToProcess = items.value.filter(
			( item ) => item.status === 'queued' || item.status === 'error'
		);

		let chain = $.Deferred().resolve().promise();

		for ( const item of itemsToProcess ) {
			chain = chain.then( () => {
				if ( stopRequested.value ) {
					return $.Deferred().resolve().promise();
				}
				return convertSingleItem( item );
			} );
		}

		return chain.always( () => {
			isConverting.value = false;
			stopRequested.value = false;
			window.removeEventListener( 'beforeunload', beforeUnload );
		} );
	}

	/**
	 * Request stopping the current mass conversion after the active item finishes.
	 */
	function stopConversion() {
		stopRequested.value = true;
	}

	/**
	 * Retry a single failed item.
	 *
	 * @param {number} id
	 * @return {jQuery.Promise}
	 */
	function retryItem( id ) {
		const item = items.value.find( ( i ) => i.id === id );
		if ( !item ) {
			return $.Deferred().reject().promise();
		}

		const beforeUnload = ( e ) => {
			e.preventDefault();
			e.returnValue = mw.msg( 'pandocultimateconverter-codex-navigate-warning' );
			return e.returnValue;
		};
		window.addEventListener( 'beforeunload', beforeUnload );

		return convertSingleItem( item ).always( () => {
			window.removeEventListener( 'beforeunload', beforeUnload );
		} );
	}

	/**
	 * Process a single conversion item (upload if file, then call pandocconvert API).
	 *
	 * @param {Object} item
	 * @return {jQuery.Promise}
	 */
	function convertSingleItem( item ) {
		const api = new mw.Api();
		item.errorMessage = '';

		if ( item.sourceType === 'file' ) {
			return convertFileItem( api, item );
		}
		return convertUrlItem( api, item );
	}

	/**
	 * Classify upload errors into global (shown at top) vs per-item.
	 * Global errors are things like uploads disabled, must be logged in.
	 *
	 * @param {string} code
	 * @return {boolean} True if this is a global error.
	 */
	function isGlobalUploadError( code ) {
		return [ 'uploaddisabled', 'mustbeloggedin', 'permissiondenied',
			'badaccess-groups', 'readonlytext' ].indexOf( code ) !== -1;
	}

	/**
	 * Convert a file-source item: upload → pandocconvert → cleanup.
	 * If the file was already uploaded (e.g. on retry), skip the upload step.
	 *
	 * @param {mw.Api} api
	 * @param {Object} item
	 * @return {jQuery.Promise}
	 */
	function convertFileItem( api, item ) {
		// If we already uploaded this file, skip straight to conversion
		if ( item.uploadedFileName ) {
			return convertWithUploadedFile( api, item, item.uploadedFileName );
		}

		item.status = 'uploading';
		const ext = getExtension( item.source.name );
		const tempFileName = 'pandocultimateconverter-' + uuidv4() + '.' + ext;

		return api.upload( item.source, {
			format: 'json',
			stash: false,
			ignorewarnings: 1,
			filename: tempFileName
		} ).then(
			() => {
				item.uploadedFileName = tempFileName;
				return convertWithUploadedFile( api, item, tempFileName );
			},
			( code, errorObj ) => {
				// "fileexists-no-change" or "duplicate" means the file is already there
				if ( code === 'fileexists-no-change' || code === 'duplicate' ||
					code === 'exists' || code === 'was-deleted' ) {
					item.uploadedFileName = tempFileName;
					return convertWithUploadedFile( api, item, tempFileName );
				}

				// Check if the upload response actually succeeded despite jQuery
				// reporting an error (MW upload API sends warnings as errors)
				const upload = errorObj && errorObj.upload;
				if ( upload && upload.result === 'Success' ) {
					item.uploadedFileName = upload.filename || tempFileName;
					return convertWithUploadedFile( api, item, item.uploadedFileName );
				}

				// Global errors go to the top-level banner
				if ( isGlobalUploadError( code ) ) {
					const msg = ( errorObj && errorObj.error && errorObj.error.info ) || code;
					if ( globalErrors.value.indexOf( msg ) === -1 ) {
						globalErrors.value.push( msg );
					}
				}

				item.status = 'error';
				item.errorMessage = ( errorObj && errorObj.error && errorObj.error.info ) || code;
				return $.Deferred().resolve().promise();
			}
		);
	}

	/**
	 * Run the pandocconvert step for an already-uploaded file, then clean up.
	 *
	 * @param {mw.Api} api
	 * @param {Object} item
	 * @param {string} tempFileName
	 * @return {jQuery.Promise}
	 */
	function convertWithUploadedFile( api, item, tempFileName ) {
		item.status = 'converting';
		const params = {
			action: 'pandocconvert',
			pagename: item.targetPageName,
			filename: tempFileName
		};
		if ( overwriteExisting.value ) {
			params.forceoverwrite = 1;
		}
		return api.postWithEditToken( params, { timeout: 5 * 60 * 1000 } ).then( ( result ) => {
			item.status = 'done';
			const title = mw.Title.newFromText( result.pandocconvert.pagename );
			item.resultPageUrl = title ? title.getUrl() : '';
			// Cleanup temp file (best-effort, don't block on failure)
			api.postWithEditToken( {
				action: 'delete',
				title: 'File:' + tempFileName,
				reason: mw.msg( 'pandocultimateconverter-conversion-complete-comment' )
			} );
			item.uploadedFileName = '';
		} ).catch( ( code, errorObj ) => {
			item.status = 'error';
			item.errorMessage = ( errorObj && errorObj.error && errorObj.error.info ) || code;
		} );
	}

	/**
	 * Convert a URL-source item: call pandocconvert API directly.
	 *
	 * @param {mw.Api} api
	 * @param {Object} item
	 * @return {jQuery.Promise}
	 */
	function convertUrlItem( api, item ) {
		item.status = 'converting';
		const params = {
			action: 'pandocconvert',
			pagename: item.targetPageName,
			url: item.source
		};
		if ( overwriteExisting.value ) {
			params.forceoverwrite = 1;
		}
		return api.postWithEditToken( params, { timeout: 5 * 60 * 1000 } ).then( ( result ) => {
			item.status = 'done';
			const title = mw.Title.newFromText( result.pandocconvert.pagename );
			item.resultPageUrl = title ? title.getUrl() : '';
		} ).catch( ( code, errorObj ) => {
			item.status = 'error';
			item.errorMessage = ( errorObj && errorObj.error && errorObj.error.info ) || code;
		} );
	}

	return {
		items,
		isConverting,
		stopRequested,
		isFetchingTitles,
		overwriteExisting,
		globalErrors,
		queuedCount,
		overwriteCount,
		canConvert,
		addFiles,
		addUrls,
		removeItem,
		clearAll,
		updatePageName,
		validatePageName,
		schedulePageExistsCheck,
		convertAll,
		stopConversion,
		retryItem,
		TITLE_MIN,
		TITLE_MAX
	};
} );
