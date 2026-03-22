<template>
	<div class="mw-pandoc-status">
		<template v-if="item.status === 'queued'">
			<!-- No indicator for queued items -->
		</template>

		<div
			v-else-if="item.status === 'uploading'"
			class="mw-pandoc-status__progress"
		>
			<cdx-progress-bar inline></cdx-progress-bar>
			<span class="mw-pandoc-status__label">
				{{ $i18n( 'pandocultimateconverter-codex-status-uploading' ).text() }}
			</span>
		</div>

		<div
			v-else-if="item.status === 'converting'"
			class="mw-pandoc-status__progress"
		>
			<cdx-progress-bar inline></cdx-progress-bar>
			<span class="mw-pandoc-status__label">
				{{ $i18n( 'pandocultimateconverter-codex-status-converting' ).text() }}
			</span>
		</div>

		<div
			v-else-if="item.status === 'done'"
			class="mw-pandoc-status__done"
		>
			<span class="mw-pandoc-status__done-icon">✓</span>
			<a :href="item.resultPageUrl">
				{{ $i18n( 'pandocultimateconverter-codex-status-done' ).text() }}
			</a>
		</div>

		<div
			v-else-if="item.status === 'error'"
			class="mw-pandoc-status__error"
		>
			<cdx-message
				type="error"
				inline
			>
				{{ $i18n( 'pandocultimateconverter-codex-status-error', item.errorMessage ).text() }}
			</cdx-message>
			<cdx-button
				weight="quiet"
				action="progressive"
				size="medium"
				@click="onRetry"
			>
				{{ $i18n( 'pandocultimateconverter-codex-retry' ).text() }}
			</cdx-button>
		</div>
	</div>
</template>

<script>
const { defineComponent } = require( 'vue' );
const { CdxButton, CdxMessage, CdxProgressBar } = require( '@wikimedia/codex' );
const useConverterStore = require( '../stores/converter.js' );

// @vue/component
module.exports = exports = defineComponent( {
	name: 'ConversionStatus',
	components: {
		CdxButton,
		CdxMessage,
		CdxProgressBar
	},
	props: {
		item: {
			type: Object,
			required: true
		}
	},
	setup( props ) {
		const store = useConverterStore();

		function onRetry() {
			store.retryItem( props.item.id );
		}

		return { onRetry };
	}
} );
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.mw-pandoc-status {
	&__progress {
		display: flex;
		align-items: center;
		gap: @spacing-50;
	}

	&__label {
		font-size: @font-size-small;
		color: @color-subtle;
		white-space: nowrap;
	}

	&__done {
		display: flex;
		align-items: center;
		gap: @spacing-25;
		font-weight: @font-weight-bold;
	}

	&__done-icon {
		color: @color-success;
		font-weight: @font-weight-bold;
	}

	&__error {
		display: flex;
		flex-direction: column;
		gap: @spacing-25;
	}
}
</style>
