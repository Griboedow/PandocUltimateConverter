<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter;

use ApiBase;
use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Action API module: action=pandocconvert
 *
 * Converts an already-uploaded MediaWiki file or a remote URL to a wiki page
 * using Pandoc.
 *
 * Workflow (file):
 *   1. Upload file via the standard MediaWiki API (action=upload).
 *   2. Call action=pandocconvert with pagename= and filename=.
 *   3. Optionally delete the file via the standard MediaWiki API (action=delete).
 *
 * Workflow (URL):
 *   1. Call action=pandocconvert with pagename= and url=.
 */
class ApiPandocConvert extends ApiBase {

	public function execute(): void {
		$params  = $this->extractRequestParams();
		$pageName = $params['pagename'];
		$fileName = $params['filename'] ?? null;
		$sourceUrl = $params['url'] ?? null;
		$forceOverwrite = $params['forceoverwrite'];

		// Exactly one source must be supplied
		if ( $fileName === null && $sourceUrl === null ) {
			$this->dieWithError( 'apierror-pandocultimateconverter-nosource' );
		}
		if ( $fileName !== null && $sourceUrl !== null ) {
			$this->dieWithError( 'apierror-pandocultimateconverter-multiplesource' );
		}

		// Basic URL scheme validation – only http/https are allowed (SSRF guard)
		if ( $sourceUrl !== null ) {
			$scheme = strtolower( (string)parse_url( $sourceUrl, PHP_URL_SCHEME ) );
			if ( !in_array( $scheme, [ 'http', 'https' ], true ) ) {
				$this->dieWithError( 'apierror-pandocultimateconverter-invalidurlscheme' );
			}
		}

		// Enforce the custom permission right if one is configured
		$mwConfig  = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'PandocUltimateConverter' );
		$userRight = $mwConfig->get( 'PandocUltimateConverter_PandocCustomUserRight' ) ?? '';
		if ( $userRight !== '' ) {
			$this->checkUserRightsAny( $userRight );
		}

		// Validate target page title
		$title = \Title::newFromText( $pageName );
		if ( $title === null ) {
			$this->dieWithError( [ 'apierror-invalidtitle', wfEscapeWikiText( $pageName ) ] );
		}

		// Respect forceoverwrite flag
		if ( !$forceOverwrite && $title->exists() ) {
			$this->dieWithError( 'apierror-pandocultimateconverter-pageexists' );
		}

		$user    = $this->getUser();
		$service = new PandocConverterService( $mwConfig, MediaWikiServices::getInstance(), $user );

		try {
			if ( $fileName !== null ) {
				$service->convertFileToPage( $fileName, $pageName );
			} else {
				$service->convertUrlToPage( $sourceUrl, $pageName );
			}
		} catch ( \RuntimeException $e ) {
			$this->dieWithError( [ 'apierror-pandocultimateconverter-conversionfailed', $e->getMessage() ] );
		} catch ( \Exception $e ) {
			$this->dieWithError( [ 'apierror-pandocultimateconverter-conversionfailed', $e->getMessage() ] );
		}

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
			'filename' => [
				ParamValidator::PARAM_TYPE     => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			],
			'url' => [
				ParamValidator::PARAM_TYPE     => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			],
			'forceoverwrite' => [
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
	protected function getExamplesMessages(): array {
		return [
			'action=pandocconvert&pagename=MyArticle&filename=Document.docx&forceoverwrite=1'
				=> 'apihelp-pandocconvert-example-file',
			'action=pandocconvert&pagename=MyArticle&url=https://example.com'
				=> 'apihelp-pandocconvert-example-url',
		];
	}

	/** @inheritDoc */
	public function getHelpUrls(): array {
		return [ 'https://www.mediawiki.org/wiki/Extension:PandocUltimateConverter' ];
	}
}
