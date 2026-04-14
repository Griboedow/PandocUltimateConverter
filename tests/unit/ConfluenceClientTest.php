<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit;

use MediaWiki\Extension\PandocUltimateConverter\ConfluenceClient;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for ConfluenceClient pure-logic helpers.
 *
 * Network-dependent methods (fetchAllPages, fetchPageBody, …) are not tested
 * here; they belong in integration tests that require a running Confluence
 * instance or a mock HTTP server.
 *
 * @covers \MediaWiki\Extension\PandocUltimateConverter\ConfluenceClient
 */
class ConfluenceClientTest extends TestCase {

	// -----------------------------------------------------------------------
	// parseStatusCode()
	// -----------------------------------------------------------------------

	private function parseStatusCode( array $headers ): int {
		$client = new ConfluenceClient( 'https://example.atlassian.net', 'user', 'token' );
		$method = new ReflectionMethod( $client, 'parseStatusCode' );
		$method->setAccessible( true );
		return $method->invoke( $client, $headers );
	}

	public function testParseStatusCode200(): void {
		$this->assertSame( 200, $this->parseStatusCode( [ 'HTTP/1.1 200 OK' ] ) );
	}

	public function testParseStatusCode401(): void {
		$this->assertSame( 401, $this->parseStatusCode( [ 'HTTP/1.1 401 Unauthorized' ] ) );
	}

	public function testParseStatusCode403(): void {
		$this->assertSame( 403, $this->parseStatusCode( [ 'HTTP/1.1 403 Forbidden' ] ) );
	}

	public function testParseStatusCode404(): void {
		$this->assertSame( 404, $this->parseStatusCode( [ 'HTTP/1.1 404 Not Found' ] ) );
	}

	public function testParseStatusCode500(): void {
		$this->assertSame( 500, $this->parseStatusCode( [ 'HTTP/1.1 500 Internal Server Error' ] ) );
	}

	public function testParseStatusCodeHttp10(): void {
		$this->assertSame( 200, $this->parseStatusCode( [ 'HTTP/1.0 200 OK' ] ) );
	}

	public function testParseStatusCodeHttp2(): void {
		$this->assertSame( 200, $this->parseStatusCode( [ 'HTTP/2 200' ] ) );
	}

	public function testParseStatusCodePicksFirstStatusLine(): void {
		// PHP puts the final status line first (after redirect follow), but we
		// always want to parse the first match in the array.
		$headers = [ 'HTTP/1.1 200 OK', 'Content-Type: application/json' ];
		$this->assertSame( 200, $this->parseStatusCode( $headers ) );
	}

	public function testParseStatusCodeEmptyArrayReturnsZero(): void {
		$this->assertSame( 0, $this->parseStatusCode( [] ) );
	}

	public function testParseStatusCodeNoStatusLineReturnsZero(): void {
		$this->assertSame( 0, $this->parseStatusCode( [ 'Content-Type: application/json' ] ) );
	}

	// -----------------------------------------------------------------------
	// isCloud() / API base path detection
	// -----------------------------------------------------------------------

	public function testIsCloudReturnsTrueForAtlassianNetUrl(): void {
		$client = new ConfluenceClient( 'https://example.atlassian.net', 'user@example.com', 'token' );
		$this->assertTrue( $client->isCloud() );
	}

	public function testIsCloudReturnsTrueForSubdomainAtlassianNet(): void {
		$client = new ConfluenceClient( 'https://mycompany.atlassian.net/wiki', 'user', 'token' );
		$this->assertTrue( $client->isCloud() );
	}

	public function testIsCloudReturnsFalseForSelfHostedServer(): void {
		$client = new ConfluenceClient( 'https://confluence.example.com', 'admin', 'password' );
		$this->assertFalse( $client->isCloud() );
	}

	public function testIsCloudReturnsFalseForGenericHttpsHost(): void {
		$client = new ConfluenceClient( 'https://wiki.company.org', 'admin', 'pass' );
		$this->assertFalse( $client->isCloud() );
	}

	public function testIsCloudIsCaseInsensitive(): void {
		$client = new ConfluenceClient( 'https://Example.ATLASSIAN.NET', 'user', 'token' );
		$this->assertTrue( $client->isCloud() );
	}

	// -----------------------------------------------------------------------
	// Constructor: trailing slashes are stripped from the base URL
	// -----------------------------------------------------------------------

	public function testConstructorStripsTrailingSlash(): void {
		// We can verify this indirectly: isCloud() still works after stripping.
		$client = new ConfluenceClient( 'https://example.atlassian.net/', 'u', 't' );
		$this->assertTrue( $client->isCloud() );
	}

	public function testConstructorStripsMultipleTrailingSlashes(): void {
		$client = new ConfluenceClient( 'https://confluence.example.com///', 'u', 't' );
		$this->assertFalse( $client->isCloud() );
	}

	// -----------------------------------------------------------------------
	// authHeader() — Basic vs Bearer selection
	// -----------------------------------------------------------------------

	private function authHeader( string $baseUrl, string $apiUser, string $apiToken ): string {
		$client = new ConfluenceClient( $baseUrl, $apiUser, $apiToken );
		$method = new ReflectionMethod( $client, 'authHeader' );
		$method->setAccessible( true );
		return $method->invoke( $client );
	}

	public function testAuthHeaderCloudUsesBasicAuth(): void {
		$header = $this->authHeader( 'https://example.atlassian.net', 'user@example.com', 'mytoken' );
		$this->assertStringStartsWith( 'Basic ', $header );
		$this->assertSame( 'Basic ' . base64_encode( 'user@example.com:mytoken' ), $header );
	}

	public function testAuthHeaderServerWithUsernameUsesBasicAuth(): void {
		$header = $this->authHeader( 'https://confluence.example.com', 'admin', 'password123' );
		$this->assertStringStartsWith( 'Basic ', $header );
		$this->assertSame( 'Basic ' . base64_encode( 'admin:password123' ), $header );
	}

	public function testAuthHeaderServerWithEmptyUserUsesBearerPat(): void {
		$header = $this->authHeader( 'https://confluence.example.com', '', 'my-personal-access-token' );
		$this->assertSame( 'Bearer my-personal-access-token', $header );
	}

	public function testAuthHeaderCloudWithEmptyUserStillUsesBasicAuth(): void {
		// Cloud should always use Basic auth, even if username is accidentally empty.
		$header = $this->authHeader( 'https://example.atlassian.net', '', 'cloudtoken' );
		$this->assertStringStartsWith( 'Basic ', $header );
	}
}
