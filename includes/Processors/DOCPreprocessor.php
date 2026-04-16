<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Processors;

use MediaWiki\Extension\PandocUltimateConverter\PandocWrapper;

/**
 * Converts source office files (e.g. .doc, .pptx) to .docx using
 * LibreOffice's headless mode.
 *
 * Pipeline: source file → LibreOffice → DOCX → (handed back to caller)
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
	 * Convert a source office file to .docx using LibreOffice in headless mode.
	 *
	 * @param string $docFilePath Absolute path to the source file (e.g. .doc, .pptx).
	 * @param string $outDir      Directory where the resulting .docx will be written.
	 * @return string Absolute path to the converted .docx file.
	 * @throws \RuntimeException If LibreOffice conversion fails or the output file is not found.
	 */
	public function convertToDocx( string $docFilePath, string $outDir ): string {
		// LibreOffice needs a writable user profile. Under Apache, environment
		// variables like HOME/USERPROFILE are not passed through, so we
		// point it at a temp directory via -env:UserInstallation.
		$profileDir = $outDir . DIRECTORY_SEPARATOR . '.lo_profile';
		if ( !is_dir( $profileDir ) ) {
			mkdir( $profileDir, 0755, true );
		}
		$profileUrl = 'file:///' . str_replace( '\\', '/', $profileDir );

		$cmd = [
			$this->libreofficePath,
			'-env:UserInstallation=' . $profileUrl,
			'--headless',
			'--convert-to', 'docx',
			'--outdir', $outDir,
			$docFilePath,
		];

		wfDebugLog(
			'PandocUltimateConverter',
			'DOCPreprocessor: running ' . implode( ' ', $cmd )
		);

		// LibreOffice may crash during shutdown but still produce the file,
		// so use invokeShellRaw which does not throw on non-zero exit.
		$result = PandocWrapper::invokeShellRaw( $cmd, true );

		$baseName = pathinfo( $docFilePath, PATHINFO_FILENAME );
		$docxPath = $outDir . DIRECTORY_SEPARATOR . $baseName . '.docx';

		// Check for the output file first; only fail if it's actually missing.
		if ( !file_exists( $docxPath ) ) {
			$output = trim( $result['output'] );
			$detail = $output !== '' ? $output
				: 'LibreOffice crashed with no output';
			throw new \RuntimeException(
				'LibreOffice conversion to .docx failed (exit '
				. $result['exitCode'] . '): ' . $detail
			);
		}

		if ( $result['exitCode'] !== 0 ) {
			wfDebugLog(
				'PandocUltimateConverter',
				'DOCPreprocessor: LibreOffice exited with code ' . $result['exitCode']
				. ' but output file was produced; continuing. stderr/stdout: '
				. $result['output']
			);
		}

		return $docxPath;
	}
}
