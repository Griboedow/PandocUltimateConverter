<template>
	<div class="mw-pandoc-page-search">
		<cdx-lookup
			:input-value="modelValue"
			:menu-items="menuItems"
			:placeholder="$i18n( 'pandocultimateconverter-export-page-placeholder' ).text()"
			:disabled="disabled"
			:status="inputStatus"
			class="mw-pandoc-page-search__lookup"
			@input="onInput"
			@update:input-value="onInput"
			@update:selected="onSelect"
		></cdx-lookup>
		<cdx-message
			v-if="inputStatus === 'error'"
			type="error"
			inline
			class="mw-pandoc-page-search__msg"
		>
			{{ $i18n( 'pandocultimateconverter-export-page-not-found' ).text() }}
		</cdx-message>
	</div>
</template>

<script>
const { defineComponent, ref, computed } = require( 'vue' );
const { CdxLookup, CdxMessage } = require( '@wikimedia/codex' );

const DEBOUNCE_MS = 300;
const VALIDATE_MS = 800;
const SUGGESTION_LIMIT = 10;

// @vue/component
module.exports = exports = defineComponent( {
	name: 'PageSearchInput',
	components: {
		CdxLookup,
		CdxMessage
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
		const pageExists = ref( null );
		let debounceTimer = null;
		let validateTimer = null;

		const inputStatus = computed( () =>
			pageExists.value === false ? 'error' : 'default'
		);

		function onInput( value ) {
			emit( 'update:modelValue', value );
			pageExists.value = null;

			if ( debounceTimer ) {
				clearTimeout( debounceTimer );
			}
			if ( validateTimer ) {
				clearTimeout( validateTimer );
			}

			if ( !value || value.trim().length < 2 ) {
				menuItems.value = [];
				return;
			}

			debounceTimer = setTimeout( () => {
				fetchSuggestions( value.trim() );
			}, DEBOUNCE_MS );

			validateTimer = setTimeout( () => {
				checkPageExists( value.trim() );
			}, VALIDATE_MS );
		}

		function onSelect( value ) {
			if ( value !== null && value !== undefined ) {
				emit( 'update:modelValue', value );
				menuItems.value = [];
				pageExists.value = true;
			}
		}

		/**
		 * Fetch autocomplete suggestions from both pages (ns 0) and categories (ns 14).
		 */
		function fetchSuggestions( query ) {
			const api = new mw.Api();
			api.get( {
				action: 'opensearch',
				search: query,
				limit: SUGGESTION_LIMIT,
				redirects: 'resolve',
				format: 'json'
			} ).then( ( data ) => {
				const titles = ( data && data[ 1 ] ) || [];
				menuItems.value = titles.map( ( title ) => ( {
					value: title,
					label: title
				} ) );
			} ).catch( () => {
				menuItems.value = [];
			} );
		}

		/**
		 * Check whether the given title exists as a page or category.
		 */
		function checkPageExists( title ) {
			const api = new mw.Api();
			api.get( {
				action: 'query',
				titles: title,
				format: 'json'
			} ).then( ( data ) => {
				if ( props.modelValue.trim() !== title ) {
					return;
				}
				const pages = data.query && data.query.pages;
				if ( pages ) {
					const ids = Object.keys( pages );
					pageExists.value = !( ids.length === 1 && pages[ ids[ 0 ] ].missing !== undefined );
				}
			} );
		}

		return {
			menuItems,
			inputStatus,
			onInput,
			onSelect
		};
	}
} );
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.mw-pandoc-page-search {
	flex: 1;
	min-width: 0;

	&__lookup {
		width: 100%;
	}

	&__msg {
		margin-top: @spacing-25;
	}
}
</style>
