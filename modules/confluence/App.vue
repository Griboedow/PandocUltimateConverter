<template>
	<div class="mw-confluence-migration-app">
		<!-- Success banner -->
		<cdx-message
			v-if="successMessage"
			type="success"
			:allow-user-dismiss="true"
			class="mw-confluence-migration-app__message"
			@user-dismiss="successMessage = ''"
		>
			{{ successMessage }}
		</cdx-message>

		<!-- Error banner -->
		<cdx-message
			v-if="errorMessage"
			type="error"
			:allow-user-dismiss="true"
			class="mw-confluence-migration-app__message"
			@user-dismiss="errorMessage = ''"
		>
			{{ errorMessage }}
		</cdx-message>

		<!-- Description -->
		<p class="mw-confluence-migration-app__description">
			{{ $i18n( 'confluencemigration-desc' ).text() }}
		</p>

		<!-- Form -->
		<div class="mw-confluence-migration-app__form">
			<!-- Confluence URL -->
			<div class="mw-confluence-migration-app__field">
				<label
					for="mw-confluence-url"
					class="mw-confluence-migration-app__label"
				>
					{{ $i18n( 'confluencemigration-url-label' ).text() }}
				</label>
				<cdx-text-input
					id="mw-confluence-url"
					v-model="form.confluenceUrl"
					input-type="url"
					:placeholder="'https://example.atlassian.net'"
					:disabled="isSubmitting"
					:status="fieldStatus( 'confluenceUrl' )"
					class="mw-confluence-migration-app__input"
				></cdx-text-input>
				<p class="mw-confluence-migration-app__help">
					{{ $i18n( 'confluencemigration-url-help' ).text() }}
				</p>
			</div>

			<!-- Space key -->
			<div class="mw-confluence-migration-app__field">
				<label
					for="mw-confluence-spacekey"
					class="mw-confluence-migration-app__label"
				>
					{{ $i18n( 'confluencemigration-spacekey-label' ).text() }}
				</label>
				<cdx-text-input
					id="mw-confluence-spacekey"
					v-model="form.spaceKey"
					input-type="text"
					:placeholder="'DOCS'"
					:disabled="isSubmitting"
					:status="fieldStatus( 'spaceKey' )"
					class="mw-confluence-migration-app__input mw-confluence-migration-app__input--short"
				></cdx-text-input>
			</div>

			<!-- Email / Username -->
			<div class="mw-confluence-migration-app__field">
				<label
					for="mw-confluence-user"
					class="mw-confluence-migration-app__label"
				>
					{{ $i18n( 'confluencemigration-user-label' ).text() }}
				</label>
				<cdx-text-input
					id="mw-confluence-user"
					v-model="form.apiUser"
					input-type="text"
					autocomplete="username"
					:disabled="isSubmitting"
					:status="fieldStatus( 'apiUser' )"
					class="mw-confluence-migration-app__input"
				></cdx-text-input>
			</div>

			<!-- API token / password -->
			<div class="mw-confluence-migration-app__field">
				<label
					for="mw-confluence-token"
					class="mw-confluence-migration-app__label"
				>
					{{ $i18n( 'confluencemigration-token-label' ).text() }}
				</label>
				<cdx-text-input
					id="mw-confluence-token"
					v-model="form.apiToken"
					input-type="password"
					autocomplete="current-password"
					:disabled="isSubmitting"
					:status="fieldStatus( 'apiToken' )"
					class="mw-confluence-migration-app__input"
				></cdx-text-input>
			</div>

			<!-- Target page prefix (optional) -->
			<div class="mw-confluence-migration-app__field">
				<label
					for="mw-confluence-prefix"
					class="mw-confluence-migration-app__label"
				>
					{{ $i18n( 'confluencemigration-prefix-label' ).text() }}
				</label>
				<cdx-text-input
					id="mw-confluence-prefix"
					v-model="form.targetPrefix"
					input-type="text"
					:disabled="isSubmitting"
					class="mw-confluence-migration-app__input"
				></cdx-text-input>
			</div>

			<!-- Overwrite checkbox -->
			<div class="mw-confluence-migration-app__field mw-confluence-migration-app__field--checkbox">
				<cdx-checkbox
					v-model="form.overwrite"
					:disabled="isSubmitting"
				>
					{{ $i18n( 'confluencemigration-overwrite-label' ).text() }}
				</cdx-checkbox>
			</div>

			<!-- Submit button -->
			<div class="mw-confluence-migration-app__actions">
				<cdx-button
					weight="primary"
					action="progressive"
					:disabled="!canSubmit"
					@click="handleSubmit"
				>
					<template v-if="isSubmitting">
						{{ $i18n( 'confluencemigration-submitting' ).text() }}
					</template>
					<template v-else>
						{{ $i18n( 'confluencemigration-submit' ).text() }}
					</template>
				</cdx-button>
			</div>
		</div>
	</div>
</template>

<script>
const { defineComponent, ref, computed } = require( 'vue' );
const { CdxButton, CdxCheckbox, CdxMessage, CdxTextInput } = require( '@wikimedia/codex' );

// @vue/component
module.exports = exports = defineComponent( {
	name: 'ConfluenceMigrationApp',
	components: {
		CdxButton,
		CdxCheckbox,
		CdxMessage,
		CdxTextInput
	},
	setup() {
		const form = ref( {
			confluenceUrl: '',
			spaceKey: '',
			apiUser: '',
			apiToken: '',
			targetPrefix: '',
			overwrite: false
		} );

		const isSubmitting = ref( false );
		const successMessage = ref( '' );
		const errorMessage = ref( '' );
		/** @type {import('vue').Ref<Set<string>>} */
		const invalidFields = ref( new Set() );

		/**
		 * Return 'error' if the field has a validation error, 'default' otherwise.
		 *
		 * @param {string} field
		 * @return {string}
		 */
		function fieldStatus( field ) {
			return invalidFields.value.has( field ) ? 'error' : 'default';
		}

		const canSubmit = computed( () => !isSubmitting.value );

		/**
		 * Client-side validation. Returns true when all required fields pass.
		 * Populates invalidFields with the names of any failing fields.
		 *
		 * @return {boolean}
		 */
		function validate() {
			const bad = new Set();

			const url = form.value.confluenceUrl.trim();
			if ( !url || !/^https:\/\/.+/i.test( url ) ) {
				bad.add( 'confluenceUrl' );
			}

			if ( !form.value.spaceKey.trim() ) {
				bad.add( 'spaceKey' );
			}

			if ( !form.value.apiUser.trim() ) {
				bad.add( 'apiUser' );
			}

			if ( !form.value.apiToken ) {
				bad.add( 'apiToken' );
			}

			invalidFields.value = bad;
			return bad.size === 0;
		}

		function handleSubmit() {
			successMessage.value = '';
			errorMessage.value = '';

			if ( !validate() ) {
				// Surface a generic error message listing which fields are invalid.
				if ( invalidFields.value.has( 'confluenceUrl' ) ) {
					errorMessage.value = mw.msg( 'confluencemigration-error-invalid-url' );
				} else if ( invalidFields.value.has( 'spaceKey' ) ) {
					errorMessage.value = mw.msg( 'confluencemigration-error-empty-spacekey' );
				} else {
					errorMessage.value = mw.msg( 'confluencemigration-error-empty-credentials' );
				}
				return;
			}

			isSubmitting.value = true;

			const api = new mw.Api();
			api.postWithToken( 'csrf', {
				action: 'pandocconfluencemigrate',
				confluenceurl: form.value.confluenceUrl.trim(),
				spacekey: form.value.spaceKey.trim(),
				apiuser: form.value.apiUser.trim(),
				apitoken: form.value.apiToken,
				targetprefix: form.value.targetPrefix.trim(),
				overwrite: form.value.overwrite ? '1' : ''
			} ).then( () => {
				successMessage.value = mw.msg(
					'confluencemigration-queued',
					form.value.spaceKey.trim()
				);
				// Clear the sensitive token field after a successful submission.
				form.value.apiToken = '';
				invalidFields.value = new Set();
			} ).catch( ( code, data ) => {
				const apiError = data && data.error && data.error.info
					? data.error.info
					: String( code );
				errorMessage.value = apiError;
			} ).always( () => {
				isSubmitting.value = false;
			} );
		}

		return {
			form,
			isSubmitting,
			successMessage,
			errorMessage,
			canSubmit,
			fieldStatus,
			handleSubmit
		};
	}
} );
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';

.mw-confluence-migration-app {
	max-width: 800px;

	&__message {
		margin-bottom: @spacing-75;
	}

	&__description {
		color: @color-subtle;
		margin-bottom: @spacing-150;
	}

	&__form {
		display: flex;
		flex-direction: column;
		gap: @spacing-100;
	}

	&__field {
		display: flex;
		flex-direction: column;
		gap: @spacing-25;

		&--checkbox {
			flex-direction: row;
			align-items: center;
		}
	}

	&__label {
		font-weight: @font-weight-bold;
	}

	&__input {
		max-width: 500px;

		&--short {
			max-width: 200px;
		}
	}

	&__help {
		color: @color-subtle;
		font-size: @font-size-small;
		margin: 0;
	}

	&__actions {
		padding-top: @spacing-100;
		border-top: @border-width-base @border-style-base @border-color-subtle;
	}
}
</style>
