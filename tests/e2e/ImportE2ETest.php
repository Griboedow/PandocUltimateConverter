<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\E2E;

use MediaWiki\Extension\PandocUltimateConverter\PandocWrapper;
use MediaWiki\Extension\PandocUltimateConverter\Processors\DOCPreprocessor;
use MediaWiki\Extension\PandocUltimateConverter\Processors\DOCXColorPreprocessor;
use MediaWiki\Extension\PandocUltimateConverter\Processors\ODTColorPreprocessor;
use MediaWiki\Extension\PandocUltimateConverter\Processors\PDFPreprocessor;
use MediaWiki\Extension\PandocUltimateConverter\Tests\Fixtures\DocumentFixtureFactory;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests for the import pipeline.
 *
 * Each test exercises the full conversion path — from a raw source document,
 * through the appropriate processor, to a final MediaWiki wikitext string.
 *
 * Requirements
 * ------------
 * All tests:   Pandoc in PATH (or PANDOC_PATH env var).
 * PDF tests:   poppler-utils (pdftohtml, pdftotext).
 * OCR test:    poppler-utils + tesseract-ocr + ImageMagick (convert).
 * DOC/PPTX tests: LibreOffice (libreoffice / soffice).
 *
 * Missing tools cause the test to be skipped automatically.
 *
 * Artifacts
 * ---------
 * Each test writes its wikitext output to E2E_ARTIFACTS_DIR (defaults to
 * /tmp/pandoc-e2e-artifacts) so that CI can upload them as proof-of-conversion.
 *
 * @group e2e
 */
class ImportE2ETest extends TestCase {

	private string $pandocBin;
	private string $tmpDir = '';
	private string $artifactsDir;

	protected function setUp(): void {
		$this->pandocBin = $this->findBinary( 'pandoc' );
		if ( $this->pandocBin === '' ) {
			$this->markTestSkipped(
				'Pandoc is not installed or not in PATH. ' .
				'Set PANDOC_PATH env var or install pandoc to run e2e tests.'
			);
		}

		$this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pandoc_e2e_import_' . uniqid();
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
	// 1.1  DOCX — colour preservation
	// ------------------------------------------------------------------

	public function testDocxColorPreservationE2E(): void {
		$docxFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'colored.docx';
		DocumentFixtureFactory::createColoredDocx( $docxFile );

		$preprocessor = new DOCXColorPreprocessor( $this->pandocBin );
		$output       = $preprocessor->processDOCXFile( $docxFile );

		// Save artifact ("screenshot")
		file_put_contents( $this->artifactsDir . '/import-docx-color.mediawiki', $output );

		$this->assertStringContainsString(
			'red text',
			$output,
			'Red-text run should appear in the wikitext output'
		);
		$this->assertMatchesRegularExpression(
			'/color\s*:\s*#ff0000/i',
			$output,
			'Red colour span (color: #ff0000) should be injected for the w:color FF0000 run'
		);
		$this->assertStringContainsString(
			'highlighted text',
			$output,
			'Highlighted run should appear in the wikitext output'
		);
		$this->assertMatchesRegularExpression(
			'/background[-_]color\s*[=:]\s*#ffff00/i',
			$output,
			'Yellow highlight background should be preserved in the output'
		);
	}

	// ------------------------------------------------------------------
	// 1.1  ODT — colour preservation
	// ------------------------------------------------------------------

	public function testOdtColorPreservationE2E(): void {
		$odtFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'colored.odt';
		DocumentFixtureFactory::createColoredOdt( $odtFile );

		$preprocessor = new ODTColorPreprocessor( $this->pandocBin );
		$output       = $preprocessor->processODTFile( $odtFile );

		// Save artifact
		file_put_contents( $this->artifactsDir . '/import-odt-color.mediawiki', $output );

		$this->assertStringContainsString(
			'red text',
			$output,
			'Red-text span should appear in the wikitext output'
		);
		$this->assertStringContainsString(
			'#ff0000',
			$output,
			'Red colour value (#ff0000) should be present in the output'
		);
		$this->assertStringContainsString(
			'highlighted text',
			$output,
			'Yellow-background span should appear in the wikitext output'
		);
		$this->assertStringContainsString(
			'#ffff00',
			$output,
			'Yellow background colour (#ffff00) should be present in the output'
		);
	}

	// ------------------------------------------------------------------
	// 1.2  PDF import — text-based PDF
	// ------------------------------------------------------------------

	public function testPdfTextImportE2E(): void {
		$pdftohtml = $this->findBinary( 'pdftohtml' );
		$pdftotext = $this->findBinary( 'pdftotext' );
		if ( $pdftohtml === '' || $pdftotext === '' ) {
			$this->markTestSkipped(
				'poppler-utils (pdftohtml + pdftotext) are required for the PDF text import test.'
			);
		}

		$pdfFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'text.pdf';
		DocumentFixtureFactory::createTextPdf( $pdfFile );
		$this->assertFileExists( $pdfFile, 'Text-PDF fixture should be created' );

		$preprocessor = new PDFPreprocessor( $pdftohtml, $pdftotext );

		// Verify the fixture is detected as a text-based (not scanned) PDF
		$this->assertFalse(
			$preprocessor->isScannedPdf( $pdfFile ),
			'The text-PDF fixture must not be classified as scanned'
		);

		// Step 1: pdftohtml → HTML file
		$htmlFile = $preprocessor->processPDFFile( $pdfFile, $this->tmpDir );
		$this->assertFileExists( $htmlFile, 'pdftohtml should produce an HTML file' );

		// Step 2: HTML → MediaWiki wikitext via Pandoc
		$wikitextOutput = PandocWrapper::invokeShell( [
			$this->pandocBin,
			'--from=html',
			'--to=mediawiki',
			$htmlFile,
		] );

		// Save artifact
		file_put_contents( $this->artifactsDir . '/import-pdf-text.mediawiki', $wikitextOutput );

		$this->assertNotEmpty( $wikitextOutput, 'Text-PDF import should produce non-empty wikitext' );
	}

	// ------------------------------------------------------------------
	// 1.2  PDF import — OCR (scanned / image-only PDF)
	// ------------------------------------------------------------------

	public function testPdfOcrImportE2E(): void {
		$pdftotext = $this->findBinary( 'pdftotext' );
		$pdftoppm  = $this->findBinary( 'pdftoppm' );
		$tesseract = $this->findBinary( 'tesseract' );
		$convert   = $this->findBinary( 'convert' ); // ImageMagick

		if ( $pdftotext === '' || $pdftoppm === '' || $tesseract === '' || $convert === '' ) {
			$this->markTestSkipped(
				'poppler-utils, tesseract-ocr, and ImageMagick (convert) are all required for the OCR test.'
			);
		}

		// Build an image-only PDF (no text layer) using ImageMagick
		$scannedPdf = $this->tmpDir . DIRECTORY_SEPARATOR . 'scanned.pdf';
		$this->createImageOnlyPdf( $scannedPdf, $convert );
		$this->assertFileExists( $scannedPdf, 'ImageMagick should create a scanned PDF' );

		$preprocessor = new PDFPreprocessor(
			$this->findBinary( 'pdftohtml' ),
			$pdftotext,
			$pdftoppm,
			$tesseract
		);

		// The image-only PDF must be detected as scanned
		$this->assertTrue(
			$preprocessor->isScannedPdf( $scannedPdf ),
			'An image-only PDF must be classified as scanned'
		);

		// OCR pipeline
		$wikitextOutput = $preprocessor->processScannedPdfFile( $scannedPdf, $this->tmpDir );

		// Save artifact
		file_put_contents( $this->artifactsDir . '/import-pdf-ocr.mediawiki', $wikitextOutput );

		$this->assertNotEmpty( $wikitextOutput, 'OCR should produce some wikitext output' );
	}

	// ------------------------------------------------------------------
	// 1.3  DOC import (LibreOffice required)
	// ------------------------------------------------------------------

	public function testDocImportE2E(): void {
		$libreoffice = $this->findLibreOffice();
		if ( $libreoffice === '' ) {
			$this->markTestSkipped( 'LibreOffice (libreoffice / soffice) is required for the DOC import test.' );
		}

		// Create a base DOCX and convert it to legacy DOC using LibreOffice
		$docxFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'source.docx';
		DocumentFixtureFactory::createDocx( $docxFile );

		$docFile = $this->convertDocxToDoc( $docxFile, $libreoffice );
		if ( $docFile === '' ) {
			$this->markTestSkipped( 'LibreOffice could not produce a .doc file from the DOCX fixture.' );
		}

		// Use DOCPreprocessor to convert .doc → .docx (the production code path)
		$preprocessor   = new DOCPreprocessor( $libreoffice );
		$resultDocxPath = $preprocessor->convertToDocx( $docFile, $this->tmpDir );
		$this->assertFileExists( $resultDocxPath, 'DOCPreprocessor must produce a .docx file' );

		// Convert the resulting DOCX to MediaWiki wikitext
		$wikitextOutput = PandocWrapper::invokeShell( [
			$this->pandocBin,
			'--from=docx',
			'--to=mediawiki',
			$resultDocxPath,
		] );

		// Save artifact
		file_put_contents( $this->artifactsDir . '/import-doc.mediawiki', $wikitextOutput );

		$this->assertStringContainsString(
			'Test Heading',
			$wikitextOutput,
			'Heading from the DOC fixture should appear in the wikitext output'
		);
		$this->assertStringContainsString(
			'test paragraph',
			$wikitextOutput,
			'Body text from the DOC fixture should appear in the wikitext output'
		);
	}

	// ------------------------------------------------------------------
	// 1.4  PPTX import (LibreOffice required)
	// ------------------------------------------------------------------

	public function testPptxImportE2E(): void {
		$libreoffice = $this->findLibreOffice();
		if ( $libreoffice === '' ) {
			$this->markTestSkipped( 'LibreOffice (libreoffice / soffice) is required for the PPTX import test.' );
		}

		// Create a base DOCX fixture and convert it to PPTX via LibreOffice.
		$docxFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'slides-source.docx';
		DocumentFixtureFactory::createDocx( $docxFile );

		$pptxFile = $this->convertDocxToPptx( $docxFile, $libreoffice );
		if ( $pptxFile === '' ) {
			$this->markTestSkipped( 'LibreOffice could not produce a .pptx file from the DOCX fixture.' );
		}

		// Use DOCPreprocessor to convert .pptx → .docx (the production code path).
		$preprocessor   = new DOCPreprocessor( $libreoffice );
		$resultDocxPath = $preprocessor->convertToDocx( $pptxFile, $this->tmpDir );
		$this->assertFileExists( $resultDocxPath, 'DOCPreprocessor must produce a .docx file from PPTX' );

		$wikitextOutput = PandocWrapper::invokeShell( [
			$this->pandocBin,
			'--from=docx',
			'--to=mediawiki',
			$resultDocxPath,
		] );

		// Save artifact
		file_put_contents( $this->artifactsDir . '/import-pptx.mediawiki', $wikitextOutput );

		$this->assertStringContainsString(
			'Test Heading',
			$wikitextOutput,
			'Heading from the PPTX fixture should appear in the wikitext output'
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

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

	/**
	 * Find LibreOffice binary (may be "libreoffice" or "soffice").
	 */
	private function findLibreOffice(): string {
		foreach ( [ 'libreoffice', 'soffice' ] as $name ) {
			$path = $this->findBinary( $name );
			if ( $path !== '' ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Create an image-only PDF using ImageMagick (no text extraction layer).
	 */
	private function createImageOnlyPdf( string $destPdf, string $convert ): void {
		$pngPath = $this->tmpDir . DIRECTORY_SEPARATOR . 'scanned_page.png';

		// Try with a font; fall back to fontless if ImageMagick can't find the font
		$textDrawCmds = [
			[ '-font', 'DejaVu-Sans',  '-draw', 'text 0,0 "OCR Test Page"' ],
			[ '-font', 'Helvetica',    '-draw', 'text 0,0 "OCR Test Page"' ],
			[ '-draw', 'text 100,150 "OCR Test Page"' ],
		];

		foreach ( $textDrawCmds as $extra ) {
			$cmd = array_merge(
				[ $convert, '-size', '1000x300', 'xc:white', '-pointsize', '36', '-fill', 'black', '-gravity', 'Center' ],
				$extra,
				[ $pngPath ]
			);
			exec( implode( ' ', array_map( 'escapeshellarg', $cmd ) ) . ' 2>/dev/null', $unusedOutput, $code );
			if ( $code === 0 && file_exists( $pngPath ) ) {
				break;
			}
		}

		if ( !file_exists( $pngPath ) ) {
			// Last resort: use GD to create a white PNG
			if ( function_exists( 'imagecreate' ) ) {
				$img   = imagecreate( 1000, 300 );
				$white = imagecolorallocate( $img, 255, 255, 255 );
				imagefilledrectangle( $img, 0, 0, 999, 299, $white );
				imagepng( $img, $pngPath );
				imagedestroy( $img );
			}
		}

		if ( file_exists( $pngPath ) ) {
			exec( implode( ' ', array_map( 'escapeshellarg', [ $convert, $pngPath, $destPdf ] ) ) . ' 2>/dev/null' );
		}
	}

	/**
	 * Use LibreOffice headless to convert DOCX → DOC.
	 */
	private function convertDocxToDoc( string $docxPath, string $libreoffice ): string {
		$profileDir = $this->tmpDir . DIRECTORY_SEPARATOR . '.lo_prep';
		@mkdir( $profileDir, 0755, true );
		$profileUrl = 'file:///' . str_replace( '\\', '/', $profileDir );

		$cmd = [
			$libreoffice,
			'-env:UserInstallation=' . $profileUrl,
			'--headless',
			'--convert-to', 'doc',
			'--outdir', $this->tmpDir,
			$docxPath,
		];
		exec( implode( ' ', array_map( 'escapeshellarg', $cmd ) ) . ' 2>/dev/null' );

		$docPath = $this->tmpDir . DIRECTORY_SEPARATOR . pathinfo( $docxPath, PATHINFO_FILENAME ) . '.doc';
		return file_exists( $docPath ) ? $docPath : '';
	}

	/**
	 * Use LibreOffice headless to convert DOCX → PPTX.
	 */
	private function convertDocxToPptx( string $docxPath, string $libreoffice ): string {
		$profileDir = $this->tmpDir . DIRECTORY_SEPARATOR . '.lo_prep_pptx';
		@mkdir( $profileDir, 0755, true );
		$profileUrl = 'file:///' . str_replace( '\\', '/', $profileDir );

		$cmd = [
			$libreoffice,
			'-env:UserInstallation=' . $profileUrl,
			'--headless',
			'--convert-to', 'pptx',
			'--outdir', $this->tmpDir,
			$docxPath,
		];
		exec( implode( ' ', array_map( 'escapeshellarg', $cmd ) ) . ' 2>/dev/null' );

		$pptxPath = $this->tmpDir . DIRECTORY_SEPARATOR . pathinfo( $docxPath, PATHINFO_FILENAME ) . '.pptx';
		return file_exists( $pptxPath ) ? $pptxPath : '';
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
