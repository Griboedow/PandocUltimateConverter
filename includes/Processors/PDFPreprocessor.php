<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Processors;

use MediaWiki\Shell\Shell;

/**
 * Preprocesses PDF files before handing off to Pandoc for conversion to
 * MediaWiki wikitext.
 *
 * Text-based PDF pipeline:
 *   PDF → pdftohtml → HTML + PNG images → Pandoc → mediawiki wikitext
 *
 * Scanned PDF pipeline:
 *   PDF → pdftoppm (page images) → tesseract OCR (per page) → mediawiki wikitext
 */
class PDFPreprocessor {

	private string $pdfToHtmlPath;
	private string $pdfToTextPath;
	private string $pdftoppmPath;
	private string $tesseractPath;
	private string $ocrLanguage;

	/** Minimum number of non-whitespace characters per page to consider a PDF text-based. */
	private const TEXT_CHARS_PER_PAGE_THRESHOLD = 50;

	/**
	 * @param string $pdfToHtmlPath  Absolute path (or bare command name) for poppler's pdftohtml.
	 * @param string $pdfToTextPath  Absolute path (or bare command name) for poppler's pdftotext.
	 * @param string $pdftoppmPath   Absolute path (or bare command name) for poppler's pdftoppm.
	 * @param string $tesseractPath  Absolute path (or bare command name) for tesseract OCR.
	 * @param string $ocrLanguage    Tesseract language code(s), e.g. 'eng' or 'eng+deu'.
	 */
	public function __construct(
		string $pdfToHtmlPath = 'pdftohtml',
		string $pdfToTextPath = 'pdftotext',
		string $pdftoppmPath = 'pdftoppm',
		string $tesseractPath = 'tesseract',
		string $ocrLanguage = 'eng'
	) {
		$this->pdfToHtmlPath = $pdfToHtmlPath;
		$this->pdfToTextPath = $pdfToTextPath;
		$this->pdftoppmPath  = $pdftoppmPath;
		$this->tesseractPath = $tesseractPath;
		$this->ocrLanguage   = $ocrLanguage;
	}

	/**
	 * Determine whether a PDF contains extractable text or is a scanned image.
	 *
	 * Uses pdftotext to extract raw text and counts non-whitespace characters.
	 * If the average falls below TEXT_CHARS_PER_PAGE_THRESHOLD per page the PDF
	 * is classified as scanned.
	 *
	 * @param string $pdfPath Absolute path to the PDF file.
	 * @return bool True if the PDF appears to be a scanned (image-only) document.
	 */
	public function isScannedPdf( string $pdfPath ): bool {
		$cmd = [
			$this->pdfToTextPath,
			'-q',        // Suppress status/warning messages
			$pdfPath,
			'-',         // Output to stdout
		];

		wfDebugLog(
			'PandocUltimateConverter',
			'PDFPreprocessor::isScannedPdf: running ' . implode( ' ', $cmd )
		);

		$result = Shell::command( $cmd )
			->includeStderr()
			->execute();

		// pdftotext exits 0 even for scanned PDFs (just produces empty output)
		$text = $result->getStdout();

		$isScanned = $this->classifyTextAsScanned( $text );

		wfDebugLog(
			'PandocUltimateConverter',
			"PDFPreprocessor::isScannedPdf: scanned=" . ( $isScanned ? 'yes' : 'no' )
		);

		return $isScanned;
	}

	/**
	 * Process a scanned PDF using OCR via tesseract.
	 *
	 * Pipeline:
	 *  1. pdftoppm converts each page to a high-resolution PNG image.
	 *  2. tesseract runs OCR on every page image.
	 *  3. Per-page texts are assembled into MediaWiki wikitext directly.
	 *
	 * @param string $pdfPath     Absolute path to the source PDF file.
	 * @param string $mediaFolder Absolute path to a writable work directory.
	 * @return string MediaWiki wikitext produced by OCR.
	 * @throws \RuntimeException If pdftoppm or tesseract fails.
	 */
	public function processScannedPdfFile( string $pdfPath, string $mediaFolder ): string {
		// Step 1: Render PDF pages to images.
		$pagePrefix = $mediaFolder . DIRECTORY_SEPARATOR . 'ocr_page';

		$ppmCmd = [
			$this->pdftoppmPath,
			'-r', '300',      // 300 DPI gives good OCR accuracy
			'-png',           // Output as PNG
			$pdfPath,
			$pagePrefix,
		];

		wfDebugLog(
			'PandocUltimateConverter',
			'PDFPreprocessor::processScannedPdfFile: running ' . implode( ' ', $ppmCmd )
		);

		$ppmResult = Shell::command( $ppmCmd )
			->includeStderr()
			->execute();

		if ( $ppmResult->getExitCode() !== 0 ) {
			throw new \RuntimeException(
				'pdftoppm failed (exit ' . $ppmResult->getExitCode() . '): '
				. $ppmResult->getStdout()
			);
		}

		// Collect the generated page images (sorted so pages are in order).
		$pageImages = glob( $pagePrefix . '-*.png' );
		if ( empty( $pageImages ) ) {
			// Some pdftoppm versions omit the trailing dash when there is only one page.
			$pageImages = glob( $pagePrefix . '.png' );
		}
		if ( empty( $pageImages ) ) {
			throw new \RuntimeException(
				'pdftoppm produced no PNG images for: ' . $pdfPath
			);
		}
		sort( $pageImages );

		// Step 2: Run tesseract on each page image and collect the text.
		$allPageTexts = [];
		foreach ( $pageImages as $pageImage ) {
			$allPageTexts[] = $this->ocrPageImage( $pageImage );
		}

		// Step 3: Assemble plain-text OCR output into MediaWiki wikitext.
		$wikitext = $this->assembleWikitextFromPageTexts( $allPageTexts );

		wfDebugLog(
			'PandocUltimateConverter',
			'PDFPreprocessor::processScannedPdfFile: OCR complete, wikitext length=' . strlen( $wikitext )
		);

		return $wikitext;
	}

	/**
	 * Run tesseract OCR on a single image file and return the recognized text.
	 *
	 * @param string $imagePath Absolute path to the image file.
	 * @return string Plain text extracted by OCR.
	 * @throws \RuntimeException If tesseract exits with a non-zero status.
	 */
	private function ocrPageImage( string $imagePath ): string {
		// tesseract <input> stdout -l <lang> txt  → writes result to stdout
		$cmd = [
			$this->tesseractPath,
			$imagePath,
			'stdout',
			'-l', $this->ocrLanguage,
			'txt',
		];

		wfDebugLog(
			'PandocUltimateConverter',
			'PDFPreprocessor::ocrPageImage: running ' . implode( ' ', $cmd )
		);

		$result = Shell::command( $cmd )
			->includeStderr()
			->execute();

		if ( $result->getExitCode() !== 0 ) {
			throw new \RuntimeException(
				'tesseract OCR failed (exit ' . $result->getExitCode() . '): '
				. $result->getStdout()
			);
		}

		return $result->getStdout();
	}

	/**
	 * Convert a text-based PDF file to a single HTML file via pdftohtml, extracting
	 * embedded images as PNGs into the supplied media folder.
	 *
	 * Call {@see isScannedPdf()} first; if the PDF is scanned, use
	 * {@see processScannedPdfFile()} instead.
	 *
	 * @param string $pdfPath     Absolute path to the source PDF file.
	 * @param string $mediaFolder Absolute path to the directory where extracted
	 *                            images should be placed. Must already exist.
	 * @return string Absolute path to the generated HTML file.
	 * @throws \RuntimeException If pdftohtml fails or produces no output.
	 */
	public function processPDFFile( string $pdfPath, string $mediaFolder ): string {
		$outputPrefix = $mediaFolder . DIRECTORY_SEPARATOR . 'pdf_output';

		$cmd = [
			$this->pdfToHtmlPath,
			'-noframes',        // Single HTML file instead of frameset
			'-enc', 'UTF-8',    // Proper encoding
			'-fmt', 'png',      // Extract images as PNG
			'-nodrm',           // Handle copy-protected PDFs
			'-s',               // Generate single HTML file
			$pdfPath,
			$outputPrefix,
		];

		wfDebugLog(
			'PandocUltimateConverter',
			'PDFPreprocessor: running ' . implode( ' ', $cmd )
		);

		$result = Shell::command( $cmd )
			->includeStderr()
			->execute();

		if ( $result->getExitCode() !== 0 ) {
			throw new \RuntimeException(
				'pdftohtml conversion failed (exit ' . $result->getExitCode() . '): '
				. $result->getStdout()
			);
		}

		// pdftohtml with -noframes -s produces <prefix>.html
		$htmlFile = $outputPrefix . '.html';
		if ( !file_exists( $htmlFile ) ) {
			// Some versions produce <prefix>-html.html instead
			$altHtmlFile = $outputPrefix . '-html.html';
			if ( file_exists( $altHtmlFile ) ) {
				$htmlFile = $altHtmlFile;
			} else {
				throw new \RuntimeException(
					'pdftohtml produced no HTML output. Expected: ' . $htmlFile
				);
			}
		}

		wfDebugLog(
			'PandocUltimateConverter',
			'PDFPreprocessor: HTML output at ' . $htmlFile
		);

		$this->cleanHtml( $htmlFile );

		return $htmlFile;
	}

	/**
	 * Classify extracted PDF text as scanned or text-based.
	 *
	 * Counts non-whitespace characters and compares the average per page
	 * (derived from form-feed separators) against TEXT_CHARS_PER_PAGE_THRESHOLD.
	 *
	 * @param string $text Raw text extracted by pdftotext.
	 * @return bool True if the PDF appears to be a scanned (image-only) document.
	 */
	private function classifyTextAsScanned( string $text ): bool {
		// Derive page count from form-feed separators inserted between pages.
		$formFeeds = substr_count( $text, "\f" );
		$pageCount = $formFeeds + 1;

		$nonWhitespace = strlen( preg_replace( '/\s+/', '', $text ) );
		$threshold = self::TEXT_CHARS_PER_PAGE_THRESHOLD * $pageCount;

		wfDebugLog(
			'PandocUltimateConverter',
			"PDFPreprocessor::classifyTextAsScanned: pages=$pageCount, chars=$nonWhitespace, threshold=$threshold"
		);

		return $nonWhitespace < $threshold;
	}

	/**
	 * Assemble per-page OCR texts into MediaWiki wikitext.
	 *
	 * Non-empty lines within each page become paragraphs (separated by blank
	 * lines); pages are separated by a horizontal rule (----).
	 *
	 * @param string[] $pageTexts One entry per page, as returned by tesseract.
	 * @return string MediaWiki wikitext.
	 */
	private function assembleWikitextFromPageTexts( array $pageTexts ): string {
		$wikitextParts = [];
		foreach ( $pageTexts as $pageText ) {
			$lines = explode( "\n", $pageText );
			$pageLines = [];
			foreach ( $lines as $line ) {
				$trimmed = trim( $line );
				if ( $trimmed !== '' ) {
					$pageLines[] = $trimmed;
				}
			}
			if ( $pageLines !== [] ) {
				$wikitextParts[] = implode( "\n\n", $pageLines );
			}
		}
		return implode( "\n\n----\n\n", $wikitextParts );
	}

	/**
	 * Remove pdftohtml artifacts from the generated HTML:
	 * - Absolute/fixed positioning inline styles (e.g. "position:relative;width:892px;height:1262px;")
	 * - Empty anchor/span tags used as page markers (e.g. <span id="2"></span>)
	 *
	 * @param string $htmlFile Absolute path to the HTML file (modified in place).
	 */
	private function cleanHtml( string $htmlFile ): void {
		$html = file_get_contents( $htmlFile );
		if ( $html === false ) {
			return;
		}

		// Strip style attributes containing absolute positioning from divs/spans
		$html = preg_replace(
			'/\s+style="[^"]*position\s*:\s*(?:absolute|relative|fixed)[^"]*"/',
			'',
			$html
		);

		// Remove empty spans/anchors used as page-number markers: <span id="N"></span>
		$html = preg_replace(
			'/<(span|a)\b[^>]*>\s*<\/\1>/',
			'',
			$html
		);

		// Replace <br/> variants with plain newlines
		$html = preg_replace( '/<br\s*\/?>/', "\n", $html );

		file_put_contents( $htmlFile, $html );
	}
}
