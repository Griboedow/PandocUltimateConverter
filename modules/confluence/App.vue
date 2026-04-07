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
					:status="fieldStatus( 'targetPrefix' )"
					aria-describedby="mw-confluence-prefix-help"
					class="mw-confluence-migration-app__input"
				></cdx-text-input>
				<p
					id="mw-confluence-prefix-help"
					class="mw-confluence-migration-app__help"
				>
					{{ $i18n( 'confluencemigration-prefix-help' ).text() }}
				</p>
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

			<!-- Auto-categorize checkbox -->
			<div class="mw-confluence-migration-app__field mw-confluence-migration-app__field--checkbox">
				<cdx-checkbox
					v-model="form.categorize"
					:disabled="isSubmitting"
				>
					{{ $i18n( 'confluencemigration-categorize-label' ).text() }}
				</cdx-checkbox>
			</div>

			<!-- LLM polish checkbox (only if LLM is configured) -->
			<div
				v-if="llmAvailable"
				class="mw-confluence-migration-app__field mw-confluence-migration-app__field--checkbox"
			>
				<cdx-checkbox
					v-model="form.llmPolish"
					:disabled="isSubmitting"
				>
					{{ $i18n( 'confluencemigration-llm-polish-label' ).text() }}
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

		<!-- Pending jobs grid -->
		<div
			v-if="jobs.length > 0"
			class="mw-confluence-migration-app__jobs"
		>
			<h3 class="mw-confluence-migration-app__jobs-heading">
				{{ $i18n( 'confluencemigration-jobs-heading' ).text() }}
			</h3>
			<table class="mw-confluence-migration-app__jobs-table wikitable">
				<thead>
					<tr>
						<th>{{ $i18n( 'confluencemigration-jobs-col-id' ).text() }}</th>
						<th>{{ $i18n( 'confluencemigration-jobs-col-space' ).text() }}</th>
						<th>{{ $i18n( 'confluencemigration-jobs-col-url' ).text() }}</th>
						<th>{{ $i18n( 'confluencemigration-jobs-col-prefix' ).text() }}</th>
						<th>{{ $i18n( 'confluencemigration-jobs-col-status' ).text() }}</th>
						<th>{{ $i18n( 'confluencemigration-jobs-col-queued' ).text() }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="job in jobs"
						:key="job.id"
					>
						<td>{{ job.id }}</td>
						<td>
							<strong>{{ job.spaceKey }}</strong>
						</td>
						<td class="mw-confluence-migration-app__jobs-url">
							{{ job.confluenceUrl }}
						</td>
						<td>{{ job.targetPrefix || '—' }}</td>
						<td>
							<span
								class="mw-confluence-migration-app__jobs-status"
								:class="'mw-confluence-migration-app__jobs-status--' + job.status"
							>
								{{ job.status === 'running'
									? $i18n( 'confluencemigration-jobs-status-running' ).text()
									: $i18n( 'confluencemigration-jobs-status-queued' ).text()
								}}
							</span>
						</td>
						<td>{{ formatTime( job.queuedAt ) }}</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Completed migration reports -->
		<div
			v-if="reports.length > 0"
			class="mw-confluence-migration-app__reports"
		>
			<h3 class="mw-confluence-migration-app__reports-heading">
				{{ $i18n( 'confluencemigration-reports-heading' ).text() }}
			</h3>
			<table class="mw-confluence-migration-app__reports-table wikitable">
				<thead>
					<tr>
						<th>{{ $i18n( 'confluencemigration-reports-col-report' ).text() }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="report in reports"
						:key="report.pageId"
					>
						<td>
							<a :href="report.url">{{ report.title }}</a>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
const { defineComponent, ref, computed, onMounted, onUnmounted } = require( 'vue' );
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
			overwrite: false,
			categorize: true,
			llmPolish: false
		} );

		const llmAvailable = !!mw.config.get( 'confluenceMigrationLlmAvailable' );
		/** @type {string[]} */
		const validNamespaces = mw.config.get( 'confluenceMigrationValidNamespaces' ) || [];

		const isSubmitting = ref( false );
		const successMessage = ref( '' );
		const errorMessage = ref( '' );
		/** @type {import('vue').Ref<Set<string>>} */
		const invalidFields = ref( new Set() );

		// --- Jobs & reports tracking ---
		const jobs = ref( mw.config.get( 'confluenceMigrationJobs' ) || [] );
		const reports = ref( mw.config.get( 'confluenceMigrationReports' ) || [] );
		let pollTimer = null;

		function loadJobs() {
			const api = new mw.Api();
			api.get( { action: 'pandocconfluencejobs', format: 'json' } )
				.then( ( data ) => {
					if ( data && data.pandocconfluencejobs ) {
						if ( data.pandocconfluencejobs.jobs ) {
							jobs.value = data.pandocconfluencejobs.jobs;
						}
						if ( data.pandocconfluencejobs.reports ) {
							reports.value = data.pandocconfluencejobs.reports;
						}
					}
				} );
		}

		function startPolling() {
			if ( pollTimer ) {
				return;
			}
			pollTimer = setInterval( loadJobs, 10000 );
		}

		function stopPolling() {
			if ( pollTimer ) {
				clearInterval( pollTimer );
				pollTimer = null;
			}
		}

		function formatTime( iso ) {
			if ( !iso ) {
				return '—';
			}
			try {
				const d = new Date( iso );
				return d.toLocaleString();
			} catch ( e ) {
				return iso;
			}
		}

		onMounted( () => {
			// Start polling if there are already pending jobs.
			if ( jobs.value.length > 0 ) {
				startPolling();
			}
			// Always load reports at least once
			if ( reports.value.length === 0 ) {
				loadJobs();
			}
		} );

		onUnmounted( () => {
			stopPolling();
		} );

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

			// Validate namespace prefix if a colon is present.
			const prefix = form.value.targetPrefix.trim();
			const colonIdx = prefix.indexOf( ':' );
			if ( colonIdx !== -1 ) {
				const nsName = prefix.slice( 0, colonIdx );
				if ( validNamespaces.length > 0 && !validNamespaces.includes( nsName ) ) {
					bad.add( 'targetPrefix' );
				}
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
				} else if ( invalidFields.value.has( 'targetPrefix' ) ) {
					errorMessage.value = mw.msg( 'confluencemigration-error-invalid-prefix' );
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
				overwrite: form.value.overwrite ? '1' : '',
				categorize: form.value.categorize ? '1' : '',
				llmpolish: form.value.llmPolish ? '1' : ''
			} ).then( () => {
				successMessage.value = mw.msg(
					'confluencemigration-queued',
					form.value.spaceKey.trim()
				);
				// Clear the sensitive token field after a successful submission.
				form.value.apiToken = '';
				invalidFields.value = new Set();
				// Refresh the jobs grid after a short delay so the DB has
				// committed the new job row, then start polling.
				setTimeout( () => {
					loadJobs();
					startPolling();
				}, 1500 );
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
			handleSubmit,
			jobs,
			reports,
			formatTime,
			llmAvailable,
			validNamespaces
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

	&__jobs {
		margin-top: @spacing-200;
	}

	&__jobs-heading {
		margin-bottom: @spacing-75;
	}

	&__jobs-table {
		width: 100%;
		font-size: @font-size-small;
	}

	&__jobs-url {
		max-width: 220px;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__jobs-status {
		display: inline-block;
		padding: 2px 8px;
		border-radius: 3px;
		font-size: @font-size-x-small;
		font-weight: @font-weight-bold;
		text-transform: uppercase;

		&--queued {
			background-color: @background-color-progressive-subtle;
			color: @color-progressive;
		}

		&--running {
			background-color: @background-color-success-subtle;
			color: @color-success;
		}
	}

	&__reports {
		margin-top: @spacing-200;
	}

	&__reports-heading {
		margin-bottom: @spacing-75;
	}

	&__reports-table {
		width: 100%;
		font-size: @font-size-small;
	}
}
</style>
