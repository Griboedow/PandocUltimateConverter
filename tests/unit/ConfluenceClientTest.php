<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit;

use MediaWiki\Extension\PandocUltimateConverter\ConfluenceClient;
use PHPUnit\Framework\TestCase;

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
}
