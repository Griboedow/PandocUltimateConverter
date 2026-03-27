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

		<!-- Unified item list (pages & categories) -->
		<div class="mw-pandoc-export-app__section">
			<label class="mw-pandoc-export-app__label">
				{{ $i18n( 'pandocultimateconverter-export-items-label' ).text() }}
			</label>

			<div
				v-for="( item, index ) in items"
				:key="index"
				class="mw-pandoc-export-app__page-row"
				:class="{ 'mw-pandoc-export-app__page-row--dragging': dragIndex === index,
					'mw-pandoc-export-app__page-row--over': dragOverIndex === index && dragIndex !== index }"
				:draggable="!isExporting && items.length > 1"
				@dragstart="onDragStart( index, $event )"
				@dragover.prevent="onDragOver( index )"
				@dragend="onDragEnd"
			>
				<span
					v-if="items.length > 1"
					class="mw-pandoc-export-app__drag-handle"
					:title="$i18n( 'pandocultimateconverter-export-drag-hint' ).text()"
				>⠿</span>
				<page-search-input
					:model-value="item"
					:disabled="isExporting"
					@update:model-value="updateItem( index, $event )"
				></page-search-input>
				<cdx-button
					weight="quiet"
					action="destructive"
					:disabled="isExporting"
					:aria-label="$i18n( 'pandocultimateconverter-export-remove-page' ).text()"
					@click="removeItem( index )"
				>
					✕
				</cdx-button>
			</div>

			<cdx-button
				weight="normal"
				action="progressive"
				:disabled="isExporting"
				class="mw-pandoc-export-app__add-btn"
				@click="addItem"
			>
				{{ $i18n( 'pandocultimateconverter-export-add-item' ).text() }}
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

		<!-- Export button & separate files toggle -->
		<div class="mw-pandoc-export-app__actions">
			<cdx-checkbox
				v-model="separateFiles"
				:disabled="isExporting"
			>
				{{ $i18n( 'pandocultimateconverter-export-separate-files' ).text() }}
			</cdx-checkbox>

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
const { CdxButton, CdxMessage, CdxSelect, CdxCheckbox } = require( '@wikimedia/codex' );
const PageSearchInput = require( './components/PageSearchInput.vue' );

// @vue/component
module.exports = exports = defineComponent( {
	name: 'PandocExportApp',
	components: {
		CdxButton,
		CdxMessage,
		CdxSelect,
		CdxCheckbox,
		PageSearchInput
	},
	setup() {
		const preloaded = mw.config.get( 'pandocExportInitialPages' ) || [];
		const items = ref( preloaded.length > 0 ? preloaded : [ '' ] );
		const selectedFormat = ref( 'docx' );
		const separateFiles = ref( false );
		const isExporting = ref( false );
		const errorMsg = ref( '' );
		const dragIndex = ref( null );
		const dragOverIndex = ref( null );

		const rawFormats = mw.config.get( 'pandocExportFormats' ) || {};
		const formatOptions = Object.keys( rawFormats ).map( ( key ) => ( {
			value: key,
			label: rawFormats[ key ].label
		} ) );

		const canExport = computed( () => {
			const filled = items.value.filter( ( v ) => v.trim().length > 0 );
			return filled.length > 0 && !isExporting.value;
		} );

		function addItem() {
			items.value.push( '' );
		}

		function removeItem( index ) {
			if ( items.value.length === 1 ) {
				items.value[ 0 ] = '';
			} else {
				items.value.splice( index, 1 );
			}
		}

		function updateItem( index, value ) {
			items.value[ index ] = value;
		}

		function onDragStart( index, event ) {
			dragIndex.value = index;
			event.dataTransfer.effectAllowed = 'move';
		}

		function onDragOver( index ) {
			if ( dragIndex.value === null || dragIndex.value === index ) {
				dragOverIndex.value = null;
				return;
			}
			dragOverIndex.value = index;
			const moved = items.value.splice( dragIndex.value, 1 )[ 0 ];
			items.value.splice( index, 0, moved );
			dragIndex.value = index;
		}

		function onDragEnd() {
			dragIndex.value = null;
			dragOverIndex.value = null;
		}

		function handleExport() {
			const filled = items.value.filter( ( v ) => v.trim().length > 0 );
			if ( filled.length === 0 ) {
				errorMsg.value = mw.msg( 'pandocultimateconverter-export-error-no-pages' );
				return;
			}

			const endpoint = mw.config.get( 'pandocExportEndpoint' );
			const params = new URLSearchParams();
			params.set( 'format', selectedFormat.value );

			filled.forEach( ( v ) => {
				params.append( 'items[]', v );
			} );

			if ( separateFiles.value ) {
				params.set( 'separate', '1' );
			}

			const url = endpoint + '?' + params.toString();

			isExporting.value = true;
			errorMsg.value = '';

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
					let filename = 'export';
					const cd = response.headers.get( 'Content-Disposition' ) || '';
					// Prefer RFC 5987 filename* (URL-encoded UTF-8)
					const starMatch = cd.match( /filename\*\s*=\s*UTF-8''([^;\s]+)/i );
					if ( starMatch ) {
						filename = decodeURIComponent( starMatch[ 1 ] );
					} else {
						// Fall back to quoted filename="..."
						const quotedMatch = cd.match( /filename\s*=\s*"([^"]+)"/i );
						if ( quotedMatch ) {
							filename = quotedMatch[ 1 ];
						}
					}
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
			items,
			selectedFormat,
			separateFiles,
			isExporting,
			errorMsg,
			formatOptions,
			canExport,
			dragIndex,
			dragOverIndex,
			addItem,
			removeItem,
			updateItem,
			onDragStart,
			onDragOver,
			onDragEnd,
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
		transition: opacity 0.15s;

		&--dragging {
			opacity: 0.4;
		}

		&--over {
			border-top: 2px solid @color-progressive;
		}
	}

	&__drag-handle {
		cursor: grab;
		user-select: none;
		padding: @spacing-25 @spacing-25;
		color: @color-subtle;
		font-size: @font-size-large;
		line-height: 32px;

		&:active {
			cursor: grabbing;
		}
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
		display: flex;
		flex-direction: column;
		gap: @spacing-100;
		align-items: flex-start;
	}
}
</style>
