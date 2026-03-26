<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\SpecialPages;

use MediaWiki\Config\Config;
use MediaWiki\Extension\PandocUltimateConverter\Api\ApiConfluenceJobs;
use MediaWiki\Extension\PandocUltimateConverter\LlmPolishService;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;

/**
 * Special page that lets administrators migrate an entire Confluence space into
 * this MediaWiki installation.
 *
 * Access via: Special:ConfluenceMigration
 *
 * The page mounts a Vue.js / Codex UI component that presents a form for:
 *  - The Confluence base URL (cloud or server)
 *  - The space key (e.g. "DOCS")
 *  - Authentication credentials (email/username + API token/password)
 *  - An optional target page prefix
 *  - A flag controlling whether existing pages may be overwritten
 *
 * On submission the Vue component calls the action=pandocconfluencemigrate API
 * which enqueues a {@see \MediaWiki\Extension\PandocUltimateConverter\Jobs\ConfluenceMigrationJob}.
 * The actual migration runs asynchronously via the MediaWiki job queue; the user
 * receives an Echo notification when it completes (requires the Echo extension).
 *
 * The feature can be disabled entirely by setting
 * <code>$wgPandocUltimateConverter_EnableConfluenceMigration = false;</code>
 * in LocalSettings.php.
 */
class SpecialConfluenceMigration extends \SpecialPage {

	private Config $config;

	public function __construct() {
		$mwConfig  = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'PandocUltimateConverter' );
		$userRight = $mwConfig->get( 'PandocUltimateConverter_PandocCustomUserRight' ) ?? '';

		parent::__construct( 'ConfluenceMigration', $userRight );

		$this->config = $mwConfig;
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'media';
	}

	/** @inheritDoc */
	public function execute( $par ): void {
		$this->setHeaders();
		$this->checkPermissions();

		$output = $this->getOutput();

		// Feature-flag check — administrators can disable this page entirely.
		if ( !$this->config->get( 'PandocUltimateConverter_EnableConfluenceMigration' ) ) {
			$output->addWikiTextAsInterface(
				wfMessage( 'confluencemigration-disabled' )->text()
			);
			return;
		}

		// Load the Vue/Codex module and mount the app.
		$output->addModules( 'ext.PandocUltimateConverter.confluence' );

		// Pre-load pending jobs so the grid renders immediately.
		$output->addJsConfigVars( 'confluenceMigrationJobs', ApiConfluenceJobs::fetchPendingJobs() );
		$output->addJsConfigVars( 'confluenceMigrationLlmAvailable',
			LlmPolishService::newFromConfig( $this->config ) !== null
		);

		$output->addHTML( Html::element( 'div', [ 'class' => 'mw-confluence-migration-root' ] ) );
	}
}

