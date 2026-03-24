<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit;

use MediaWiki\Extension\PandocUltimateConverter\Processors\DOCXColorPreprocessor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for the private helper methods of DOCXColorPreprocessor.
 *
 * These methods perform pure string/color-mapping operations and are exercised
 * via reflection so they can be verified in isolation without needing a real
 * DOCX file or a running Pandoc binary.
 *
 * @covers \MediaWiki\Extension\PandocUltimateConverter\Processors\DOCXColorPreprocessor
 */
class DOCXColorPreprocessorTest extends TestCase {

	private DOCXColorPreprocessor $preprocessor;
	private ReflectionMethod $mapDOCXColor;
	private ReflectionMethod $normalizeDOCXColor;

	protected function setUp(): void {
		$this->preprocessor       = new DOCXColorPreprocessor();
		$this->mapDOCXColor       = new ReflectionMethod( DOCXColorPreprocessor::class, 'mapDOCXColor' );
		$this->normalizeDOCXColor = new ReflectionMethod( DOCXColorPreprocessor::class, 'normalizeDOCXColor' );
	}

	// ------------------------------------------------------------------
	// mapDOCXColor — named colours
	// ------------------------------------------------------------------

	/** @dataProvider namedColorProvider */
	public function testMapDOCXColorKnownNames( string $docxName, string $expectedCss ): void {
		$result = $this->mapDOCXColor->invoke( $this->preprocessor, $docxName );
		$this->assertSame( $expectedCss, $result );
	}

	public static function namedColorProvider(): array {
		return [
			[ 'yellow',      '#ffff00' ],
			[ 'green',       '#00ff00' ],
			[ 'cyan',        '#00ffff' ],
			[ 'magenta',     '#ff00ff' ],
			[ 'blue',        '#0000ff' ],
			[ 'red',         '#ff0000' ],
			[ 'darkBlue',    '#000080' ],
			[ 'darkCyan',    '#008080' ],
			[ 'darkGreen',   '#008000' ],
			[ 'darkMagenta', '#800080' ],
			[ 'darkRed',     '#800000' ],
			[ 'darkYellow',  '#808000' ],
			[ 'darkGray',    '#808080' ],
			[ 'lightGray',   '#c0c0c0' ],
			[ 'black',       '#000000' ],
			[ 'white',       '#ffffff' ],
		];
	}

	public function testMapDOCXColorUnknownNameReturnsHashPrefixedInput(): void {
		$result = $this->mapDOCXColor->invoke( $this->preprocessor, 'FF5733' );
		$this->assertSame( '#FF5733', $result );
	}

	public function testMapDOCXColorArbitraryHexStringReturnsHashPrefixed(): void {
		$result = $this->mapDOCXColor->invoke( $this->preprocessor, 'aabbcc' );
		$this->assertSame( '#aabbcc', $result );
	}

	// ------------------------------------------------------------------
	// normalizeDOCXColor
	// ------------------------------------------------------------------

	public function testNormalizeDOCXColorAutoReturnsEmpty(): void {
		$this->assertSame( '', $this->normalizeDOCXColor->invoke( $this->preprocessor, 'auto' ) );
	}

	public function testNormalizeDOCXColorEmptyStringReturnsEmpty(): void {
		$this->assertSame( '', $this->normalizeDOCXColor->invoke( $this->preprocessor, '' ) );
	}

	public function testNormalizeDOCXColorValidHexAddsHashPrefix(): void {
		$this->assertSame( '#1a2b3c', $this->normalizeDOCXColor->invoke( $this->preprocessor, '1a2b3c' ) );
	}

	public function testNormalizeDOCXColorUppercaseHexAddsHashPrefix(): void {
		$this->assertSame( '#AABBCC', $this->normalizeDOCXColor->invoke( $this->preprocessor, 'AABBCC' ) );
	}

	public function testNormalizeDOCXColorNonHexValuePassedThrough(): void {
		// Shouldn't normally appear in a real DOCX, but the method should handle it gracefully.
		$result = $this->normalizeDOCXColor->invoke( $this->preprocessor, 'notahex' );
		$this->assertSame( 'notahex', $result );
	}

	// ------------------------------------------------------------------
	// injectColors — placeholder replacement in wikitext
	// ------------------------------------------------------------------

	/**
	 * Verify that inline colour spans are injected into simple wikitext.
	 *
	 * This test exposes the protected state via a test-subclass to avoid
	 * needing a full DOCX extraction, while still exercising the real
	 * injectColors() logic through a reflection call.
	 */
	public function testInjectColorsReplacesInlinePlaceholders(): void {
		$preprocessor = new DOCXColorPreprocessor();

		// Use reflection to set the private colorPlaceholders property
		$prop = new \ReflectionProperty( DOCXColorPreprocessor::class, 'colorPlaceholders' );
		$prop->setValue( $preprocessor, [
			'__DOCX_COLOR_PLACEHOLDER_0__' => [
				'text'   => 'Hello',
				'colors' => [ 'color' => '#ff0000' ],
			],
		] );

		$injectColors = new ReflectionMethod( DOCXColorPreprocessor::class, 'injectColors' );
		$input  = 'Before __DOCX_COLOR_PLACEHOLDER_0__ After';
		$result = $injectColors->invoke( $preprocessor, $input );

		$this->assertStringContainsString( '<span style="color: #ff0000">Hello</span>', $result );
		$this->assertStringNotContainsString( '__DOCX_COLOR_PLACEHOLDER_0__', $result );
	}

	public function testInjectColorsReplacesBackgroundSpan(): void {
		$preprocessor = new DOCXColorPreprocessor();

		$prop = new \ReflectionProperty( DOCXColorPreprocessor::class, 'colorPlaceholders' );
		$prop->setValue( $preprocessor, [
			'__DOCX_COLOR_PLACEHOLDER_0__' => [
				'text'   => 'Highlighted',
				'colors' => [ 'background' => '#ffff00' ],
			],
		] );

		$injectColors = new ReflectionMethod( DOCXColorPreprocessor::class, 'injectColors' );
		$result = $injectColors->invoke( $preprocessor, '__DOCX_COLOR_PLACEHOLDER_0__' );
		$this->assertStringContainsString( 'background-color: #ffff00', $result );
		$this->assertStringContainsString( 'Highlighted', $result );
	}
}
