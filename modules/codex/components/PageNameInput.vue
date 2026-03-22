<template>
	<div class="mw-pandoc-page-name">
		<cdx-text-input
			:model-value="item.targetPageName"
			:disabled="disabled || item.status === 'done'"
			:status="inputStatus"
			@update:model-value="onInput"
		></cdx-text-input>
		<cdx-message
			v-if="validationError"
			type="error"
			inline
			class="mw-pandoc-page-name__msg"
		>
			{{ validationErrorText }}
		</cdx-message>
		<cdx-message
			v-else-if="item.pageExists === true"
			type="warning"
			inline
			class="mw-pandoc-page-name__msg"
		>
			{{ $i18n( 'pandocultimateconverter-codex-page-exists-warning' ).text() }}
		</cdx-message>
	</div>
</template>

<script>
const { defineComponent, computed } = require( 'vue' );
const { CdxMessage, CdxTextInput } = require( '@wikimedia/codex' );
const useConverterStore = require( '../stores/converter.js' );

// @vue/component
module.exports = exports = defineComponent( {
	name: 'PageNameInput',
	components: {
		CdxMessage,
		CdxTextInput
	},
	props: {
		item: {
			type: Object,
			required: true
		},
		disabled: {
			type: Boolean,
			default: false
		}
	},
	setup( props ) {
		const store = useConverterStore();

		const validationError = computed( () =>
			store.validatePageName( props.item.targetPageName )
		);

		const validationErrorText = computed( () => {
			const key = validationError.value;
			if ( !key ) {
				return '';
			}
			if ( key === 'pandocultimateconverter-warning-page-name-length' ) {
				return mw.msg( key, store.TITLE_MIN, store.TITLE_MAX );
			}
			if ( key === 'pandocultimateconverter-warning-page-name-invalid-character' ) {
				const invalid = props.item.targetPageName.match( /[/'"$]/g );
				return mw.msg( key, invalid ? invalid.join( ' ' ) : '' );
			}
			return mw.msg( key );
		} );

		const inputStatus = computed( () => {
			if ( validationError.value ) {
				return 'error';
			}
			if ( props.item.pageExists === true ) {
				return 'warning';
			}
			return 'default';
		} );

		function onInput( value ) {
			store.updatePageName( props.item.id, value );
		}

		return {
			validationError,
			validationErrorText,
			inputStatus,
			onInput
		};
	}
} );
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.mw-pandoc-page-name {
	&__msg {
		margin-top: @spacing-25;
	}
}
</style>
