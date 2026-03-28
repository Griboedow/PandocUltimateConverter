<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Api;

use ApiBase;
use MediaWiki\Extension\PandocUltimateConverter\LlmPolishService;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Action API module: action=pandocllmpolish
 *
 * Runs LLM-based cleanup on the wikitext of an existing page.
 */
class ApiPandocLlmPolish extends ApiBase {

	public function execute(): void {
		// LLM calls can take minutes for large pages — extend PHP time limit
		set_time_limit( 600 );

		$params   = $this->extractRequestParams();
		$pageName = $params['pagename'];

		$mwServices = MediaWikiServices::getInstance();
		$mwConfig   = $mwServices->getConfigFactory()->makeConfig( 'PandocUltimateConverter' );

		// Enforce the custom permission right if one is configured
		$userRight = $mwConfig->get( 'PandocUltimateConverter_PandocCustomUserRight' ) ?? '';
		if ( $userRight !== '' ) {
			$this->checkUserRightsAny( $userRight );
		}

		// Validate target page title
		$title = Title::newFromText( $pageName );
		if ( $title === null || !$title->exists() ) {
			$this->dieWithError( [ 'apierror-pandocllmpolish-pagenotfound', wfEscapeWikiText( $pageName ) ] );
		}

		// Build the LLM service
		$llmService = LlmPolishService::newFromConfig( $mwConfig );
		if ( $llmService === null ) {
			$this->dieWithError( 'apierror-pandocllmpolish-notconfigured' );
		}

		// Read current page content (validate before deferring)
		$wikiPage = $mwServices->getWikiPageFactory()->newFromTitle( $title );
		$content  = $wikiPage->getContent();
		if ( !$content || !( $content instanceof \WikitextContent ) ) {
			$this->dieWithError( 'apierror-pandocllmpolish-notwikitext' );
		}

		$user = $this->getUser();

		$originalText = $content->getText();
		$polishedText = $llmService->polish( $originalText );

		if ( trim( $polishedText ) === '' ) {
			$this->dieWithError( 'apierror-pandocllmpolish-failed' );
		}

		$pageUpdater = $wikiPage->newPageUpdater( $user );
		$newContent  = new \WikitextContent( $polishedText );
		$pageUpdater->setContent( SlotRecord::MAIN, $newContent );
		$pageUpdater->saveRevision(
			\CommentStoreComment::newUnsavedComment(
				wfMessage( 'pandocultimateconverter-llmpolish-comment' )->text()
			),
			EDIT_INTERNAL
		);

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result'   => 'success',
			'pagename' => $pageName,
		] );
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		return [
			'pagename' => [
				ParamValidator::PARAM_TYPE     => 'string',
				ParamValidator::PARAM_REQUIRED => true,
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
}
