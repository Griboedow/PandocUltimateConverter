<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit;

use MediaWiki\Extension\PandocUltimateConverter\Processors\ODTColorPreprocessor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for the private helper methods of ODTColorPreprocessor.
 *
 * @covers \MediaWiki\Extension\PandocUltimateConverter\Processors\ODTColorPreprocessor
 */
class ODTColorPreprocessorTest extends TestCase {

	private ODTColorPreprocessor $preprocessor;
	private ReflectionMethod $normalizeColor;

	protected function setUp(): void {
		$this->preprocessor   = new ODTColorPreprocessor();
		$this->normalizeColor = new ReflectionMethod( ODTColorPreprocessor::class, 'normalizeColor' );
	}

	// ------------------------------------------------------------------
	// normalizeColor — ODT colours are already CSS #rrggbb
	// ------------------------------------------------------------------

	public function testNormalizeColorPassesThroughHashHexValue(): void {
		$this->assertSame( '#ff0000', $this->normalizeColor->invoke( $this->preprocessor, '#ff0000' ) );
	}

	public function testNormalizeColorPassesThroughArbitraryString(): void {
		// ODT colours should always be in CSS format; the method simply echoes input.
		$this->assertSame( '#1a2b3c', $this->normalizeColor->invoke( $this->preprocessor, '#1a2b3c' ) );
	}

	public function testNormalizeColorPassesThroughEmptyString(): void {
		$this->assertSame( '', $this->normalizeColor->invoke( $this->preprocessor, '' ) );
	}

	// ------------------------------------------------------------------
	// injectColorsIntoOutput — placeholder replacement
	// ------------------------------------------------------------------

	/**
	 * Use an anonymous subclass to expose the private placeholder map and
	 * call the private injection method.
	 */
	public function testInjectColorsIntoOutputReplacesInlinePlaceholder(): void {
		$preprocessor = new ODTColorPreprocessor();

		$prop = new \ReflectionProperty( ODTColorPreprocessor::class, 'colorPlaceholders' );
		$prop->setValue( $preprocessor, [
			'__COLOR_PLACEHOLDER_0__' => [
				'text'   => 'Red text',
				'colors' => [ 'color' => '#ff0000' ],
			],
		] );

		$injectColors = new ReflectionMethod( ODTColorPreprocessor::class, 'injectColorsIntoOutput' );
		$result = $injectColors->invoke( $preprocessor, 'Before __COLOR_PLACEHOLDER_0__ After' );

		$this->assertStringContainsString( '<span style="color:#ff0000">Red text</span>', $result );
		$this->assertStringNotContainsString( '__COLOR_PLACEHOLDER_0__', $result );
	}

	public function testInjectColorsIntoOutputAppliesTableCellStyle(): void {
		$preprocessor = new ODTColorPreprocessor();

		$prop = new \ReflectionProperty( ODTColorPreprocessor::class, 'colorPlaceholders' );
		$prop->setValue( $preprocessor, [
			'__COLOR_PLACEHOLDER_0__' => [
				'text'        => 'Cell text',
				'colors'      => [ 'background-color' => '#ffff00' ],
				'isTableCell' => true,
			],
		] );

		// Simulate the wikitext produced by Pandoc for a table cell
		$wikitableRow = "| __COLOR_PLACEHOLDER_0__";
		$injectColors = new ReflectionMethod( ODTColorPreprocessor::class, 'injectColorsIntoOutput' );
		$result       = $injectColors->invoke( $preprocessor, $wikitableRow );

		$this->assertStringContainsString( 'background-color:#ffff00', $result );
		$this->assertStringContainsString( 'Cell text', $result );
		$this->assertStringNotContainsString( '__COLOR_PLACEHOLDER_0__', $result );
	}

	public function testInjectColorsIntoOutputHandlesEmptyPlaceholderMap(): void {
		$preprocessor = new ODTColorPreprocessor();

		$prop = new \ReflectionProperty( ODTColorPreprocessor::class, 'colorPlaceholders' );
		$prop->setValue( $preprocessor, [] );

		$text = "== Heading ==\nSome text.";
		$injectColors = new ReflectionMethod( ODTColorPreprocessor::class, 'injectColorsIntoOutput' );
		$result = $injectColors->invoke( $preprocessor, $text );

		$this->assertSame( $text, $result );
	}
}
