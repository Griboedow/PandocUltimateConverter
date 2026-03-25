<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Processors;

use MediaWiki\Shell\Shell;

/**
 * Converts legacy .doc files to .docx using LibreOffice's headless mode
 * so that Pandoc (which cannot read .doc) can process them.
 *
 * Pipeline: DOC → LibreOffice → DOCX → (handed back to caller)
 */
class DOCPreprocessor {

	private string $libreofficePath;

	/**
	 * @param string $libreofficePath Absolute path (or bare command name) for soffice / libreoffice.
	 */
	public function __construct( string $libreofficePath = 'libreoffice' ) {
		$this->libreofficePath = $libreofficePath;
	}

	/**
	 * Convert a .doc file to .docx using LibreOffice in headless mode.
	 *
	 * @param string $docFilePath Absolute path to the source .doc file.
	 * @param string $outDir      Directory where the resulting .docx will be written.
	 * @return string Absolute path to the converted .docx file.
	 * @throws \RuntimeException If LibreOffice conversion fails or the output file is not found.
	 */
	public function convertToDocx( string $docFilePath, string $outDir ): string {
		$cmd = [
			$this->libreofficePath,
			'--headless',
			'--convert-to', 'docx',
			'--outdir', $outDir,
			$docFilePath,
		];

		wfDebugLog(
			'PandocUltimateConverter',
			'DOCPreprocessor: running ' . implode( ' ', $cmd )
		);

		$result = Shell::command( $cmd )
			->includeStderr()
			->execute();

		$baseName = pathinfo( $docFilePath, PATHINFO_FILENAME );
		$docxPath = $outDir . DIRECTORY_SEPARATOR . $baseName . '.docx';

		// LibreOffice may crash during shutdown but still produce the file.
		// Check for the output file first; only fail if it's actually missing.
		if ( !file_exists( $docxPath ) ) {
			$exitCode = $result->getExitCode();
			$output = trim( $result->getStdout() );
			$detail = $output !== '' ? $output
				: 'LibreOffice crashed with no output';
			throw new \RuntimeException(
				'LibreOffice .doc→.docx conversion failed (exit '
				. $exitCode . '): ' . $detail
			);
		}

		if ( $result->getExitCode() !== 0 ) {
			wfDebugLog(
				'PandocUltimateConverter',
				'DOCPreprocessor: LibreOffice exited with code ' . $result->getExitCode()
				. ' but output file was produced; continuing. stderr/stdout: '
				. $result->getStdout()
			);
		}

		return $docxPath;
	}
}
