<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\E2E;

use MediaWiki\Extension\PandocUltimateConverter\PandocWrapper;
use MediaWiki\Extension\PandocUltimateConverter\SpecialPages\SpecialPandocExport;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * End-to-end tests for the export pipeline.
 *
 * Each test converts a snippet of MediaWiki wikitext to a target format via
 * Pandoc, verifying that:
 *   1. The output file is produced.
 *   2. The file has the expected structure (valid ZIP for DOCX/ODT, %PDF magic for PDF).
 *   3. SpecialPandocExport::SUPPORTED_FORMATS lists the format being tested.
 *
 * The test for PDF export is skipped when no supported PDF engine is found.
 *
 * Requirements
 * ------------
 * All tests:  Pandoc in PATH (or PANDOC_PATH env var).
 * PDF export: one of xelatex, pdflatex, wkhtmltopdf, or weasyprint.
 *
 * Artifacts
 * ---------
 * Exported files are copied to E2E_ARTIFACTS_DIR (defaults to
 * /tmp/pandoc-e2e-artifacts) so that CI can upload them as proof.
 *
 * @group e2e
 */
class ExportE2ETest extends TestCase {

	private string $pandocBin;
	private string $tmpDir = '';
	private string $artifactsDir;

	/** Minimal wikitext used as export input for all tests. */
	private const WIKITEXT = <<<'WIKI'
= Export Test Heading =

This is '''bold''' and ''italic'' text.

* Item one
* Item two

{| class="wikitable"
! Column A !! Column B
|-
| Value 1  || Value 2
|}
WIKI;

	protected function setUp(): void {
		$this->pandocBin = $this->findBinary( 'pandoc' );
		if ( $this->pandocBin === '' ) {
			$this->markTestSkipped(
				'Pandoc is not installed or not in PATH. ' .
				'Set PANDOC_PATH env var or install pandoc to run e2e tests.'
			);
		}

		$this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pandoc_e2e_export_' . uniqid();
		mkdir( $this->tmpDir, 0755, true );

		$this->artifactsDir = (string) ( getenv( 'E2E_ARTIFACTS_DIR' ) ?: sys_get_temp_dir() . '/pandoc-e2e-artifacts' );
		if ( !is_dir( $this->artifactsDir ) ) {
			mkdir( $this->artifactsDir, 0755, true );
		}
	}

	protected function tearDown(): void {
		if ( $this->tmpDir !== '' && is_dir( $this->tmpDir ) ) {
			$this->rmdirRecursive( $this->tmpDir );
		}
	}

	// ------------------------------------------------------------------
	// 2.1  Export to DOCX
	// ------------------------------------------------------------------

	public function testExportToDocx(): void {
		$this->assertArrayHasKey(
			'docx',
			SpecialPandocExport::SUPPORTED_FORMATS,
			'docx must be listed in SpecialPandocExport::SUPPORTED_FORMATS'
		);

		$inputFile  = $this->writeWikitextFile( 'export_test.mediawiki' );
		$outputFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'export.docx';

		PandocWrapper::invokeShell( [
			$this->pandocBin,
			'--from=mediawiki',
			'--to=docx',
			'-o', $outputFile,
			$inputFile,
		] );

		$this->assertFileExists( $outputFile, 'Pandoc must produce a .docx output file' );
		$this->assertGreaterThan( 0, filesize( $outputFile ), 'DOCX output must not be empty' );

		// DOCX is a ZIP — verify the archive is valid
		$zip = new ZipArchive();
		$this->assertSame(
			true,
			$zip->open( $outputFile ),
			'The .docx output must be a valid ZIP archive'
		);
		// Check for the mandatory DOCX entry
		$this->assertNotFalse(
			$zip->locateName( 'word/document.xml' ),
			'The .docx archive must contain word/document.xml'
		);
		$zip->close();

		// Save artifact
		copy( $outputFile, $this->artifactsDir . '/export-output.docx' );
	}

	// ------------------------------------------------------------------
	// 2.2  Export to ODT
	// ------------------------------------------------------------------

	public function testExportToOdt(): void {
		$this->assertArrayHasKey(
			'odt',
			SpecialPandocExport::SUPPORTED_FORMATS,
			'odt must be listed in SpecialPandocExport::SUPPORTED_FORMATS'
		);

		$inputFile  = $this->writeWikitextFile( 'export_test.mediawiki' );
		$outputFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'export.odt';

		PandocWrapper::invokeShell( [
			$this->pandocBin,
			'--from=mediawiki',
			'--to=odt',
			'-o', $outputFile,
			$inputFile,
		] );

		$this->assertFileExists( $outputFile, 'Pandoc must produce a .odt output file' );
		$this->assertGreaterThan( 0, filesize( $outputFile ), 'ODT output must not be empty' );

		// ODT is a ZIP — verify the archive and its MIME type
		$zip = new ZipArchive();
		$this->assertSame(
			true,
			$zip->open( $outputFile ),
			'The .odt output must be a valid ZIP archive'
		);
		$mimeEntry = $zip->getFromName( 'mimetype' );
		$this->assertNotFalse( $mimeEntry, 'The .odt archive must contain a mimetype entry' );
		$this->assertStringContainsString(
			'opendocument.text',
			$mimeEntry,
			'The .odt mimetype entry must declare an ODF text document'
		);
		$zip->close();

		// Save artifact
		copy( $outputFile, $this->artifactsDir . '/export-output.odt' );
	}

	// ------------------------------------------------------------------
	// 2.3  Export to PDF
	// ------------------------------------------------------------------

	public function testExportToPdf(): void {
		$this->assertArrayHasKey(
			'pdf',
			SpecialPandocExport::SUPPORTED_FORMATS,
			'pdf must be listed in SpecialPandocExport::SUPPORTED_FORMATS'
		);

		[ $engine, $engineName ] = $this->findPdfEngine();
		if ( $engine === '' ) {
			$this->markTestSkipped(
				'No PDF engine found (tried xelatex, pdflatex, lualatex, wkhtmltopdf, weasyprint).'
			);
		}

		$inputFile  = $this->writeWikitextFile( 'export_test.mediawiki' );
		$outputFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'export.pdf';

		PandocWrapper::invokeShell( [
			$this->pandocBin,
			'--from=mediawiki',
			'--to=pdf',
			'--pdf-engine=' . $engineName,
			'-o', $outputFile,
			$inputFile,
		] );

		$this->assertFileExists( $outputFile, "Pandoc with $engineName must produce a .pdf output file" );
		$this->assertGreaterThan( 0, filesize( $outputFile ), 'PDF output must not be empty' );

		// Verify PDF magic bytes
		$magic = file_get_contents( $outputFile, false, null, 0, 4 );
		$this->assertSame( '%PDF', $magic, 'Output file must start with %PDF magic bytes' );

		// Save artifact
		copy( $outputFile, $this->artifactsDir . '/export-output.pdf' );
	}

	// ------------------------------------------------------------------
	// 2.4  Export action is available in the supported-formats registry
	// ------------------------------------------------------------------

	/**
	 * Verifies that SpecialPandocExport::SUPPORTED_FORMATS declares all the
	 * formats that the toolbar "Export" action is expected to offer.
	 *
	 * A separate MediaWiki smoke test (in .github/workflows/tests.yml) verifies
	 * that the hook handler actually adds the link to the page toolbar.
	 */
	public function testSupportedFormatsIncludesAllRequiredExportFormats(): void {
		$required = [ 'docx', 'odt', 'pdf', 'epub', 'html', 'rtf', 'txt' ];

		foreach ( $required as $format ) {
			$this->assertArrayHasKey(
				$format,
				SpecialPandocExport::SUPPORTED_FORMATS,
				"SpecialPandocExport::SUPPORTED_FORMATS must include the '$format' format"
			);
		}
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Write the shared WIKITEXT constant to a temp file and return its path.
	 */
	private function writeWikitextFile( string $name ): string {
		$path = $this->tmpDir . DIRECTORY_SEPARATOR . $name;
		file_put_contents( $path, self::WIKITEXT );
		return $path;
	}

	/**
	 * Find a PDF engine supported by Pandoc and return [binary path, engine name].
	 *
	 * @return array{string, string}
	 */
	private function findPdfEngine(): array {
		// Map from engine name (passed to --pdf-engine) to binary name (searched in PATH)
		$candidates = [
			'xelatex'     => 'xelatex',
			'pdflatex'    => 'pdflatex',
			'lualatex'    => 'lualatex',
			'wkhtmltopdf' => 'wkhtmltopdf',
			'weasyprint'  => 'weasyprint',
		];

		foreach ( $candidates as $engineName => $binaryName ) {
			$path = $this->findBinary( $binaryName );
			if ( $path !== '' ) {
				return [ $path, $engineName ];
			}
		}

		return [ '', '' ];
	}

	/**
	 * Locate a binary: first checks an env var like PANDOC_PATH, then PATH.
	 */
	private function findBinary( string $name ): string {
		$envKey  = strtoupper( str_replace( '-', '_', $name ) ) . '_PATH';
		$envPath = (string) getenv( $envKey );
		if ( $envPath !== '' && is_executable( $envPath ) ) {
			return $envPath;
		}

		$found = trim( (string) shell_exec( 'which ' . escapeshellarg( $name ) . ' 2>/dev/null' ) );
		return ( $found !== '' && is_executable( $found ) ) ? $found : '';
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
