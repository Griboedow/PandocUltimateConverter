<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit;

use MediaWiki\Extension\PandocUltimateConverter\Jobs\ConfluenceMigrationJob;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConfluenceMigrationJob::filterPagesByPatterns().
 *
 * @covers \MediaWiki\Extension\PandocUltimateConverter\Jobs\ConfluenceMigrationJob
 */
class ConfluenceMigrationJobFilterPagesTest extends TestCase {

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Build a minimal page array.
	 *
	 * @return array{id: string, title: string, parentId: string|null}
	 */
	private function page( string $id, string $title, ?string $parentId = null ): array {
		return [ 'id' => $id, 'title' => $title, 'parentId' => $parentId ];
	}

	/**
	 * Extract only IDs from the result for simpler assertions.
	 *
	 * @param list<array{id: string, title: string, parentId: string|null}> $pages
	 * @return string[]
	 */
	private function ids( array $pages ): array {
		return array_values( array_map( static fn ( $p ) => $p['id'], $pages ) );
	}

	// -----------------------------------------------------------------------
	// Edge cases
	// -----------------------------------------------------------------------

	public function testEmptyPagesReturnsEmpty(): void {
		$result = ConfluenceMigrationJob::filterPagesByPatterns( [], [ 'Home' ] );
		$this->assertSame( [], $result );
	}

	public function testEmptyPatternsReturnsEmpty(): void {
		$pages  = [ $this->page( '1', 'Home' ) ];
		$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [] );
		$this->assertSame( [], $result );
	}

	public function testBlankPatternLinesAreIgnored(): void {
		$pages  = [ $this->page( '1', 'Home' ) ];
		$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ '', '   ' ] );
		$this->assertSame( [], $result );
	}

	// -----------------------------------------------------------------------
	// Exact matches (no wildcard)
	// -----------------------------------------------------------------------

	public function testExactMatchSelectsSinglePage(): void {
		$pages  = [
			$this->page( '1', 'Home' ),
			$this->page( '2', 'About' ),
		];
		$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Home' ] );
		$this->assertSame( [ '1' ], $this->ids( $result ) );
	}

	public function testExactMatchDoesNotIncludeChildren(): void {
		// "Docs" has a child "Docs/Install" but an exact pattern should NOT pull
		// in children.
		$pages  = [
			$this->page( '1', 'Docs' ),
			$this->page( '2', 'Install', '1' ),
		];
		$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Docs' ] );
		$this->assertSame( [ '1' ], $this->ids( $result ) );
	}

	public function testExactMatchIsCaseSensitive(): void {
		$pages  = [
			$this->page( '1', 'Home' ),
			$this->page( '2', 'home' ),
		];
		// fnmatch without FNM_CASEFOLD — 'Home' != 'home'
		$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Home' ] );
		$this->assertSame( [ '1' ], $this->ids( $result ) );
	}

	public function testMultipleExactPatternsSelectMultiplePages(): void {
		$pages  = [
			$this->page( '1', 'Home' ),
			$this->page( '2', 'About' ),
			$this->page( '3', 'Contact' ),
		];
		$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Home', 'Contact' ] );
		$this->assertEqualsCanonicalizing( [ '1', '3' ], $this->ids( $result ) );
	}

	public function testNoMatchReturnsEmpty(): void {
		$pages  = [ $this->page( '1', 'Home' ) ];
		$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Missing' ] );
		$this->assertSame( [], $result );
	}

	// -----------------------------------------------------------------------
	// Wildcard patterns — title matching only (no descendants)
	// -----------------------------------------------------------------------

	public function testWildcardSuffixMatchesTitlePrefix(): void {
		$pages  = [
			$this->page( '1', 'Documentation' ),
			$this->page( '2', 'Documentation v2' ),
			$this->page( '3', 'About' ),
		];
		// "Documentation*" should match '1' and '2' based on title alone
		// (descendants are also added but these pages have none).
		$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Documentation*' ] );
		$this->assertEqualsCanonicalizing( [ '1', '2' ], $this->ids( $result ) );
	}

	public function testWildcardOnlyMatchesAllPages(): void {
		$pages  = [
			$this->page( '1', 'Home' ),
			$this->page( '2', 'About', '1' ),
		];
		$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ '*' ] );
		$this->assertEqualsCanonicalizing( [ '1', '2' ], $this->ids( $result ) );
	}

	public function testQuestionMarkWildcard(): void {
		$pages  = [
			$this->page( '1', 'Page1' ),
			$this->page( '2', 'Page2' ),
			$this->page( '3', 'PageAB' ),
		];
		$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Page?' ] );
		$this->assertEqualsCanonicalizing( [ '1', '2' ], $this->ids( $result ) );
	}

	// -----------------------------------------------------------------------
	// Wildcard patterns — descendants are included
	// -----------------------------------------------------------------------

	public function testWildcardIncludesDirectChildren(): void {
		$pages  = [
			$this->page( '1', 'Docs' ),
			$this->page( '2', 'Install', '1' ),
			$this->page( '3', 'Other' ),
		];
		$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Docs*' ] );
		// "Docs" matched by title; "Install" is a direct child of "Docs".
		$this->assertEqualsCanonicalizing( [ '1', '2' ], $this->ids( $result ) );
	}

	public function testWildcardIncludesAllDescendantLevels(): void {
		// Hierarchy: Docs → Install → Quick Start → Step 1
		$pages  = [
			$this->page( '1', 'Docs' ),
			$this->page( '2', 'Install', '1' ),
			$this->page( '3', 'Quick Start', '2' ),
			$this->page( '4', 'Step 1', '3' ),
			$this->page( '5', 'Other' ),
		];
		$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Docs*' ] );
		$this->assertEqualsCanonicalizing( [ '1', '2', '3', '4' ], $this->ids( $result ) );
		$this->assertNotContains( '5', $this->ids( $result ) );
	}

	public function testWildcardDoesNotAddUnrelatedPages(): void {
		$pages  = [
			$this->page( '1', 'Docs' ),
			$this->page( '2', 'Install', '1' ),
			$this->page( '3', 'Blog' ),
			$this->page( '4', 'Post', '3' ),
		];
		$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Docs*' ] );
		$this->assertEqualsCanonicalizing( [ '1', '2' ], $this->ids( $result ) );
	}

	// -----------------------------------------------------------------------
	// Mixed exact + wildcard patterns
	// -----------------------------------------------------------------------

	public function testMixedPatternsUnionResults(): void {
		// Hierarchy: Docs → Install; Blog standalone
		$pages  = [
			$this->page( '1', 'Docs' ),
			$this->page( '2', 'Install', '1' ),
			$this->page( '3', 'Blog' ),
			$this->page( '4', 'Post', '3' ),
		];
		// Exact "Blog" (no children) + wildcard "Docs*" (with child "Install")
		$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Blog', 'Docs*' ] );
		$this->assertEqualsCanonicalizing( [ '1', '2', '3' ], $this->ids( $result ) );
	}

	public function testDuplicatesNotReturnedWhenTwoPatternsBothMatchSamePage(): void {
		$pages  = [
			$this->page( '1', 'Docs' ),
			$this->page( '2', 'Install', '1' ),
		];
		// Both patterns match page '1'; it should appear only once.
		$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Docs', 'Docs*' ] );
		$ids    = $this->ids( $result );
		$this->assertCount( count( array_unique( $ids ) ), $ids );
		$this->assertEqualsCanonicalizing( [ '1', '2' ], $ids );
	}

	// -----------------------------------------------------------------------
	// Wildcard expansion stops at orphaned children
	// -----------------------------------------------------------------------

	public function testDescendantWithoutMatchingAncestorInListIsNotIncluded(): void {
		// "Child" is a child of "Parent" but "Parent" is not in the pages list.
		$pages  = [
			$this->page( '1', 'OtherRoot' ),
			$this->page( '2', 'Child', '99' ), // parent '99' not in list
		];
		$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'OtherRoot*' ] );
		// "Child" is not a descendant of "OtherRoot" — must not be included.
		$this->assertSame( [ '1' ], $this->ids( $result ) );
	}
}
