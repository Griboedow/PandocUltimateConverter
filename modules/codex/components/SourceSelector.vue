<template>
	<cdx-tabs v-model:active="activeTab">
		<cdx-tab
			name="files"
			:label="$i18n( 'pandocultimateconverter-codex-tab-files' ).text()"
		>
			<div
				class="mw-pandoc-dropzone"
				:class="{ 'mw-pandoc-dropzone--active': isDragOver }"
				@dragover.prevent="isDragOver = true"
				@dragleave.prevent="isDragOver = false"
				@drop.prevent="onDrop"
				@click="onDropzoneClick"
			>
				<input
					ref="fileInput"
					type="file"
					multiple
					class="mw-pandoc-dropzone__input"
					@change="onFileSelect"
				>
				<div class="mw-pandoc-dropzone__content">
					<span class="mw-pandoc-dropzone__icon">⬆</span>
					<p class="mw-pandoc-dropzone__text">
						{{ $i18n( 'pandocultimateconverter-codex-dropzone-text' ).text() }}
					</p>
					<p class="mw-pandoc-dropzone__hint">
						{{ $i18n( 'pandocultimateconverter-codex-dropzone-hint' ).text() }}
					</p>
				</div>
			</div>
		</cdx-tab>
		<cdx-tab
			name="urls"
			:label="$i18n( 'pandocultimateconverter-codex-tab-urls' ).text()"
		>
			<div class="mw-pandoc-url-input">
				<textarea
					v-model="urlText"
					class="mw-pandoc-url-input__textarea"
					:placeholder="$i18n( 'pandocultimateconverter-codex-urls-placeholder' ).text()"
					rows="4"
				></textarea>
				<cdx-button
					class="mw-pandoc-url-input__button"
					weight="normal"
					action="progressive"
					:disabled="isAddDisabled"
					@click="onAddUrls"
				>
					{{ addButtonLabel }}
				</cdx-button>
			</div>
		</cdx-tab>
	</cdx-tabs>
</template>

<script>
const { defineComponent, ref, computed } = require( 'vue' );
const { CdxButton, CdxTab, CdxTabs } = require( '@wikimedia/codex' );
const useConverterStore = require( '../stores/converter.js' );

// @vue/component
module.exports = exports = defineComponent( {
	name: 'SourceSelector',
	components: {
		CdxButton,
		CdxTab,
		CdxTabs
	},
	setup() {
		const store = useConverterStore();
		const activeTab = ref( 'files' );
		const isDragOver = ref( false );
		const urlText = ref( '' );
		const fileInput = ref( null );

		const isAddDisabled = computed( () =>
			urlText.value.trim().length === 0 || store.isFetchingTitles
		);

		const addButtonLabel = computed( () =>
			store.isFetchingTitles
				? mw.msg( 'pandocultimateconverter-codex-urls-fetching' )
				: mw.msg( 'pandocultimateconverter-codex-urls-add' )
		);

		function onDrop( event ) {
			isDragOver.value = false;
			const files = event.dataTransfer && event.dataTransfer.files;
			if ( files && files.length ) {
				store.addFiles( files );
			}
		}

		function onDropzoneClick() {
			if ( fileInput.value ) {
				fileInput.value.click();
			}
		}

		function onFileSelect( event ) {
			const files = event.target.files;
			if ( files && files.length ) {
				store.addFiles( files );
			}
			// Reset input so the same file(s) can be re-selected
			event.target.value = '';
		}

		function onAddUrls() {
			if ( urlText.value.trim() ) {
				const text = urlText.value;
				store.addUrls( text ).always( () => {
					urlText.value = '';
				} );
			}
		}

		return {
			activeTab,
			isDragOver,
			urlText,
			fileInput,
			isAddDisabled,
			addButtonLabel,
			onDrop,
			onDropzoneClick,
			onFileSelect,
			onAddUrls
		};
	}
} );
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.mw-pandoc-dropzone {
	border: 2px dashed @border-color-interactive;
	border-radius: @border-radius-base;
	padding: @spacing-200;
	text-align: center;
	cursor: pointer;
	transition-property: border-color, background-color;
	transition-duration: @transition-duration-base;
	background-color: @background-color-interactive-subtle;

	&:hover {
		border-color: @border-color-progressive;
		background-color: @background-color-progressive-subtle;
	}

	&--active {
		border-color: @border-color-progressive;
		background-color: @background-color-progressive-subtle;
	}

	&__input {
		display: none;
	}

	&__icon {
		display: block;
		font-size: 2em;
		margin-bottom: @spacing-50;
		color: @color-progressive;
	}

	&__text {
		font-weight: @font-weight-bold;
		margin-bottom: @spacing-25;
	}

	&__hint {
		color: @color-subtle;
		font-size: @font-size-small;
	}
}

.mw-pandoc-url-input {
	display: flex;
	flex-direction: column;
	gap: @spacing-75;

	&__textarea {
		width: 100%;
		padding: @spacing-50 @spacing-75;
		border: @border-width-base @border-style-base @border-color-base;
		border-radius: @border-radius-base;
		font-family: inherit;
		font-size: inherit;
		resize: vertical;
		box-sizing: border-box;
	}

	&__button {
		align-self: flex-end;
	}
}
</style>
