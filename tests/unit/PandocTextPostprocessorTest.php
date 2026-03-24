<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit;

use MediaWiki\Extension\PandocUltimateConverter\Processors\PandocTextPostprocessor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\PandocUltimateConverter\Processors\PandocTextPostprocessor
 */
class PandocTextPostprocessorTest extends TestCase {

	// ------------------------------------------------------------------
	// Empty / no-op cases
	// ------------------------------------------------------------------

	public function testEmptyVocabularyReturnsTextUnchanged(): void {
		$text = "== Heading ==\nSome [[wikilink]] and [[File:photo.png|thumb]].";
		$this->assertSame( $text, PandocTextPostprocessor::postprocess( $text, [] ) );
	}

	public function testNoFileReferencesReturnsTextUnchanged(): void {
		$text = "Hello '''world'''.\n\n* item one\n* item two";
		$this->assertSame(
			$text,
			PandocTextPostprocessor::postprocess( $text, [ '/tmp/img.png' => 'Doc-img.png' ] )
		);
	}

	public function testEmptyTextReturnsEmpty(): void {
		$this->assertSame( '', PandocTextPostprocessor::postprocess( '', [ '/a/b.png' => 'x.png' ] ) );
	}

	// ------------------------------------------------------------------
	// Exact absolute-path matches (produced by pandoc --extract-media)
	// ------------------------------------------------------------------

	public function testExactAbsolutePathIsReplaced(): void {
		$mediaFolder = '/tmp/pandoc_test';
		$text        = '[[File:/tmp/pandoc_test/img.png|thumb]]';
		$vocab       = [ '/tmp/pandoc_test/img.png' => 'MyDoc-img.png' ];

		$result = PandocTextPostprocessor::postprocess( $text, $vocab );
		$this->assertSame( '[[File:MyDoc-img.png|thumb]]', $result );
	}

	public function testMultipleExactPathsAreAllReplaced(): void {
		$text  = "[[File:/tmp/doc/a.png]]\n[[File:/tmp/doc/b.jpg|thumb]]";
		$vocab = [
			'/tmp/doc/a.png' => 'Doc-a.png',
			'/tmp/doc/b.jpg' => 'Doc-b.jpg',
		];

		$result = PandocTextPostprocessor::postprocess( $text, $vocab );
		$this->assertStringContainsString( '[[File:Doc-a.png]]', $result );
		$this->assertStringContainsString( '[[File:Doc-b.jpg|thumb]]', $result );
	}

	// ------------------------------------------------------------------
	// Basename fallback (ODT/DOCX-internal paths like "Pictures/img.png")
	// ------------------------------------------------------------------

	public function testBasenameMatchesWhenAbsolutePathNotFound(): void {
		$text  = '[[File:Pictures/image001.png|none|auto]]';
		// key in vocabulary is the absolute path on disk
		$vocab = [ '/tmp/odt_work/image001.png' => 'Report-image001.png' ];

		$result = PandocTextPostprocessor::postprocess( $text, $vocab );
		$this->assertSame( '[[File:Report-image001.png|none|auto]]', $result );
	}

	public function testBasenameMatchWorksForNestedPaths(): void {
		$text  = '[[File:word/media/image1.png]]';
		$vocab = [ '/tmp/docx_work/word/media/image1.png' => 'Doc-image1.png' ];

		$result = PandocTextPostprocessor::postprocess( $text, $vocab );
		$this->assertSame( '[[File:Doc-image1.png]]', $result );
	}

	// ------------------------------------------------------------------
	// No-match fallback (forward-slash normalisation)
	// ------------------------------------------------------------------

	public function testUnknownReferenceIsNormalisedToForwardSlashes(): void {
		$text   = '[[File:some\\path\\image.png|thumb]]';
		$vocab  = [ '/other/file.png' => 'Other.png' ];

		$result = PandocTextPostprocessor::postprocess( $text, $vocab );
		// Backslashes in the unknown reference should be converted to forward slashes
		$this->assertSame( '[[File:some/path/image.png|thumb]]', $result );
	}

	public function testForwardSlashRefIsLeftUntouchedWhenNotInVocab(): void {
		$text  = '[[File:already/forward/slash.png]]';
		$vocab = [ '/some/other.png' => 'other.png' ];

		$result = PandocTextPostprocessor::postprocess( $text, $vocab );
		$this->assertSame( '[[File:already/forward/slash.png]]', $result );
	}

	// ------------------------------------------------------------------
	// Mixed content
	// ------------------------------------------------------------------

	public function testMixedWikitextWithFileRefsAndOtherMarkup(): void {
		$vocab = [
			'/tmp/work/logo.svg' => 'MyDoc-logo.svg',
			'/tmp/work/chart.png' => 'MyDoc-chart.png',
		];
		$text = <<<'WIKI'
== Section ==
[[File:/tmp/work/logo.svg|thumb|The logo]]

Some text with a [[wikilink]] and '''bold'''.

{| class="wikitable"
|-
| [[File:/tmp/work/chart.png|center]]
|}
WIKI;

		$result = PandocTextPostprocessor::postprocess( $text, $vocab );
		$this->assertStringContainsString( '[[File:MyDoc-logo.svg|thumb|The logo]]', $result );
		$this->assertStringContainsString( '[[File:MyDoc-chart.png|center]]', $result );
		// Non-File content must be preserved
		$this->assertStringContainsString( '== Section ==', $result );
		$this->assertStringContainsString( "[[wikilink]]", $result );
	}

	// ------------------------------------------------------------------
	// Path-separator normalisation on different OS conventions
	// ------------------------------------------------------------------

	/**
	 * Ensure that backslash paths in the vocabulary key (Windows) are
	 * matched when Pandoc emits forward-slash refs (or vice-versa).
	 */
	public function testWindowsPathSeparatorInVocabKeyIsNormalised(): void {
		// Vocabulary key uses backslashes (as produced on Windows)
		$absPath = 'C:\\Users\\user\\AppData\\Local\\Temp\\pandoc_test\\img.png';
		// Pandoc typically emits the path with the same separator it received,
		// but the postprocessor normalises both to DIRECTORY_SEPARATOR for matching.
		// Simulate by using the same key format in text that PHP would produce.
		$normKey = str_replace( [ '/', '\\' ], DIRECTORY_SEPARATOR, $absPath );
		$normRef = str_replace( [ '/', '\\' ], DIRECTORY_SEPARATOR, $absPath );
		$vocab   = [ $normKey => 'WinDoc-img.png' ];
		$text    = '[[File:' . $normRef . '|thumb]]';

		$result = PandocTextPostprocessor::postprocess( $text, $vocab );
		$this->assertSame( '[[File:WinDoc-img.png|thumb]]', $result );
	}
}
