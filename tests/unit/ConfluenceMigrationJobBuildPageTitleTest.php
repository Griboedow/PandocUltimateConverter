<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit;

use MediaWiki\Extension\PandocUltimateConverter\Jobs\ConfluenceMigrationJob;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for ConfluenceMigrationJob::buildPageTitle().
 *
 * @covers \MediaWiki\Extension\PandocUltimateConverter\Jobs\ConfluenceMigrationJob
 */
class ConfluenceMigrationJobBuildPageTitleTest extends TestCase {

	private ReflectionMethod $buildPageTitle;
	private ConfluenceMigrationJob $job;

	protected function setUp(): void {
		parent::setUp();

		$title = $this->createMock( \Title::class );
		$this->job = new ConfluenceMigrationJob( $title, [
			'confluenceUrl' => 'https://example.atlassian.net',
			'spaceKey'      => 'TEST',
			'apiUser'       => 'u',
			'apiToken'      => 't',
			'userId'        => 1,
		] );

		$this->buildPageTitle = new ReflectionMethod( $this->job, 'buildPageTitle' );
		$this->buildPageTitle->setAccessible( true );
	}

	private function build( string $confluenceTitle, string $prefix ): string {
		return $this->buildPageTitle->invoke( $this->job, $confluenceTitle, $prefix );
	}

	// -----------------------------------------------------------------------
	// No prefix
	// -----------------------------------------------------------------------

	public function testNoPrefixReturnsTitleAsIs(): void {
		$this->assertSame( 'My Page', $this->build( 'My Page', '' ) );
	}

	public function testNoPrefixWithSpecialChars(): void {
		$this->assertSame( 'FAQ: What & Why?', $this->build( 'FAQ: What & Why?', '' ) );
	}

	// -----------------------------------------------------------------------
	// With prefix
	// -----------------------------------------------------------------------

	public function testPrefixPrependsWithSlash(): void {
		$this->assertSame( 'Confluence/DOCS/My Page', $this->build( 'My Page', 'Confluence/DOCS' ) );
	}

	public function testSingleLevelPrefix(): void {
		$this->assertSame( 'Import/Home', $this->build( 'Home', 'Import' ) );
	}

	public function testEmptyPrefixBehavesLikeNoPrefix(): void {
		$this->assertSame( 'Hello World', $this->build( 'Hello World', '' ) );
	}

	// -----------------------------------------------------------------------
	// Namespace-aware prefix
	// -----------------------------------------------------------------------

	public function testNamespaceOnlyPrefixCreatesNsTitle(): void {
		// "MyNS:" → "MyNS:PageTitle"
		$this->assertSame( 'MyNS:Home', $this->build( 'Home', 'MyNS:' ) );
	}

	public function testNamespaceWithSubprefixCreatesNsSubprefixTitle(): void {
		// "MyNS:Confluence/DOCS" → "MyNS:Confluence/DOCS/My Page"
		$this->assertSame( 'MyNS:Confluence/DOCS/My Page', $this->build( 'My Page', 'MyNS:Confluence/DOCS' ) );
	}

	public function testNamespaceWithSimpleSubprefix(): void {
		$this->assertSame( 'MyNS:Docs/FAQ', $this->build( 'FAQ', 'MyNS:Docs' ) );
	}

	public function testNamespaceOnlyPreservesSpecialCharsInTitle(): void {
		$this->assertSame( 'MyNS:FAQ: What & Why?', $this->build( 'FAQ: What & Why?', 'MyNS:' ) );
	}

	// -----------------------------------------------------------------------
	// 255-byte truncation
	// -----------------------------------------------------------------------

	public function testTitleExactly255BytesIsNotTruncated(): void {
		$title = str_repeat( 'a', 255 );
		$result = $this->build( $title, '' );
		$this->assertSame( 255, strlen( $result ) );
		$this->assertSame( $title, $result );
	}

	public function testTitleOver255BytesIsTruncated(): void {
		$title = str_repeat( 'a', 300 );
		$result = $this->build( $title, '' );
		$this->assertSame( 255, strlen( $result ) );
	}

	public function testTitlePlusPrefixOver255BytesIsTruncated(): void {
		// prefix(10) + '/'(1) + title(255) = 266 bytes — must be truncated.
		$result = $this->build( str_repeat( 'b', 255 ), str_repeat( 'a', 10 ) );
		$this->assertLessThanOrEqual( 255, strlen( $result ) );
	}

	// -----------------------------------------------------------------------
	// UTF-8 safe truncation
	// -----------------------------------------------------------------------

	public function testUtf8TitleIsNotCorruptedByTruncation(): void {
		// Each '日' character is 3 bytes in UTF-8.
		// 86 characters × 3 bytes = 258 bytes — just over the 255-byte limit.
		$title  = str_repeat( '日', 86 );
		$result = $this->build( $title, '' );

		// Must be at most 255 bytes.
		$this->assertLessThanOrEqual( 255, strlen( $result ) );

		// And must be valid UTF-8 (no split multi-byte sequences).
		$this->assertSame( mb_strlen( $result, 'UTF-8' ), mb_strlen( $result ) );
	}

	public function testAsciiTitleUnder255BytesLeftIntact(): void {
		$title = 'Short title';
		$this->assertSame( $title, $this->build( $title, '' ) );
	}
}
