<template>
	<div class="mw-pandoc-codex-app">
		<cdx-message
			v-for="( error, index ) in store.globalErrors"
			:key="'err-' + index"
			type="error"
			class="mw-pandoc-codex-app__global-error"
			:allow-user-dismiss="true"
			@user-dismiss="store.globalErrors.splice( index, 1 )"
		>
			{{ error }}
		</cdx-message>

		<cdx-message
			v-if="successCount > 0 && !store.isConverting"
			type="success"
			class="mw-pandoc-codex-app__success"
			:allow-user-dismiss="true"
		>
			{{ successCount }} {{ successCount === 1 ? 'item' : 'items' }} converted successfully.
		</cdx-message>

		<p class="mw-pandoc-codex-app__description">
			{{ $i18n( 'pandocultimateconverter-codex-description' ).text() }}
			<a :href="classicUrl" class="mw-pandoc-codex-app__classic-link">
				{{ $i18n( 'pandocultimateconverter-codex-switch-classic' ).text() }}
			</a>
		</p>

		<source-selector></source-selector>

		<conversion-queue
			v-if="store.items.length > 0"
		></conversion-queue>

		<div
			v-if="store.items.length > 0"
			class="mw-pandoc-codex-app__actions"
		>
			<div class="mw-pandoc-codex-app__actions-left">
				<cdx-checkbox
					v-model="store.overwriteExisting"
					:disabled="store.isConverting"
				>
					{{ $i18n( 'pandocultimateconverter-codex-overwrite-toggle' ).text() }}
				</cdx-checkbox>
			</div>
			<div class="mw-pandoc-codex-app__actions-right">
				<cdx-button
					weight="normal"
					action="default"
					:disabled="store.isConverting"
					@click="store.clearAll()"
				>
					{{ $i18n( 'pandocultimateconverter-codex-clear-all' ).text() }}
				</cdx-button>
				<cdx-button
					v-if="store.isConverting"
					weight="primary"
					action="destructive"
					:disabled="store.stopRequested"
					@click="store.stopConversion()"
				>
					{{ $i18n( store.stopRequested
						? 'pandocultimateconverter-codex-stop-requested'
						: 'pandocultimateconverter-codex-stop'
					).text() }}
				</cdx-button>
				<cdx-button
					v-else
					weight="primary"
					action="progressive"
					:disabled="!store.canConvert"
					@click="handleConvertAll"
				>
					{{ $i18n( 'pandocultimateconverter-codex-convert-all' ).text() }}
				</cdx-button>
			</div>
		</div>
	</div>
</template>

<script>
const { defineComponent, ref, computed } = require( 'vue' );
const { CdxButton, CdxMessage, CdxCheckbox } = require( '@wikimedia/codex' );
const useConverterStore = require( './stores/converter.js' );
const SourceSelector = require( './components/SourceSelector.vue' );
const ConversionQueue = require( './components/ConversionQueue.vue' );

// @vue/component
module.exports = exports = defineComponent( {
	name: 'PandocConverterApp',
	components: {
		CdxButton,
		CdxMessage,
		CdxCheckbox,
		SourceSelector,
		ConversionQueue
	},
	setup() {
		const store = useConverterStore();
		const successCount = computed( () =>
			store.items.filter( ( item ) => item.status === 'done' ).length
		);

		const classicUrl = mw.util.getUrl(
			mw.config.get( 'wgPageName' ),
			{ codex: '0' }
		);

		function handleConvertAll() {
			store.convertAll();
		}

		return {
			store,
			successCount,
			classicUrl,
			handleConvertAll
		};
	}
} );
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.mw-pandoc-codex-app {
	max-width: 1280px;

	&__global-error {
		margin-bottom: @spacing-75;
	}

	&__description {
		color: @color-subtle;
		margin-bottom: @spacing-100;
	}

	&__classic-link {
		font-size: @font-size-small;
	}

	&__success {
		margin-bottom: @spacing-100;
	}

	&__actions {
		display: flex;
		align-items: center;
		justify-content: space-between;
		margin-top: @spacing-100;
		padding-top: @spacing-100;
		border-top: @border-width-base @border-style-base @border-color-subtle;
	}

	&__actions-right {
		display: flex;
		gap: @spacing-75;
	}
}
</style>
