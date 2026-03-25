<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit;

use MediaWiki\Extension\PandocUltimateConverter\Processors\PDFPreprocessor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for PDFPreprocessor — the HTML-cleaning logic, OCR scanned-PDF
 * detection, and wikitext assembly.
 *
 * Private methods are tested via reflection because they encapsulate clearly
 * defined transformations that are important to verify independently, without
 * requiring external binaries (pdftotext, pdftoppm, tesseract).
 *
 * @covers \MediaWiki\Extension\PandocUltimateConverter\Processors\PDFPreprocessor
 */
class PDFPreprocessorTest extends TestCase {

	private PDFPreprocessor $preprocessor;
	private ReflectionMethod $cleanHtml;
	private ReflectionMethod $classifyTextAsScanned;
	private ReflectionMethod $assembleWikitextFromPageTexts;
	private string $tmpDir;

	protected function setUp(): void {
		$this->preprocessor = new PDFPreprocessor();
		$this->cleanHtml    = new ReflectionMethod( PDFPreprocessor::class, 'cleanHtml' );
		$this->classifyTextAsScanned = new ReflectionMethod( PDFPreprocessor::class, 'classifyTextAsScanned' );
		$this->assembleWikitextFromPageTexts = new ReflectionMethod( PDFPreprocessor::class, 'assembleWikitextFromPageTexts' );

		$this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_test_' . uniqid();
		mkdir( $this->tmpDir, 0755, true );
	}

	protected function tearDown(): void {
		// Remove any temp files created during tests
		if ( is_dir( $this->tmpDir ) ) {
			foreach ( glob( $this->tmpDir . DIRECTORY_SEPARATOR . '*' ) ?: [] as $f ) {
				unlink( $f );
			}
			rmdir( $this->tmpDir );
		}
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Write HTML to a temp file, run cleanHtml() on it, and return the result.
	 */
	private function clean( string $html ): string {
		$file = $this->tmpDir . DIRECTORY_SEPARATOR . uniqid() . '.html';
		file_put_contents( $file, $html );
		$this->cleanHtml->invoke( $this->preprocessor, $file );
		return file_get_contents( $file );
	}

	/**
	 * Call the private classifyTextAsScanned() helper and return the result.
	 */
	private function classify( string $text ): bool {
		return $this->classifyTextAsScanned->invoke( $this->preprocessor, $text );
	}

	/**
	 * Call the private assembleWikitextFromPageTexts() helper and return the result.
	 *
	 * @param string[] $pageTexts
	 */
	private function assemble( array $pageTexts ): string {
		return $this->assembleWikitextFromPageTexts->invoke( $this->preprocessor, $pageTexts );
	}

	// ------------------------------------------------------------------
	// Positioning style removal
	// ------------------------------------------------------------------

	public function testRemovesAbsolutePositionStyleFromDiv(): void {
		$html = '<div style="position:absolute;left:100px;top:200px;">Hello</div>';
		$result = $this->clean( $html );
		$this->assertStringNotContainsString( 'position:absolute', $result );
		$this->assertStringContainsString( 'Hello', $result );
	}

	public function testRemovesRelativePositionStyleFromDiv(): void {
		$html = '<div style="position:relative;width:892px;height:1262px;">Content</div>';
		$result = $this->clean( $html );
		$this->assertStringNotContainsString( 'position:relative', $result );
		$this->assertStringContainsString( 'Content', $result );
	}

	public function testRemovesFixedPositionStyleFromSpan(): void {
		$html = '<span style="position:fixed;top:0">Header</span>';
		$result = $this->clean( $html );
		$this->assertStringNotContainsString( 'position:fixed', $result );
		$this->assertStringContainsString( 'Header', $result );
	}

	public function testPreservesNonPositioningStyles(): void {
		$html = '<p style="color:red;font-size:12pt">Text</p>';
		$result = $this->clean( $html );
		// Non-positioning styles should NOT be stripped
		$this->assertStringContainsString( 'color:red', $result );
	}

	// ------------------------------------------------------------------
	// Empty tag removal
	// ------------------------------------------------------------------

	public function testRemovesEmptySpanPageMarker(): void {
		$html = '<p>Text before<span id="3"></span>Text after</p>';
		$result = $this->clean( $html );
		$this->assertStringNotContainsString( '<span id="3"></span>', $result );
		$this->assertStringContainsString( 'Text before', $result );
		$this->assertStringContainsString( 'Text after', $result );
	}

	public function testRemovesEmptyAnchorPageMarker(): void {
		$html = '<p>Content<a name="page2"></a>More</p>';
		$result = $this->clean( $html );
		$this->assertStringNotContainsString( '<a name="page2"></a>', $result );
		$this->assertStringContainsString( 'Content', $result );
	}

	public function testPreservesSpansWithContent(): void {
		$html = '<span class="highlight">important text</span>';
		$result = $this->clean( $html );
		$this->assertStringContainsString( 'important text', $result );
	}

	// ------------------------------------------------------------------
	// <br> → newline replacement
	// ------------------------------------------------------------------

	public function testReplacesXhtmlBrWithNewline(): void {
		$html = '<p>Line one<br/>Line two</p>';
		$result = $this->clean( $html );
		$this->assertStringNotContainsString( '<br/>', $result );
		$this->assertStringContainsString( "\n", $result );
	}

	public function testReplacesHtmlBrWithNewline(): void {
		$html = '<p>Line one<br>Line two</p>';
		$result = $this->clean( $html );
		$this->assertStringNotContainsString( '<br>', $result );
		$this->assertStringContainsString( "\n", $result );
	}

	public function testReplacesBrWithSpaceBeforeSlashWithNewline(): void {
		$html = '<p>A<br />B</p>';
		$result = $this->clean( $html );
		$this->assertStringNotContainsString( '<br />', $result );
		$this->assertStringContainsString( "\n", $result );
	}

	// ------------------------------------------------------------------
	// Combined / realistic HTML
	// ------------------------------------------------------------------

	public function testCleansRealisticPdfToHtmlOutput(): void {
		$html = <<<'HTML'
<!DOCTYPE html>
<html>
<head><title>PDF Export</title></head>
<body>
<div style="position:absolute;left:0px;top:0px;width:595px;height:842px;">
<p style="position:absolute;top:72px;left:56px">
  <span id="1"></span>
  Chapter 1<br/>Introduction
</p>
<span style="position:fixed;top:10px">Footer</span>
</div>
</body>
</html>
HTML;

		$result = $this->clean( $html );

		$this->assertStringNotContainsString( 'position:absolute', $result );
		$this->assertStringNotContainsString( 'position:fixed', $result );
		$this->assertStringNotContainsString( '<span id="1"></span>', $result );
		$this->assertStringNotContainsString( '<br/>', $result );
		$this->assertStringContainsString( 'Chapter 1', $result );
		$this->assertStringContainsString( 'Introduction', $result );
		$this->assertStringContainsString( 'Footer', $result );
	}

	public function testDoesNothingWhenFileCannotBeRead(): void {
		// cleanHtml() should not throw — it guards on file_get_contents returning false.
		// Suppress the PHP warning that file_get_contents emits for a missing file.
		set_error_handler( static function (): bool { return true; } );
		try {
			$this->cleanHtml->invoke( $this->preprocessor, '/nonexistent/path/file.html' );
		} finally {
			restore_error_handler();
		}
		// If we reach here, no exception was thrown.
		$this->assertTrue( true );
	}

	// ------------------------------------------------------------------
	// classifyTextAsScanned — scanned PDF detection
	// ------------------------------------------------------------------

	public function testEmptyTextIsClassifiedAsScanned(): void {
		$this->assertTrue( $this->classify( '' ) );
	}

	public function testWhitespaceOnlyTextIsClassifiedAsScanned(): void {
		$this->assertTrue( $this->classify( "   \n\t\r\n   " ) );
	}

	public function testTextWithEnoughCharsIsNotScanned(): void {
		// 100 non-whitespace chars on a single page → well above the 50-char threshold
		$text = str_repeat( 'x', 100 );
		$this->assertFalse( $this->classify( $text ) );
	}

	public function testTextJustBelowThresholdIsScanned(): void {
		// 49 non-whitespace chars for a single page (threshold is 50)
		$text = str_repeat( 'a', 49 );
		$this->assertTrue( $this->classify( $text ) );
	}

	public function testTextExactlyAtThresholdIsNotScanned(): void {
		// Exactly 50 non-whitespace chars → not scanned (threshold is strictly less-than)
		$text = str_repeat( 'a', 50 );
		$this->assertFalse( $this->classify( $text ) );
	}

	public function testMultiPageTextUsesPageCountForThreshold(): void {
		// 3 pages (2 form feeds) → threshold = 3 × 50 = 150
		// Providing 100 chars should still be scanned, 200 chars should not
		$twoFormFeeds = "\f\f";

		$scannedText    = str_repeat( 'a', 100 ) . $twoFormFeeds;
		$textBasedText  = str_repeat( 'a', 200 ) . $twoFormFeeds;

		$this->assertTrue( $this->classify( $scannedText ),
			'100 chars across 3 pages (threshold 150) should be scanned' );
		$this->assertFalse( $this->classify( $textBasedText ),
			'200 chars across 3 pages (threshold 150) should not be scanned' );
	}

	public function testWhitespaceCharactersDoNotCountTowardsThreshold(): void {
		// 200 spaces — whitespace is excluded from the character count
		$text = str_repeat( ' ', 200 );
		$this->assertTrue( $this->classify( $text ) );
	}

	public function testSinglePageWithMixedWhitespaceAndText(): void {
		// 60 'x' + lots of spaces — non-whitespace count is 60, threshold is 50
		$text = str_repeat( 'x', 60 ) . str_repeat( ' ', 500 );
		$this->assertFalse( $this->classify( $text ) );
	}

	// ------------------------------------------------------------------
	// assembleWikitextFromPageTexts — wikitext assembly
	// ------------------------------------------------------------------

	public function testAssembleEmptyArrayReturnsEmptyString(): void {
		$this->assertSame( '', $this->assemble( [] ) );
	}

	public function testAssembleSinglePageSingleLine(): void {
		$result = $this->assemble( [ 'Hello world' ] );
		$this->assertSame( 'Hello world', $result );
	}

	public function testAssembleSinglePageMultipleLines(): void {
		$result = $this->assemble( [ "Line one\nLine two\nLine three" ] );
		$this->assertSame( "Line one\n\nLine two\n\nLine three", $result );
	}

	public function testAssembleTwoPagesAreSeparatedByHorizontalRule(): void {
		$result = $this->assemble( [ 'Page one text', 'Page two text' ] );
		$this->assertSame( "Page one text\n\n----\n\nPage two text", $result );
	}

	public function testAssembleThreePagesAreSeparatedByHorizontalRules(): void {
		$result = $this->assemble( [ 'Page one', 'Page two', 'Page three' ] );
		$this->assertSame( "Page one\n\n----\n\nPage two\n\n----\n\nPage three", $result );
	}

	public function testAssembleStripsLeadingAndTrailingWhitespaceFromLines(): void {
		$result = $this->assemble( [ "  trimmed line  \n   another  " ] );
		$this->assertSame( "trimmed line\n\nanother", $result );
	}

	public function testAssembleSkipsEmptyLines(): void {
		$result = $this->assemble( [ "First\n\n\nSecond\n\n" ] );
		$this->assertSame( "First\n\nSecond", $result );
	}

	public function testAssembleSkipsEntirelyEmptyPage(): void {
		// An empty (or whitespace-only) page should not produce a section or extra ----
		$result = $this->assemble( [ 'Page one', "   \n\n  ", 'Page three' ] );
		$this->assertSame( "Page one\n\n----\n\nPage three", $result );
	}

	public function testAssembleAllEmptyPagesReturnsEmptyString(): void {
		$result = $this->assemble( [ '', "  \n\t  ", "\n\n" ] );
		$this->assertSame( '', $result );
	}

	public function testAssembleMultiLinePageWithEmptyLinesInterleaved(): void {
		$pageText = "First line\n\nSecond line\n\n\nThird line";
		$result   = $this->assemble( [ $pageText ] );
		$this->assertSame( "First line\n\nSecond line\n\nThird line", $result );
	}

	public function testAssembleRealisticTwoPageOcrOutput(): void {
		$page1 = "Chapter 1\n\nThis is the introduction.\nIt spans two lines.";
		$page2 = "Chapter 2\n\nThe second chapter begins here.";

		$result = $this->assemble( [ $page1, $page2 ] );

		$this->assertStringContainsString( 'Chapter 1', $result );
		$this->assertStringContainsString( 'Chapter 2', $result );
		$this->assertStringContainsString( '----', $result );
		// Pages must appear in order
		$this->assertLessThan(
			strpos( $result, 'Chapter 2' ),
			strpos( $result, 'Chapter 1' )
		);
	}
}
