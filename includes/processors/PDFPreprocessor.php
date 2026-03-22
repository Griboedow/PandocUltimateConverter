<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Processors;

use MediaWiki\Shell\Shell;

/**
 * Preprocesses PDF files using poppler's pdftohtml before handing the
 * resulting HTML + images to Pandoc for conversion to MediaWiki wikitext.
 *
 * Pipeline: PDF → pdftohtml → HTML + PNG images → Pandoc → mediawiki wikitext
 */
class PDFPreprocessor {

	private string $pdfToHtmlPath;

	/**
	 * @param string $pdfToHtmlPath Absolute path (or bare command name) for poppler's pdftohtml.
	 */
	public function __construct( string $pdfToHtmlPath = 'pdftohtml' ) {
		$this->pdfToHtmlPath = $pdfToHtmlPath;
	}

	/**
	 * Convert a PDF file to a single HTML file, extracting embedded images as PNGs
	 * into the supplied media folder.
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
