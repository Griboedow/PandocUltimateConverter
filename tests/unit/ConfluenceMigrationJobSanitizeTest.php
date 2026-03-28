<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit;

use MediaWiki\Extension\PandocUltimateConverter\Jobs\ConfluenceMigrationJob;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for the Confluence HTML sanitisation logic in ConfluenceMigrationJob.
 *
 * @covers \MediaWiki\Extension\PandocUltimateConverter\Jobs\ConfluenceMigrationJob
 */
class ConfluenceMigrationJobSanitizeTest extends TestCase {

	private ReflectionMethod $sanitize;
	private ConfluenceMigrationJob $job;

	protected function setUp(): void {
		parent::setUp();

		// ConfluenceMigrationJob extends Job whose constructor needs a Title.
		$title = $this->createMock( \Title::class );
		$this->job = new ConfluenceMigrationJob( $title, [
			'confluenceUrl' => 'https://example.atlassian.net',
			'spaceKey'      => 'TEST',
			'apiUser'       => 'u',
			'apiToken'      => 't',
			'userId'        => 1,
		] );

		$this->sanitize = new ReflectionMethod( $this->job, 'sanitizeConfluenceHtml' );
		$this->sanitize->setAccessible( true );
	}

	private function sanitize( string $html ): string {
		return $this->sanitize->invoke( $this->job, $html );
	}

	// -------------------------------------------------------------------
	// ac:image with ri:attachment → <img>
	// -------------------------------------------------------------------

	public function testAttachmentImageConvertedToImgTag(): void {
		$input = '<p>Before <ac:image><ri:attachment ri:filename="screenshot.png" /></ac:image> after</p>';
		$result = $this->sanitize( $input );

		$this->assertStringContainsString( '<img src="screenshot.png" />', $result );
		$this->assertStringNotContainsString( 'ac:image', $result );
		$this->assertStringNotContainsString( 'ri:attachment', $result );
	}

	public function testAttachmentImageWithDimensionsPreserved(): void {
		$input = '<ac:image ac:width="400" ac:height="250"><ri:attachment ri:filename="diagram.png" /></ac:image>';
		$result = $this->sanitize( $input );

		$this->assertStringContainsString( 'src="diagram.png"', $result );
		$this->assertStringContainsString( 'width="400"', $result );
		$this->assertStringContainsString( 'height="250"', $result );
	}

	public function testAttachmentImageWithAltText(): void {
		$input = '<ac:image ac:alt="My screenshot"><ri:attachment ri:filename="shot.png" /></ac:image>';
		$result = $this->sanitize( $input );

		$this->assertStringContainsString( 'alt="My screenshot"', $result );
		$this->assertStringContainsString( 'src="shot.png"', $result );
	}

	public function testAttachmentImageWithClosingTag(): void {
		// Some Confluence versions use non-self-closing ri:attachment tags.
		$input = '<ac:image><ri:attachment ri:filename="photo.jpg"></ri:attachment></ac:image>';
		$result = $this->sanitize( $input );

		$this->assertStringContainsString( '<img src="photo.jpg" />', $result );
	}

	public function testAttachmentImageWithCrossPageReference(): void {
		// Image attached to another page — ri:attachment wraps ri:page.
		$input = '<ac:image><ri:attachment ri:filename="logo.png"><ri:page ri:content-title="Other Page" /></ri:attachment></ac:image>';
		$result = $this->sanitize( $input );

		$this->assertStringContainsString( 'src="logo.png"', $result );
	}

	public function testAttachmentImageWithVersionAttribute(): void {
		$input = '<ac:image><ri:attachment ri:filename="chart.png" ri:version-at-save="3" /></ac:image>';
		$result = $this->sanitize( $input );

		$this->assertStringContainsString( 'src="chart.png"', $result );
	}

	// -------------------------------------------------------------------
	// ac:image with ri:url → <img>
	// -------------------------------------------------------------------

	public function testUrlImageConvertedToImgTag(): void {
		$input = '<ac:image><ri:url ri:value="https://example.com/badge.svg" /></ac:image>';
		$result = $this->sanitize( $input );

		$this->assertStringContainsString( '<img src="https://example.com/badge.svg" />', $result );
	}

	public function testUrlImageWithDimensions(): void {
		$input = '<ac:image ac:width="200" ac:height="100"><ri:url ri:value="https://img.shields.io/badge.svg" /></ac:image>';
		$result = $this->sanitize( $input );

		$this->assertStringContainsString( 'width="200"', $result );
		$this->assertStringContainsString( 'height="100"', $result );
	}

	// -------------------------------------------------------------------
	// Generic stripping still works
	// -------------------------------------------------------------------

	public function testRemainingAcTagsAreStripped(): void {
		$input = '<ac:emoticon ac:name="smile" ac:emoji-id="1f600" />';
		$result = $this->sanitize( $input );

		$this->assertStringNotContainsString( 'ac:emoticon', $result );
	}

	public function testCodeMacroConvertedToPre(): void {
		$input = '<ac:structured-macro ac:name="code"><ac:plain-text-body>echo "hi";</ac:plain-text-body></ac:structured-macro>';
		$result = $this->sanitize( $input );

		$this->assertStringContainsString( '<pre>echo "hi";</pre>', $result );
	}

	public function testMultipleImagesInSameDocument(): void {
		$input = '<p><ac:image><ri:attachment ri:filename="a.png" /></ac:image></p>'
			. '<p><ac:image ac:width="500"><ri:attachment ri:filename="b.jpg" /></ac:image></p>'
			. '<p><ac:image><ri:url ri:value="https://example.com/c.gif" /></ac:image></p>';
		$result = $this->sanitize( $input );

		$this->assertStringContainsString( 'src="a.png"', $result );
		$this->assertStringContainsString( 'src="b.jpg"', $result );
		$this->assertStringContainsString( 'src="https://example.com/c.gif"', $result );
		$this->assertStringContainsString( 'width="500"', $result );
		$this->assertStringNotContainsString( 'ac:image', $result );
		$this->assertStringNotContainsString( 'ri:attachment', $result );
		$this->assertStringNotContainsString( 'ri:url', $result );
	}
}
