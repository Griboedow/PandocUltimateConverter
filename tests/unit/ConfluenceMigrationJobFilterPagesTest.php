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

public function testSubtreePatternWithEmptyBaseNameIsIgnored(): void {
$pages  = [ $this->page( '1', 'Home' ) ];
$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ '/*' ] );
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
// "Docs" has a child "Install" — an exact pattern must NOT pull in children.
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
// Glob patterns — title matching only, NO descendant expansion
// -----------------------------------------------------------------------

public function testGlobSuffixMatchesTitlePrefixOnly(): void {
$pages  = [
$this->page( '1', 'Documentation' ),
$this->page( '2', 'Documentation v2' ),
$this->page( '3', 'About' ),
];
$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Documentation*' ] );
$this->assertEqualsCanonicalizing( [ '1', '2' ], $this->ids( $result ) );
}

public function testGlobDoesNotExpandDescendants(): void {
// "Docs*" matches 'Docs' by title, but must NOT pull in its children.
$pages  = [
$this->page( '1', 'Docs' ),
$this->page( '2', 'Install', '1' ),
];
$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Docs*' ] );
$this->assertSame( [ '1' ], $this->ids( $result ) );
}

public function testGlobOnlyMatchesAllPages(): void {
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
// Subtree patterns ("pageName/*") — exact parent match + all descendants
// -----------------------------------------------------------------------

public function testSubtreePatternIncludesParentPage(): void {
$pages  = [
$this->page( '1', 'Docs' ),
$this->page( '2', 'Other' ),
];
$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Docs/*' ] );
$this->assertContains( '1', $this->ids( $result ) );
}

public function testSubtreePatternIncludesDirectChildren(): void {
$pages  = [
$this->page( '1', 'Docs' ),
$this->page( '2', 'Install', '1' ),
$this->page( '3', 'Other' ),
];
$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Docs/*' ] );
$this->assertEqualsCanonicalizing( [ '1', '2' ], $this->ids( $result ) );
}

public function testSubtreePatternIncludesAllDescendantLevels(): void {
// Hierarchy: Docs → Install → Quick Start → Step 1
$pages  = [
$this->page( '1', 'Docs' ),
$this->page( '2', 'Install', '1' ),
$this->page( '3', 'Quick Start', '2' ),
$this->page( '4', 'Step 1', '3' ),
$this->page( '5', 'Other' ),
];
$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Docs/*' ] );
$this->assertEqualsCanonicalizing( [ '1', '2', '3', '4' ], $this->ids( $result ) );
$this->assertNotContains( '5', $this->ids( $result ) );
}

public function testSubtreePatternDoesNotAddUnrelatedPages(): void {
$pages  = [
$this->page( '1', 'Docs' ),
$this->page( '2', 'Install', '1' ),
$this->page( '3', 'Blog' ),
$this->page( '4', 'Post', '3' ),
];
$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Docs/*' ] );
$this->assertEqualsCanonicalizing( [ '1', '2' ], $this->ids( $result ) );
}

public function testSubtreePatternRequiresExactTitleMatchForParent(): void {
// "Documentation/*" must NOT match "Documentation v2".
$pages  = [
$this->page( '1', 'Documentation' ),
$this->page( '2', 'Documentation v2' ),
$this->page( '3', 'Child', '2' ),
];
$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Documentation/*' ] );
// Only '1' (exact match) and its children; '2' and '3' are unrelated.
$this->assertEqualsCanonicalizing( [ '1' ], $this->ids( $result ) );
}

public function testSubtreePatternNoMatchReturnsEmpty(): void {
$pages  = [ $this->page( '1', 'Home' ) ];
$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Missing/*' ] );
$this->assertSame( [], $result );
}

// -----------------------------------------------------------------------
// Mixed patterns — exact, glob, and subtree combined
// -----------------------------------------------------------------------

public function testMixedExactAndSubtreePatterns(): void {
// Hierarchy: Docs → Install; Blog standalone
$pages  = [
$this->page( '1', 'Docs' ),
$this->page( '2', 'Install', '1' ),
$this->page( '3', 'Blog' ),
$this->page( '4', 'Post', '3' ),
];
// Exact "Blog" + subtree "Docs/*"
$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Blog', 'Docs/*' ] );
$this->assertEqualsCanonicalizing( [ '1', '2', '3' ], $this->ids( $result ) );
}

public function testMixedGlobAndSubtreePatterns(): void {
$pages  = [
$this->page( '1', 'Docs' ),
$this->page( '2', 'Docs Extra' ),
$this->page( '3', 'Install', '1' ),
];
// Glob "Docs*" matches '1' and '2' by title (no descendants);
// subtree "Docs/*" adds '3' (child of '1').
$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Docs*', 'Docs/*' ] );
$this->assertEqualsCanonicalizing( [ '1', '2', '3' ], $this->ids( $result ) );
}

public function testDuplicatesNotReturnedWhenPatternsOverlap(): void {
$pages  = [
$this->page( '1', 'Docs' ),
$this->page( '2', 'Install', '1' ),
];
// Both exact "Docs" and subtree "Docs/*" target page '1'; must appear once.
$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'Docs', 'Docs/*' ] );
$ids    = $this->ids( $result );
$this->assertCount( count( array_unique( $ids ) ), $ids );
$this->assertEqualsCanonicalizing( [ '1', '2' ], $ids );
}

// -----------------------------------------------------------------------
// Orphan handling
// -----------------------------------------------------------------------

public function testDescendantWithoutMatchingAncestorInListIsNotIncluded(): void {
// "Child" is a child of parent '99' which is not in the pages list.
$pages  = [
$this->page( '1', 'OtherRoot' ),
$this->page( '2', 'Child', '99' ),
];
$result = ConfluenceMigrationJob::filterPagesByPatterns( $pages, [ 'OtherRoot/*' ] );
// "Child" is not a descendant of "OtherRoot" — must not be included.
$this->assertSame( [ '1' ], $this->ids( $result ) );
}
}
