<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Processors;

use MediaWiki\Extension\PandocUltimateConverter\PandocWrapper;

/**
 * Converts source office files via LibreOffice's headless mode.
 *
 * Supported pipelines:
 *  - .doc  → .docx  (Writer → Writer)
 *  - .pptx → .pdf   (Impress → PDF export)
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
	 * @param string $docFilePath Absolute path to the source file (e.g. .doc).
	 * @param string $outDir      Directory where the resulting .docx will be written.
	 * @return string Absolute path to the converted .docx file.
	 * @throws \RuntimeException If LibreOffice conversion fails or the output file is not found.
	 */
	public function convertToDocx( string $docFilePath, string $outDir ): string {
		return $this->convertTo( $docFilePath, $outDir, 'docx' );
	}

	/**
	 * Convert a source office file to an arbitrary format using LibreOffice.
	 *
	 * @param string $filePath      Absolute path to the source file.
	 * @param string $outDir        Directory where the converted file will be written.
	 * @param string $targetFormat  Target format extension (e.g. 'docx', 'pdf').
	 * @return string Absolute path to the converted file.
	 * @throws \RuntimeException If LibreOffice conversion fails or the output file is not found.
	 */
	public function convertTo( string $filePath, string $outDir, string $targetFormat ): string {
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
			'--convert-to', $targetFormat,
			'--outdir', $outDir,
			$filePath,
		];

		wfDebugLog(
			'PandocUltimateConverter',
			'DOCPreprocessor: running ' . implode( ' ', $cmd )
		);

		// LibreOffice may crash during shutdown but still produce the file,
		// so use invokeShellRaw which does not throw on non-zero exit.
		$result = PandocWrapper::invokeShellRaw( $cmd, true );

		$baseName = pathinfo( $filePath, PATHINFO_FILENAME );
		$outputPath = $outDir . DIRECTORY_SEPARATOR . $baseName . '.' . $targetFormat;

		// Check for the output file first; only fail if it's actually missing.
		if ( !file_exists( $outputPath ) ) {
			$output = trim( $result['output'] );
			$detail = $output !== '' ? $output
				: 'LibreOffice crashed with no output';
			throw new \RuntimeException(
				'LibreOffice conversion to .' . $targetFormat . ' failed (exit '
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

		return $outputPath;
	}
}
