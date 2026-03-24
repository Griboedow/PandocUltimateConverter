<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Integration;

use MediaWiki\Extension\PandocUltimateConverter\Tests\Fixtures\DocumentFixtureFactory;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests that invoke the real Pandoc binary to verify end-to-end
 * document conversion.
 *
 * All tests in this suite are automatically skipped when Pandoc is not found
 * in PATH or via the PANDOC_PATH environment variable, so the suite can run
 * in environments where Pandoc is not installed (e.g. basic PHP CI without
 * additional packages).
 *
 * To enable these tests install Pandoc and ensure it is in your PATH, or
 * set the PANDOC_PATH environment variable to the pandoc binary path.
 *
 * @group integration
 */
class ConversionIntegrationTest extends TestCase {

	private string $pandocBin;
	private string $tmpDir = '';

	protected function setUp(): void {
		$this->pandocBin = $this->findPandoc();
		if ( $this->pandocBin === '' ) {
			$this->markTestSkipped(
				'Pandoc is not installed or not in PATH. ' .
				'Set PANDOC_PATH env var or install pandoc to run integration tests.'
			);
		}

		$this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pandoc_integration_' . uniqid();
		mkdir( $this->tmpDir, 0755, true );
	}

	protected function tearDown(): void {
		if ( $this->tmpDir !== '' && is_dir( $this->tmpDir ) ) {
			$this->rmdirRecursive( $this->tmpDir );
		}
	}

	// ------------------------------------------------------------------
	// HTML → MediaWiki
	// ------------------------------------------------------------------

	public function testConvertHtmlToMediawiki(): void {
		$htmlFile  = $this->tmpDir . DIRECTORY_SEPARATOR . 'test.html';
		$mediaDir  = $this->tmpDir . DIRECTORY_SEPARATOR . 'media_html';
		mkdir( $mediaDir, 0755, true );

		DocumentFixtureFactory::createHtml( $htmlFile );

		$output = $this->runPandoc( [
			$this->pandocBin,
			'--from=html',
			'--to=mediawiki',
			'--extract-media=' . $mediaDir,
			$htmlFile,
		] );

		$this->assertStringContainsString( 'Test Heading', $output,
			'Heading should be present in wikitext output' );
		$this->assertStringContainsString( 'bold', $output,
			'Bold text should be preserved' );
		// Mediawiki table syntax
		$this->assertMatchesRegularExpression( '/\{\|.*wikitable|\{\|/si', $output,
			'Table syntax should be present' );
	}

	// ------------------------------------------------------------------
	// DOCX → MediaWiki
	// ------------------------------------------------------------------

	public function testConvertDocxToMediawiki(): void {
		$docxFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'test.docx';
		$mediaDir = $this->tmpDir . DIRECTORY_SEPARATOR . 'media_docx';
		mkdir( $mediaDir, 0755, true );

		DocumentFixtureFactory::createDocx( $docxFile );

		$output = $this->runPandoc( [
			$this->pandocBin,
			'--from=docx',
			'--to=mediawiki',
			'--extract-media=' . $mediaDir,
			$docxFile,
		] );

		$this->assertStringContainsString( 'Test Heading', $output,
			'Heading from DOCX should be in wikitext' );
		$this->assertStringContainsString( 'test paragraph', $output,
			'Paragraph text from DOCX should be in wikitext' );
	}

	// ------------------------------------------------------------------
	// ODT → MediaWiki
	// ------------------------------------------------------------------

	public function testConvertOdtToMediawiki(): void {
		$odtFile  = $this->tmpDir . DIRECTORY_SEPARATOR . 'test.odt';
		$mediaDir = $this->tmpDir . DIRECTORY_SEPARATOR . 'media_odt';
		mkdir( $mediaDir, 0755, true );

		DocumentFixtureFactory::createOdt( $odtFile );

		$output = $this->runPandoc( [
			$this->pandocBin,
			'--from=odt',
			'--to=mediawiki',
			'--extract-media=' . $mediaDir,
			$odtFile,
		] );

		$this->assertStringContainsString( 'Test Heading', $output,
			'Heading from ODT should be in wikitext' );
		$this->assertStringContainsString( 'test paragraph', $output,
			'Paragraph text from ODT should be in wikitext' );
	}

	// ------------------------------------------------------------------
	// DOCX → MediaWiki: output contains expected wikitext constructs
	// ------------------------------------------------------------------

	public function testDocxTableIsConvertedToWikitable(): void {
		$docxFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'table.docx';
		$mediaDir = $this->tmpDir . DIRECTORY_SEPARATOR . 'media_table';
		mkdir( $mediaDir, 0755, true );

		DocumentFixtureFactory::createDocx( $docxFile );

		$output = $this->runPandoc( [
			$this->pandocBin,
			'--from=docx',
			'--to=mediawiki',
			$docxFile,
		] );

		// WikiMedia table open/close syntax
		$this->assertMatchesRegularExpression( '/\{\|/', $output, 'Table opening brace expected' );
		$this->assertMatchesRegularExpression( '/\|\}/', $output, 'Table closing brace expected' );
		$this->assertStringContainsString( 'Column A', $output );
		$this->assertStringContainsString( 'Value 1',  $output );
	}

	// ------------------------------------------------------------------
	// PandocWrapper::invokePandoc integration
	// ------------------------------------------------------------------

	public function testInvokePandocReturnsPandocVersionString(): void {
		// A trivial smoke test: "pandoc --version" should exit 0 and contain "pandoc".
		$output = $this->runPandoc( [ $this->pandocBin, '--version' ] );
		$this->assertStringContainsString( 'pandoc', strtolower( $output ) );
	}

	public function testInvokePandocThrowsOnNonZeroExit(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Pandoc conversion failed/' );

		// Pass a non-existent file — Pandoc will exit non-zero.
		\MediaWiki\Extension\PandocUltimateConverter\PandocWrapper::invokePandoc( [
			$this->pandocBin,
			'--from=docx',
			'--to=mediawiki',
			'/nonexistent/path/file.docx',
		] );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Find the Pandoc binary path: first checks PANDOC_PATH env var, then PATH.
	 */
	private function findPandoc(): string {
		$envPath = getenv( 'PANDOC_PATH' );
		if ( $envPath !== false && $envPath !== '' && is_executable( $envPath ) ) {
			return $envPath;
		}

		// Try `which` / `where` on the current OS
		foreach ( [ 'pandoc', 'pandoc.exe' ] as $bin ) {
			$found = trim( (string) shell_exec( 'which ' . escapeshellarg( $bin ) . ' 2>/dev/null' ) );
			if ( $found !== '' && is_executable( $found ) ) {
				return $found;
			}
		}

		return '';
	}

	/**
	 * Execute a command and return stdout. Throws on non-zero exit.
	 *
	 * @param string[] $cmd
	 */
	private function runPandoc( array $cmd ): string {
		$escaped  = implode( ' ', array_map( 'escapeshellarg', $cmd ) );
		$output   = [];
		$exitCode = 0;
		exec( $escaped . ' 2>&1', $output, $exitCode );

		if ( $exitCode !== 0 ) {
			throw new \RuntimeException(
				'Pandoc conversion failed: ' . implode( "\n", $output )
			);
		}

		return implode( "\n", $output );
	}

	private function rmdirRecursive( string $dir ): void {
		if ( !is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $item ) {
			if ( $item === '.' || $item === '..' ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			is_dir( $path ) ? $this->rmdirRecursive( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}
}
