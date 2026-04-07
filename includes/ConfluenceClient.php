<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter;

/**
 * HTTP client for the Confluence REST API (v1).
 *
 * Supports both Confluence Cloud (https://*.atlassian.net) and
 * Confluence Server / Data Center (any other HTTPS host).
 *
 * Authentication:
 *  - Cloud:  HTTP Basic Auth — email address + API token.
 *  - Server: HTTP Basic Auth — username + password or personal access token.
 *
 * The same REST API endpoints work on both variants; only the base path and
 * auth details differ.
 */
class ConfluenceClient {

	/** Number of pages fetched per API request. */
	private const PAGINATION_LIMIT = 50;

	/** Connection / read timeout in seconds for regular API calls. */
	private const TIMEOUT = 30;

	/** Connection / read timeout in seconds for file downloads. */
	private const DOWNLOAD_TIMEOUT = 120;

	private string $baseUrl;
	private string $apiUser;
	private string $apiToken;
	private bool $isCloud;

	/**
	 * @param string $baseUrl   Confluence base URL, e.g. "https://example.atlassian.net"
	 *                          or "https://confluence.example.com".
	 * @param string $apiUser   Email (Cloud) or username (Server).
	 * @param string $apiToken  API token (Cloud) or password / personal access token (Server).
	 */
	public function __construct( string $baseUrl, string $apiUser, string $apiToken ) {
		$this->baseUrl  = rtrim( $baseUrl, '/' );
		$this->apiUser  = $apiUser;
		$this->apiToken = $apiToken;
		$this->isCloud  = $this->detectCloud( $baseUrl );
	}

	// -----------------------------------------------------------------------
	// Private helpers
	// -----------------------------------------------------------------------

	/**
	 * Detect whether the given URL points to a Confluence Cloud instance.
	 *
	 * Cloud instances are hosted under *.atlassian.net.
	 */
	private function detectCloud( string $url ): bool {
		$host = strtolower( (string)parse_url( $url, PHP_URL_HOST ) );
		return str_ends_with( $host, '.atlassian.net' );
	}

	/**
	 * Return the REST API v1 base URL.
	 *
	 * Cloud adds the "/wiki" prefix before "/rest/api".
	 */
	private function apiBase(): string {
		return $this->isCloud
			? $this->baseUrl . '/wiki/rest/api'
			: $this->baseUrl . '/rest/api';
	}

	/**
	 * Return the value of the Authorization header for Basic auth.
	 */
	private function authHeader(): string {
		return 'Basic ' . base64_encode( $this->apiUser . ':' . $this->apiToken );
	}

	/**
	 * Build an HTTP stream context for use with file_get_contents().
	 *
	 * @param int $timeout Timeout in seconds.
	 */
	private function makeContext( int $timeout ): mixed {
		return stream_context_create( [
			'http' => [
				'method'         => 'GET',
				'header'         => implode( "\r\n", [
					'Authorization: ' . $this->authHeader(),
					'Accept: application/json',
					'X-Atlassian-Token: no-check',
				] ),
				'ignore_errors'  => true,
				'timeout'        => $timeout,
			],
			'ssl'  => [
				'verify_peer'      => true,
				'verify_peer_name' => true,
			],
		] );
	}

	/**
	 * Make an HTTP GET request and return the decoded JSON body.
	 *
	 * @param string               $endpoint Relative path, e.g. "/content".
	 * @param array<string,string> $params   Query-string parameters.
	 * @return array<string,mixed> Decoded JSON object.
	 * @throws \RuntimeException On connection error, HTTP error, or invalid JSON.
	 */
	private function get( string $endpoint, array $params = [] ): array {
		$url = $this->apiBase() . $endpoint;
		if ( $params !== [] ) {
			$url .= '?' . http_build_query( $params );
		}

		$context = $this->makeContext( self::TIMEOUT );

		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.file_get_contents
		$body = file_get_contents( $url, false, $context );

		if ( $body === false ) {
			throw new \RuntimeException( "Failed to connect to Confluence API: $url" );
		}

		// Inspect the HTTP status line that PHP stores in this pseudo-global.
		// phpcs:ignore MediaWiki.NamingConventions.ValidGlobalName.wgPrefix
		$statusCode = $this->parseStatusCode( $http_response_header ?? [] );
		if ( $statusCode === 401 || $statusCode === 403 ) {
			throw new \RuntimeException(
				"Confluence API authentication failed (HTTP $statusCode). " .
				"Check your email/username and API token/password."
			);
		}
		if ( $statusCode >= 400 ) {
			throw new \RuntimeException( "Confluence API error (HTTP $statusCode): " . substr( $body, 0, 300 ) );
		}

		$data = json_decode( $body, true );
		if ( !is_array( $data ) ) {
			throw new \RuntimeException(
				'Confluence API returned invalid JSON: ' . substr( $body, 0, 200 )
			);
		}

		return $data;
	}

	/**
	 * Extract the numeric HTTP status code from the response header array.
	 *
	 * @param string[] $headers The $http_response_header pseudo-array.
	 */
	private function parseStatusCode( array $headers ): int {
		foreach ( $headers as $header ) {
			if ( preg_match( '/HTTP\/\S+\s+(\d{3})/', $header, $m ) ) {
				return (int)$m[1];
			}
		}
		return 0;
	}

	// -----------------------------------------------------------------------
	// Public API
	// -----------------------------------------------------------------------

	/**
	 * Fetch the IDs and titles of all pages in a Confluence space.
	 *
	 * Handles pagination automatically and returns a flat list.
	 * Each page includes its immediate parent ID (null for top-level pages).
	 *
	 * @param string $spaceKey The Confluence space key (e.g. "DOCS").
	 * @return list<array{id: string, title: string, parentId: string|null}> Pages with id, title, and parentId.
	 * @throws \RuntimeException On API error.
	 */
	public function fetchAllPages( string $spaceKey ): array {
		$pages = [];
		$start = 0;

		do {
			$data = $this->get( '/content', [
				'spaceKey' => $spaceKey,
				'type'     => 'page',
				'start'    => (string)$start,
				'limit'    => (string)self::PAGINATION_LIMIT,
				'expand'   => 'ancestors',
			] );

			$results = $data['results'] ?? [];
			foreach ( $results as $result ) {
				// The ancestors array is ordered from the root to the immediate parent.
				// The last element is the direct parent of this page.
				$ancestors = $result['ancestors'] ?? [];
				$parentId  = $ancestors !== []
					? (string)$ancestors[ count( $ancestors ) - 1 ]['id']
					: null;

				$pages[] = [
					'id'       => (string)$result['id'],
					'title'    => (string)$result['title'],
					'parentId' => $parentId,
				];
			}

			$fetched = count( $results );
			$start  += $fetched;
			$total   = (int)( $data['totalSize'] ?? 0 );
		} while ( $fetched === self::PAGINATION_LIMIT && $start < $total );

		return $pages;
	}

	/**
	 * Fetch the storage-format HTML body of a single Confluence page.
	 *
	 * The "storage format" is Confluence's XHTML-based content representation;
	 * it is the closest input Pandoc can work with for HTML conversion.
	 *
	 * @param string $pageId The Confluence page ID (numeric string).
	 * @return string HTML content in Confluence storage format.
	 * @throws \RuntimeException On API error.
	 */
	public function fetchPageBody( string $pageId ): string {
		$data = $this->get( "/content/$pageId", [
			'expand' => 'body.storage',
		] );

		return (string)( $data['body']['storage']['value'] ?? '' );
	}

	/**
	 * Fetch the list of file attachments for a given Confluence page.
	 *
	 * Returns an empty array on error rather than throwing, so a failed
	 * attachment fetch does not abort the entire migration.
	 *
	 * @param string $pageId The Confluence page ID.
	 * @return list<array{id: string, title: string, downloadUrl: string, mediaType: string}>
	 */
	public function fetchAttachments( string $pageId ): array {
		try {
			$data = $this->get( "/content/$pageId/child/attachment", [
				'limit' => '50',
			] );
		} catch ( \RuntimeException $e ) {
			wfDebugLog( 'PandocUltimateConverter', 'Confluence: failed to fetch attachments for ' . $pageId . ': ' . $e->getMessage() );
			return [];
		}

		$attachments = [];
		foreach ( $data['results'] ?? [] as $result ) {
			$downloadPath = $result['_links']['download'] ?? null;
			if ( $downloadPath === null ) {
				continue;
			}

			// Build the absolute download URL (Cloud prepends /wiki to the link).
			$downloadUrl = $this->isCloud
				? $this->baseUrl . '/wiki' . $downloadPath
				: $this->baseUrl . $downloadPath;

			$attachments[] = [
				'id'          => (string)$result['id'],
				'title'       => (string)$result['title'],
				'downloadUrl' => $downloadUrl,
				'mediaType'   => (string)( $result['metadata']['mediaType'] ?? 'application/octet-stream' ),
			];
		}

		return $attachments;
	}

	/**
	 * Download a file from Confluence (e.g. an attachment) and return its raw bytes.
	 *
	 * The same Basic-Auth credentials are sent with the request.
	 *
	 * @param string $url Absolute URL of the file to download.
	 * @return string Raw file content.
	 * @throws \RuntimeException On download failure.
	 */
	public function downloadFile( string $url ): string {
		$context = stream_context_create( [
			'http' => [
				'method'        => 'GET',
				'header'        => 'Authorization: ' . $this->authHeader(),
				'ignore_errors' => true,
				'timeout'       => self::DOWNLOAD_TIMEOUT,
			],
			'ssl'  => [
				'verify_peer'      => true,
				'verify_peer_name' => true,
			],
		] );

		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.file_get_contents
		$data = file_get_contents( $url, false, $context );
		if ( $data === false ) {
			throw new \RuntimeException( "Failed to download Confluence file: $url" );
		}

		return $data;
	}

	/**
	 * Fetch a subset of pages from a Confluence space by their exact titles.
	 *
	 * For each requested title one API call is made (Confluence does not
	 * support bulk title lookup in a single request).  Titles that do not
	 * match any page in the space are silently skipped.
	 *
	 * @param string   $spaceKey The Confluence space key (e.g. "DOCS").
	 * @param string[] $titles   Exact page titles to fetch.
	 * @return list<array{id: string, title: string, parentId: string|null}>
	 * @throws \RuntimeException On API error.
	 */
	public function fetchPagesByTitles( string $spaceKey, array $titles ): array {
		$pages = [];

		foreach ( $titles as $title ) {
			$title = trim( $title );
			if ( $title === '' ) {
				continue;
			}

			$data = $this->get( '/content', [
				'spaceKey' => $spaceKey,
				'type'     => 'page',
				'title'    => $title,
				'expand'   => 'ancestors',
				'limit'    => '1',
			] );

			foreach ( $data['results'] ?? [] as $result ) {
				$ancestors = $result['ancestors'] ?? [];
				$parentId  = $ancestors !== []
					? (string)$ancestors[ count( $ancestors ) - 1 ]['id']
					: null;

				$pages[] = [
					'id'       => (string)$result['id'],
					'title'    => (string)$result['title'],
					'parentId' => $parentId,
				];
			}
		}

		return $pages;
	}

	/**
	 * Lightweight connectivity and authentication check.
	 *
	 * Fetches one page from the space to verify that the credentials work
	 * and the space exists.  Throws RuntimeException on failure.
	 *
	 * @param string $spaceKey The Confluence space key.
	 * @throws \RuntimeException On auth failure, bad space key, or network error.
	 */
	public function validateAccess( string $spaceKey ): void {
		$this->get( '/content', [
			'spaceKey' => $spaceKey,
			'type'     => 'page',
			'start'    => '0',
			'limit'    => '1',
		] );
	}

	/**
	 * Returns true when the configured Confluence instance is Cloud-hosted.
	 */
	public function isCloud(): bool {
		return $this->isCloud;
	}
}
