<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Api;

use ApiBase;
use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Action API module: action=pandocurltitle
 *
 * Fetches a remote URL and extracts the HTML <title> tag to suggest
 * a wiki page name for PandocUltimateConverter batch conversions.
 */
class ApiPandocUrlTitle extends ApiBase {

	public function execute(): void {
		$params = $this->extractRequestParams();
		$urls = $params['urls'];

		$results = [];
		$httpFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();

		foreach ( $urls as $url ) {
			// Only allow http/https (SSRF guard)
			$scheme = strtolower( (string)parse_url( $url, PHP_URL_SCHEME ) );
			if ( !in_array( $scheme, [ 'http', 'https' ], true ) ) {
				$results[] = [
					'url' => $url,
					'title' => '',
					'error' => 'invalid-scheme',
				];
				continue;
			}

			try {
				$request = $httpFactory->create( $url, [
					'timeout' => 10,
					'followRedirects' => true,
				], __METHOD__ );
				$status = $request->execute();

				if ( !$status->isOK() ) {
					$results[] = [
						'url' => $url,
						'title' => '',
						'error' => 'fetch-failed',
					];
					continue;
				}

				$content = $request->getContent();
				$title = $this->extractTitle( $content );
				$results[] = [
					'url' => $url,
					'title' => $this->sanitizeTitleForWiki( $title ),
				];
			} catch ( \Exception $e ) {
				$results[] = [
					'url' => $url,
					'title' => '',
					'error' => 'fetch-failed',
				];
			}
		}

		$this->getResult()->addValue( null, 'pandocurltitle', [ 'results' => $results ] );
	}

	/**
	 * Extract the <title> tag content from HTML.
	 *
	 * @param string $html
	 * @return string
	 */
	private function extractTitle( string $html ): string {
		// Only look at the first 50KB to avoid parsing huge documents
		$html = substr( $html, 0, 51200 );

		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/si', $html, $matches ) ) {
			$title = html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			return trim( $title );
		}

		return '';
	}

	/**
	 * Clean up an extracted title for use as a wiki page name.
	 *
	 * @param string $title
	 * @return string
	 */
	private function sanitizeTitleForWiki( string $title ): string {
		// Replace characters invalid in MediaWiki titles with dashes
		$title = str_replace( [ '#', '<', '>', '[', ']', '{', '}', '|', '\\', '/' ], '-', $title );
		// Replace underscores with spaces
		$title = str_replace( '_', ' ', $title );
		// Collapse consecutive dashes
		$title = preg_replace( '/-{2,}/', '-', $title );
		// Collapse whitespace
		$title = preg_replace( '/\s+/', ' ', $title );
		// Trim dashes and whitespace from edges
		return trim( $title, " \t\n\r\0\x0B-" );
	}

	/** @inheritDoc */
	public function getAllowedParams(): array {
		return [
			'urls' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_ISMULTI => true,
			],
		];
	}

	/** @inheritDoc */
	public function isInternal(): bool {
		return true;
	}
}
