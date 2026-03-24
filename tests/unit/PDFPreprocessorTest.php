<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit;

use MediaWiki\Extension\PandocUltimateConverter\Processors\PDFPreprocessor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for PDFPreprocessor — specifically the HTML-cleaning logic that strips
 * pdftohtml artefacts before the intermediate HTML is handed to Pandoc.
 *
 * The private cleanHtml() method is tested via reflection because it encapsulates
 * a clearly defined transformation that is important to verify independently.
 *
 * @covers \MediaWiki\Extension\PandocUltimateConverter\Processors\PDFPreprocessor
 */
class PDFPreprocessorTest extends TestCase {

	private PDFPreprocessor $preprocessor;
	private ReflectionMethod $cleanHtml;
	private string $tmpDir;

	protected function setUp(): void {
		$this->preprocessor = new PDFPreprocessor();
		$this->cleanHtml    = new ReflectionMethod( PDFPreprocessor::class, 'cleanHtml' );

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
}
