<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit;

use MediaWiki\Extension\PandocUltimateConverter\SpecialPandocExport;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure-logic static helpers in SpecialPandocExport.
 *
 * These methods have no MediaWiki dependencies and can therefore be exercised
 * without a running wiki installation.
 *
 * @covers \MediaWiki\Extension\PandocUltimateConverter\SpecialPandocExport::sanitizeFilename
 * @covers \MediaWiki\Extension\PandocUltimateConverter\SpecialPandocExport::buildCombinedWikitext
 * @covers \MediaWiki\Extension\PandocUltimateConverter\SpecialPandocExport::extractWikilinkTargets
 */
class SpecialPandocExportTest extends TestCase {

	// ------------------------------------------------------------------
	// SUPPORTED_FORMATS constant — structural assertions
	// ------------------------------------------------------------------

	public function testSupportedFormatsContainsExpectedKeys(): void {
		$expected = [ 'docx', 'odt', 'epub', 'pdf', 'html', 'rtf', 'txt' ];
		$this->assertSame(
			$expected,
			array_keys( SpecialPandocExport::SUPPORTED_FORMATS ),
			'SUPPORTED_FORMATS must list exactly the seven expected format keys in order'
		);
	}

	/** @dataProvider provideFormatKeys */
	public function testSupportedFormatsEntryHasRequiredSubKeys( string $formatKey ): void {
		$info = SpecialPandocExport::SUPPORTED_FORMATS[$formatKey];
		foreach ( [ 'label', 'pandoc_format', 'ext', 'mime' ] as $subKey ) {
			$this->assertArrayHasKey(
				$subKey,
				$info,
				"SUPPORTED_FORMATS['$formatKey'] must have a '$subKey' entry"
			);
			$this->assertNotEmpty(
				$info[$subKey],
				"SUPPORTED_FORMATS['$formatKey']['$subKey'] must not be empty"
			);
		}
	}

	/** @dataProvider provideFormatKeys */
	public function testSupportedFormatsExtMatchesKey( string $formatKey ): void {
		$this->assertSame(
			$formatKey,
			SpecialPandocExport::SUPPORTED_FORMATS[$formatKey]['ext'],
			"The 'ext' of format '$formatKey' should match its key"
		);
	}

	public static function provideFormatKeys(): array {
		return array_map(
			static fn( $k ) => [ $k ],
			array_keys( SpecialPandocExport::SUPPORTED_FORMATS )
		);
	}

	// ------------------------------------------------------------------
	// sanitizeFilename
	// ------------------------------------------------------------------

	/** @dataProvider provideSanitizeFilenameInputs */
	public function testSanitizeFilename( string $input, string $expected ): void {
		$this->assertSame( $expected, SpecialPandocExport::sanitizeFilename( $input ) );
	}

	public static function provideSanitizeFilenameInputs(): array {
		return [
			'plain ascii name'               => [ 'My Report',           'My Report' ],
			'forward slash replaced'         => [ 'a/b',                 'a_b' ],
			'backslash replaced'             => [ 'a\\b',                'a_b' ],
			'colon replaced'                 => [ 'Talk:Page',           'Talk_Page' ],
			'asterisk replaced'              => [ 'foo*bar',             'foo_bar' ],
			'question mark replaced'         => [ 'what?',               'what_' ],
			'double quote replaced'          => [ '"quoted"',            '_quoted_' ],
			'angle brackets replaced'        => [ '<tag>',               '_tag_' ],
			'pipe replaced'                  => [ 'a|b',                 'a_b' ],
			'leading dot stripped'           => [ '.hidden',             'hidden' ],
			'trailing dot stripped'          => [ 'file.',               'file' ],
			'leading space stripped'         => [ ' name',               'name' ],
			'trailing space stripped'        => [ 'name ',               'name' ],
			'empty string returns export'    => [ '',                    'export' ],
			'only dots returns export'       => [ '...',                 'export' ],
			'unicode preserved'              => [ 'Статья',              'Статья' ],
			'mixed safe and unsafe'          => [ 'Report: Q1/2025',     'Report_ Q1_2025' ],
			'multiple unsafe chars'          => [ '<>:"/\\|?*',          '_________' ],
		];
	}

	// ------------------------------------------------------------------
	// buildCombinedWikitext
	// ------------------------------------------------------------------

	public function testBuildCombinedWikitextSinglePageReturnsRawWikitext(): void {
		$result = SpecialPandocExport::buildCombinedWikitext( [ 'My Page' ], [ 'Some content here.' ] );
		$this->assertSame( 'Some content here.', $result );
	}

	public function testBuildCombinedWikitextTwoPagesAddsHeadingsAndSeparator(): void {
		$result = SpecialPandocExport::buildCombinedWikitext(
			[ 'Page One', 'Page Two' ],
			[ 'Content A', 'Content B' ]
		);

		// Each section should start with a level-1 heading
		$this->assertStringContainsString( '= Page One =', $result );
		$this->assertStringContainsString( '= Page Two =', $result );
		// Sections are separated by a horizontal rule
		$this->assertStringContainsString( "\n\n----\n\n", $result );
		// Wikitext content is preserved
		$this->assertStringContainsString( 'Content A', $result );
		$this->assertStringContainsString( 'Content B', $result );
	}

	public function testBuildCombinedWikitextThreePagesOrderIsPreserved(): void {
		$pages     = [ 'Alpha', 'Beta', 'Gamma' ];
		$wikitexts = [ 'Text A', 'Text B', 'Text C' ];
		$result    = SpecialPandocExport::buildCombinedWikitext( $pages, $wikitexts );

		$posAlpha = strpos( $result, 'Alpha' );
		$posBeta  = strpos( $result, 'Beta' );
		$posGamma = strpos( $result, 'Gamma' );
		$this->assertNotFalse( $posAlpha );
		$this->assertNotFalse( $posBeta );
		$this->assertNotFalse( $posGamma );
		$this->assertLessThan( $posBeta,  $posAlpha, 'Alpha heading must precede Beta' );
		$this->assertLessThan( $posGamma, $posBeta,  'Beta heading must precede Gamma' );
	}

	public function testBuildCombinedWikitextEscapesWikitextSpecialCharsInPageName(): void {
		// A page name containing "=" would break wikitext heading syntax without escaping.
		$result = SpecialPandocExport::buildCombinedWikitext(
			[ 'A=B', 'Normal' ],
			[ 'Content A', 'Content B' ]
		);

		// The "=" in the page name must be HTML-entity-escaped in the heading
		$this->assertStringNotContainsString( '= A=B =', $result );
		$this->assertStringContainsString( 'A&#61;B', $result );
	}

	public function testBuildCombinedWikitextEmptyWikitextSinglePage(): void {
		$result = SpecialPandocExport::buildCombinedWikitext( [ 'EmptyPage' ], [ '' ] );
		$this->assertSame( '', $result );
	}

	// ------------------------------------------------------------------
	// extractWikilinkTargets
	// ------------------------------------------------------------------

	public function testExtractWikilinkTargetsEmptyWikitext(): void {
		$this->assertSame( [], SpecialPandocExport::extractWikilinkTargets( '' ) );
	}

	public function testExtractWikilinkTargetsNoLinks(): void {
		$this->assertSame(
			[],
			SpecialPandocExport::extractWikilinkTargets( 'Plain text without any wiki links.' )
		);
	}

	public function testExtractWikilinkTargetsIgnoresLinksWithoutNamespaceColon(): void {
		// [[PlainPage]] has no ":", so it must not appear in the results.
		$result = SpecialPandocExport::extractWikilinkTargets( '[[PlainPage]] and [[Another]]' );
		$this->assertSame( [], $result );
	}

	public function testExtractWikilinkTargetsStandardFileLink(): void {
		$result = SpecialPandocExport::extractWikilinkTargets( '[[File:photo.jpg]]' );
		$this->assertContains( 'File:photo.jpg', $result );
	}

	public function testExtractWikilinkTargetsImageAlias(): void {
		$result = SpecialPandocExport::extractWikilinkTargets( '[[Image:logo.png|thumb|Caption]]' );
		$this->assertContains( 'Image:logo.png', $result );
	}

	public function testExtractWikilinkTargetsMediaNamespace(): void {
		$result = SpecialPandocExport::extractWikilinkTargets( '[[Media:audio.ogg]]' );
		$this->assertContains( 'Media:audio.ogg', $result );
	}

	public function testExtractWikilinkTargetsLocalizedNamespace(): void {
		$result = SpecialPandocExport::extractWikilinkTargets( '[[Datei:bild.png]] [[Файл:фото.jpg]]' );
		$this->assertContains( 'Datei:bild.png', $result );
		$this->assertContains( 'Файл:фото.jpg', $result );
	}

	public function testExtractWikilinkTargetsDeduplicatesIdenticalLinks(): void {
		$wikitext = '[[File:same.png]] and again [[File:same.png|thumb]]';
		$result   = SpecialPandocExport::extractWikilinkTargets( $wikitext );
		$this->assertCount( 1, array_filter( $result, static fn( $t ) => $t === 'File:same.png' ) );
	}

	public function testExtractWikilinkTargetsMixedLinksReturnOnlyNamespaced(): void {
		$wikitext = '[[PlainPage]] [[File:img.jpg]] [[Category:Foo]] [[Template:Bar]]';
		$result   = SpecialPandocExport::extractWikilinkTargets( $wikitext );

		// PlainPage has no colon — must be absent
		$this->assertNotContains( 'PlainPage', $result );
		// Namespace-prefixed links should be present
		$this->assertContains( 'File:img.jpg', $result );
		$this->assertContains( 'Category:Foo', $result );
		$this->assertContains( 'Template:Bar', $result );
	}

	public function testExtractWikilinkTargetsPipeAndAnchorNotIncludedInTarget(): void {
		// [[File:img.jpg|thumb|right]] — only "File:img.jpg" before the first "|"
		$result = SpecialPandocExport::extractWikilinkTargets( '[[File:img.jpg|thumb|right]]' );
		$this->assertContains( 'File:img.jpg', $result );
		foreach ( $result as $target ) {
			$this->assertStringNotContainsString( '|', $target );
		}
	}

	public function testExtractWikilinkTargetsAnchorNotIncludedInTarget(): void {
		// [[File:doc.pdf#page=2]] — anchor must be stripped
		$result = SpecialPandocExport::extractWikilinkTargets( '[[File:doc.pdf#page=2]]' );
		$this->assertContains( 'File:doc.pdf', $result );
		foreach ( $result as $target ) {
			$this->assertStringNotContainsString( '#', $target );
		}
	}
}
