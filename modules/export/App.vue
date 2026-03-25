<template>
	<div class="mw-pandoc-export-app">
		<!-- Global error banner -->
		<cdx-message
			v-if="errorMsg"
			type="error"
			:allow-user-dismiss="true"
			class="mw-pandoc-export-app__error"
			@user-dismiss="errorMsg = ''"
		>
			{{ errorMsg }}
		</cdx-message>

		<p class="mw-pandoc-export-app__description">
			{{ $i18n( 'pandocultimateconverter-export-description' ).text() }}
		</p>

		<!-- Page list -->
		<div class="mw-pandoc-export-app__section">
			<label class="mw-pandoc-export-app__label">
				{{ $i18n( 'pandocultimateconverter-export-pages-label' ).text() }}
			</label>

			<div
				v-for="( page, index ) in pages"
				:key="index"
				class="mw-pandoc-export-app__page-row"
			>
				<page-search-input
					:model-value="page"
					:disabled="isExporting"
					@update:model-value="updatePage( index, $event )"
				></page-search-input>
				<cdx-button
					weight="quiet"
					action="destructive"
					:disabled="isExporting"
					:aria-label="$i18n( 'pandocultimateconverter-export-remove-page' ).text()"
					@click="removePage( index )"
				>
					✕
				</cdx-button>
			</div>

			<cdx-button
				weight="normal"
				action="progressive"
				:disabled="isExporting"
				class="mw-pandoc-export-app__add-btn"
				@click="addPage"
			>
				{{ $i18n( 'pandocultimateconverter-export-add-page' ).text() }}
			</cdx-button>
		</div>

		<!-- Format selector -->
		<div class="mw-pandoc-export-app__section">
			<label
				for="mw-pandoc-export-format"
				class="mw-pandoc-export-app__label"
			>
				{{ $i18n( 'pandocultimateconverter-export-format-label' ).text() }}
			</label>
			<cdx-select
				id="mw-pandoc-export-format"
				v-model:selected="selectedFormat"
				:menu-items="formatOptions"
				:disabled="isExporting"
				class="mw-pandoc-export-app__format-select"
			></cdx-select>
		</div>

		<!-- Export button -->
		<div class="mw-pandoc-export-app__actions">
			<cdx-button
				weight="primary"
				action="progressive"
				:disabled="!canExport"
				@click="handleExport"
			>
				<template v-if="isExporting">
					{{ $i18n( 'pandocultimateconverter-export-preparing' ).text() }}
				</template>
				<template v-else>
					{{ $i18n( 'pandocultimateconverter-export-button' ).text() }}
				</template>
			</cdx-button>
		</div>
	</div>
</template>

<script>
const { defineComponent, ref, computed } = require( 'vue' );
const { CdxButton, CdxMessage, CdxSelect } = require( '@wikimedia/codex' );
const PageSearchInput = require( './components/PageSearchInput.vue' );

/** Milliseconds to keep the export button in the "preparing" state after the download starts. */
const EXPORT_LOADING_DURATION_MS = 5000;

// @vue/component
module.exports = exports = defineComponent( {
	name: 'PandocExportApp',
	components: {
		CdxButton,
		CdxMessage,
		CdxSelect,
		PageSearchInput
	},
	setup() {
		/** @type {import('vue').Ref<string[]>} */
		const pages = ref( [ '' ] );
		const selectedFormat = ref( 'docx' );
		const isExporting = ref( false );
		const errorMsg = ref( '' );

		const rawFormats = mw.config.get( 'pandocExportFormats' ) || {};
		const formatOptions = Object.keys( rawFormats ).map( ( key ) => ( {
			value: key,
			label: rawFormats[ key ].label
		} ) );

		const canExport = computed( () => {
			const filledPages = pages.value.filter( ( p ) => p.trim().length > 0 );
			return filledPages.length > 0 && !isExporting.value;
		} );

		function addPage() {
			pages.value.push( '' );
		}

		function removePage( index ) {
			if ( pages.value.length === 1 ) {
				pages.value[ 0 ] = '';
			} else {
				pages.value.splice( index, 1 );
			}
		}

		function updatePage( index, value ) {
			pages.value[ index ] = value;
		}

		function handleExport() {
			const filledPages = pages.value.filter( ( p ) => p.trim().length > 0 );
			if ( filledPages.length === 0 ) {
				errorMsg.value = mw.msg( 'pandocultimateconverter-export-error-no-pages' );
				return;
			}

			const endpoint = mw.config.get( 'pandocExportEndpoint' );
			const params = new URLSearchParams();
			params.set( 'format', selectedFormat.value );
			filledPages.forEach( ( p ) => params.append( 'pages[]', p ) );

			const url = endpoint + '?' + params.toString();

			isExporting.value = true;
			errorMsg.value = '';

			// Use fetch so we can detect server-side errors (e.g. Pandoc failure)
			// and surface them to the user instead of silently swallowing them
			// inside a hidden iframe.
			fetch( url ).then( ( response ) => {
				const contentType = response.headers.get( 'Content-Type' ) || '';
				if ( !response.ok || contentType.indexOf( 'application/json' ) !== -1 ) {
					return response.json().then( ( data ) => {
						throw new Error( data.error || ( 'Export failed (HTTP ' + response.status + ')' ) );
					} ).catch( ( jsonErr ) => {
						if ( jsonErr instanceof SyntaxError ) {
							throw new Error( 'Export failed (HTTP ' + response.status + ')' );
						}
						throw jsonErr;
					} );
				}
				return response.blob().then( ( blob ) => {
					// Determine filename from Content-Disposition header, fall back to generic.
					let filename = 'export';
					const cd = response.headers.get( 'Content-Disposition' ) || '';
					const match = cd.match( /filename\*?=(?:UTF-8'')?["']?([^"';\s]+)/i );
					if ( match ) {
						filename = decodeURIComponent( match[ 1 ] );
					}
					// Trigger browser download via object URL.
					const a = document.createElement( 'a' );
					a.href = URL.createObjectURL( blob );
					a.download = filename;
					document.body.appendChild( a );
					a.click();
					setTimeout( () => {
						URL.revokeObjectURL( a.href );
						document.body.removeChild( a );
					}, 100 );
				} );
			} ).catch( ( err ) => {
				errorMsg.value = err.message || String( err );
			} ).finally( () => {
				isExporting.value = false;
			} );
		}

		return {
			pages,
			selectedFormat,
			isExporting,
			errorMsg,
			formatOptions,
			canExport,
			addPage,
			removePage,
			updatePage,
			handleExport
		};
	}
} );
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.mw-pandoc-export-app {
	max-width: 800px;

	&__error {
		margin-bottom: @spacing-75;
	}

	&__description {
		color: @color-subtle;
		margin-bottom: @spacing-150;
	}

	&__section {
		margin-bottom: @spacing-150;
	}

	&__label {
		display: block;
		font-weight: @font-weight-bold;
		margin-bottom: @spacing-50;
	}

	&__page-row {
		display: flex;
		align-items: flex-start;
		gap: @spacing-50;
		margin-bottom: @spacing-50;
	}

	&__add-btn {
		margin-top: @spacing-50;
	}

	&__format-select {
		max-width: 300px;
	}

	&__actions {
		padding-top: @spacing-100;
		border-top: @border-width-base @border-style-base @border-color-subtle;
	}
}
</style>
