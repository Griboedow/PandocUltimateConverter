<template>
	<div class="mw-pandoc-queue">
		<p class="mw-pandoc-queue__summary">
			{{ $i18n( 'pandocultimateconverter-codex-queue-summary', store.queuedCount ).text() }}
		</p>
		<cdx-message
			v-if="store.overwriteCount > 0"
			type="warning"
			class="mw-pandoc-queue__overwrite-msg"
		>
			{{ $i18n( 'pandocultimateconverter-codex-queue-overwrite-warning', store.overwriteCount ).text() }}
		</cdx-message>

		<table class="mw-pandoc-queue__table">
			<thead>
				<tr>
					<th class="mw-pandoc-queue__col-source">
						{{ $i18n( 'pandocultimateconverter-codex-column-source' ).text() }}
					</th>
					<th class="mw-pandoc-queue__col-target">
						{{ $i18n( 'pandocultimateconverter-codex-column-target' ).text() }}
					</th>
					<th class="mw-pandoc-queue__col-status">
						{{ $i18n( 'pandocultimateconverter-codex-column-status' ).text() }}
					</th>
					<th class="mw-pandoc-queue__col-actions">
						{{ $i18n( 'pandocultimateconverter-codex-column-actions' ).text() }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="item in store.items"
					:key="item.id"
					class="mw-pandoc-queue__row"
					:class="{
						'mw-pandoc-queue__row--done': item.status === 'done',
						'mw-pandoc-queue__row--error': item.status === 'error'
					}"
				>
					<td class="mw-pandoc-queue__col-source">
						<span class="mw-pandoc-queue__source-icon">
							{{ item.sourceType === 'file' ? '📄' : '🔗' }}
						</span>
						<span
							class="mw-pandoc-queue__source-name"
							:title="item.displayName"
						>
							{{ truncate( item.displayName, 40 ) }}
						</span>
					</td>
					<td class="mw-pandoc-queue__col-target">
						<page-name-input
							:item="item"
							:disabled="store.isConverting || item.status === 'uploading' || item.status === 'converting'"
						></page-name-input>
					</td>
					<td class="mw-pandoc-queue__col-status">
						<conversion-status :item="item"></conversion-status>
					</td>
					<td class="mw-pandoc-queue__col-actions">
						<cdx-button
							v-if="item.status === 'queued' || item.status === 'error'"
							weight="quiet"
							action="progressive"
							class="mw-pandoc-queue__play-btn"
							:disabled="store.isConverting"
							:title="$i18n( 'pandocultimateconverter-codex-convert-one' ).text()"
							:aria-label="$i18n( 'pandocultimateconverter-codex-convert-one' ).text()"
							@click="store.retryItem( item.id )"
						>
							▶
						</cdx-button>
						<cdx-button
							v-if="item.status === 'done' && store.LLM_AVAILABLE && !store.polishPendingIds.includes( item.id )"
							weight="quiet"
							action="progressive"
							class="mw-pandoc-queue__ai-btn"
							:title="$i18n( 'pandocultimateconverter-codex-llm-polish-btn' ).text()"
							:aria-label="$i18n( 'pandocultimateconverter-codex-llm-polish-btn' ).text()"
							@click="store.polishItem( item.id )"
						>
							✨
						</cdx-button>
						<cdx-button
							v-if="item.status !== 'uploading' && item.status !== 'converting' && item.status !== 'polishing'"
							weight="quiet"
							action="destructive"
							:disabled="store.isConverting"
							:title="$i18n( 'pandocultimateconverter-codex-remove-item' ).text()"
							:aria-label="$i18n( 'pandocultimateconverter-codex-remove-item' ).text()"
							@click="store.removeItem( item.id )"
						>
							✕
						</cdx-button>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
const { defineComponent } = require( 'vue' );
const { CdxButton, CdxMessage } = require( '@wikimedia/codex' );
const useConverterStore = require( '../stores/converter.js' );
const PageNameInput = require( './PageNameInput.vue' );
const ConversionStatus = require( './ConversionStatus.vue' );

// @vue/component
module.exports = exports = defineComponent( {
	name: 'ConversionQueue',
	components: {
		CdxButton,
		CdxMessage,
		PageNameInput,
		ConversionStatus
	},
	setup() {
		const store = useConverterStore();

		/**
		 * Truncate a string with ellipsis.
		 *
		 * @param {string} str
		 * @param {number} maxLen
		 * @return {string}
		 */
		function truncate( str, maxLen ) {
			if ( str.length <= maxLen ) {
				return str;
			}
			return str.slice( 0, maxLen - 1 ) + '…';
		}

		return {
			store,
			truncate
		};
	}
} );
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.mw-pandoc-queue {
	margin-top: @spacing-100;

	&__summary {
		margin-bottom: @spacing-50;
		font-weight: @font-weight-bold;
	}

	&__overwrite-msg {
		margin-bottom: @spacing-75;
	}

	&__table {
		width: 100%;
		border-collapse: collapse;
		border: @border-width-base @border-style-base @border-color-subtle;
		border-radius: @border-radius-base;

		th,
		td {
			padding: @spacing-50 @spacing-75;
			text-align: left;
			vertical-align: middle;
			border-bottom: @border-width-base @border-style-base @border-color-subtle;
		}

		th {
			background-color: @background-color-interactive-subtle;
			font-weight: @font-weight-bold;
			font-size: @font-size-small;
			color: @color-subtle;
		}
	}

	&__col-source {
		width: 25%;
	}

	&__col-target {
		width: 35%;
	}

	&__col-status {
		width: 30%;
	}

	&__col-actions {
		width: 10%;
		text-align: center;
		white-space: nowrap;
	}

	&__row--done {
		background-color: @background-color-success-subtle;
	}

	&__row--error {
		background-color: @background-color-error-subtle;
	}

	&__source-icon {
		margin-right: @spacing-25;
	}

	&__source-name {
		word-break: break-all;
	}

	&__play-btn {
		color: @color-success;
	}
}
</style>
