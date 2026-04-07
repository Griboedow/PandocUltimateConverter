<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Api;

use ApiBase;
use MediaWiki\Extension\PandocUltimateConverter\ConfluenceClient;
use MediaWiki\Extension\PandocUltimateConverter\Jobs\ConfluenceMigrationJob;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Action API module: action=pandocconfluencemigrate
 *
 * Enqueues a ConfluenceMigrationJob that imports all pages from a Confluence
 * space into this MediaWiki installation.
 *
 * This module is called from the Vue/Codex UI on Special:ConfluenceMigration.
 *
 * Required parameters:
 *   confluenceurl  – HTTPS base URL of the Confluence instance.
 *   spacekey       – Confluence space key (e.g. "DOCS").
 *   apiuser        – Email (Cloud) or username (Server).
 *   apitoken       – API token (Cloud) or password / personal access token (Server).
 *
 * Optional parameters:
 *   targetprefix   – Prefix prepended to every wiki page title.
 *   overwrite      – When true, overwrite existing wiki pages (default: false).
 */
class ApiConfluenceMigrate extends ApiBase {

	/** @inheritDoc */
	public function execute(): void {
		$params = $this->extractRequestParams();

		$confluenceUrl = trim( $params['confluenceurl'] );
		$spaceKey      = trim( $params['spacekey'] );
		$apiUser       = trim( $params['apiuser'] );
		$apiToken      = $params['apitoken'];
		$targetPrefix  = trim( $params['targetprefix'] ?? '' );
		$overwrite     = (bool)$params['overwrite'];
		$categorize    = (bool)$params['categorize'];
		$llmPolish     = (bool)$params['llmpolish'];

		// Validate that the feature is enabled
		$mwConfig = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'PandocUltimateConverter' );

		if ( !$mwConfig->get( 'PandocUltimateConverter_EnableConfluenceMigration' ) ) {
			$this->dieWithError( 'apierror-pandocconfluencemigrate-disabled' );
		}

		// Enforce the custom permission right if one is configured
		$userRight = $mwConfig->get( 'PandocUltimateConverter_PandocCustomUserRight' ) ?? '';
		if ( $userRight !== '' ) {
			$this->checkUserRightsAny( $userRight );
		}

		// Validate URL scheme (SSRF guard — only https is allowed)
		$scheme = strtolower( (string)parse_url( $confluenceUrl, PHP_URL_SCHEME ) );
		$host   = (string)parse_url( $confluenceUrl, PHP_URL_HOST );
		if ( $scheme !== 'https' || $host === '' ) {
			$this->dieWithError( 'apierror-pandocconfluencemigrate-invalidurl' );
		}

		// Pre-flight: verify credentials and space access before enqueuing
		$client = new ConfluenceClient( $confluenceUrl, $apiUser, $apiToken );
		try {
			$client->validateAccess( $spaceKey );
		} catch ( \RuntimeException $e ) {
			$this->dieWithError(
				[ 'apierror-pandocconfluencemigrate-authfailed', $e->getMessage() ]
			);
		}

		// Validate namespace in prefix if one is specified.
		// Accepted formats: "NS:", "NS:subprefix", or a plain "subprefix" (no colon).
		$colonPos = strpos( $targetPrefix, ':' );
		if ( $colonPos !== false ) {
			$nsName = substr( $targetPrefix, 0, $colonPos );

			$services     = MediaWikiServices::getInstance();
			$contentLang  = $services->getContentLanguage();
			$nsInfo       = $services->getNamespaceInfo();

			// Resolve the namespace name – getNsIndex() handles both canonical
			// and localised names (e.g. "Категория" for Category in Russian).
			$nsIndex = $contentLang->getNsIndex( $nsName );

			if ( $nsIndex === false ) {
				$this->dieWithError(
					[ 'apierror-pandocconfluencemigrate-invalidns', $nsName ]
				);
			}

			// Block built-in / service namespaces (IDs 0 – 15) and any talk
			// namespace (odd IDs), which have special meaning in MediaWiki and
			// are not suitable as Confluence import targets.
			if ( $nsIndex < 100 || $nsInfo->isTalk( $nsIndex ) ) {
				$this->dieWithError( 'apierror-pandocconfluencemigrate-servicens' );
			}
		}

		// Enqueue the migration job
		$jobTitle = Title::newMainPage();
		$user     = $this->getUser();

		$job = new ConfluenceMigrationJob( $jobTitle, [
			'confluenceUrl' => $confluenceUrl,
			'spaceKey'      => $spaceKey,
			'apiUser'       => $apiUser,
			'apiToken'      => $apiToken,
			'targetPrefix'  => $targetPrefix,
			'overwrite'     => $overwrite,
			'categorize'    => $categorize,
			'llmPolish'     => $llmPolish,
			'userId'        => $user->getId(),
		] );

		MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result'   => 'queued',
			'spaceKey' => $spaceKey,
		] );
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		return [
			'confluenceurl' => [
				ParamValidator::PARAM_TYPE     => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'spacekey' => [
				ParamValidator::PARAM_TYPE     => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'apiuser' => [
				ParamValidator::PARAM_TYPE     => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'apitoken' => [
				ParamValidator::PARAM_TYPE     => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'targetprefix' => [
				ParamValidator::PARAM_TYPE     => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT  => '',
			],
			'overwrite' => [
				ParamValidator::PARAM_TYPE    => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
			],
			'categorize' => [
				ParamValidator::PARAM_TYPE    => 'boolean',
				ParamValidator::PARAM_DEFAULT => true,
			],
			'llmpolish' => [
				ParamValidator::PARAM_TYPE    => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
			],
		];
	}

	/** @inheritDoc */
	public function needsToken(): string {
		return 'csrf';
	}

	/** @inheritDoc */
	public function isWriteMode(): bool {
		return true;
	}

	/** @inheritDoc */
	public function mustBePosted(): bool {
		return true;
	}

	/** @inheritDoc */
	public function getHelpUrls(): array {
		return [ 'https://www.mediawiki.org/wiki/Extension:PandocUltimateConverter' ];
	}
}
