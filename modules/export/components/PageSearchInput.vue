<template>
	<div class="mw-pandoc-page-search">
		<cdx-lookup
			:input-value="modelValue"
			:menu-items="menuItems"
			:placeholder="$i18n( 'pandocultimateconverter-export-page-placeholder' ).text()"
			:disabled="disabled"
			class="mw-pandoc-page-search__lookup"
			@update:input-value="onInput"
			@update:selected="onSelect"
		></cdx-lookup>
	</div>
</template>

<script>
const { defineComponent, ref } = require( 'vue' );
const { CdxLookup } = require( '@wikimedia/codex' );

/** Milliseconds to wait after the last keystroke before firing an autocomplete request. */
const DEBOUNCE_MS = 300;
/** Maximum number of autocomplete suggestions to fetch per query. */
const SUGGESTION_LIMIT = 10;

// @vue/component
module.exports = exports = defineComponent( {
	name: 'PageSearchInput',
	components: {
		CdxLookup
	},
	props: {
		modelValue: {
			type: String,
			default: ''
		},
		disabled: {
			type: Boolean,
			default: false
		}
	},
	emits: [ 'update:modelValue' ],
	setup( props, { emit } ) {
		const menuItems = ref( [] );
		let debounceTimer = null;

		function onInput( value ) {
			emit( 'update:modelValue', value );

			if ( debounceTimer ) {
				clearTimeout( debounceTimer );
			}

			if ( !value || value.trim().length < 2 ) {
				menuItems.value = [];
				return;
			}

			debounceTimer = setTimeout( () => {
				fetchSuggestions( value.trim() );
			}, DEBOUNCE_MS );
		}

		function onSelect( value ) {
			if ( value !== null && value !== undefined ) {
				emit( 'update:modelValue', value );
				menuItems.value = [];
			}
		}

		/**
		 * Fetch autocomplete suggestions for the given query using the MediaWiki
		 * opensearch API.
		 *
		 * @param {string} query
		 */
		function fetchSuggestions( query ) {
			const api = new mw.Api();
			api.get( {
				action: 'opensearch',
				search: query,
				limit: SUGGESTION_LIMIT,
				namespace: 0,
				redirects: 'resolve',
				format: 'json'
			} ).then( ( data ) => {
				// opensearch returns [query, [titles], [descriptions], [urls]]
				const titles = ( data && data[ 1 ] ) || [];
				menuItems.value = titles.map( ( title ) => ( {
					value: title,
					label: title
				} ) );
			} ).catch( () => {
				menuItems.value = [];
			} );
		}

		return {
			menuItems,
			onInput,
			onSelect
		};
	}
} );
</script>

<style lang="less">
.mw-pandoc-page-search {
	flex: 1;
	min-width: 0;

	&__lookup {
		width: 100%;
	}
}
</style>
