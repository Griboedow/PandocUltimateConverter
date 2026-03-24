<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter;

use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;

/**
 * Special page for exporting wiki pages to external document formats (DOCX, ODT, EPUB, …)
 * via Pandoc.
 *
 * Access via: Special:PandocExport
 *
 * When called with GET parameters `format` and `pages[]` it streams the converted file
 * directly to the browser as a download attachment.  Without those parameters it renders
 * the interactive Codex UI.
 */
class SpecialPandocExport extends \SpecialPage {

	/**
	 * Supported export formats.
	 *
	 * Keys are the format identifiers used in the URL / UI.
	 * `pandoc_format` is the argument passed to `--to=` in pandoc.
	 * `ext`          is the file extension for the download.
	 * `mime`         is the Content-Type sent with the download.
	 *
	 * @var array<string, array{label: string, pandoc_format: string, ext: string, mime: string}>
	 */
	public const SUPPORTED_FORMATS = [
		'docx' => [
			'label'         => 'Microsoft Word (.docx)',
			'pandoc_format' => 'docx',
			'ext'           => 'docx',
			'mime'          => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		],
		'odt' => [
			'label'         => 'OpenDocument Text (.odt)',
			'pandoc_format' => 'odt',
			'ext'           => 'odt',
			'mime'          => 'application/vnd.oasis.opendocument.text',
		],
		'epub' => [
			'label'         => 'EPUB (.epub)',
			'pandoc_format' => 'epub',
			'ext'           => 'epub',
			'mime'          => 'application/epub+zip',
		],
		'pdf' => [
			'label'         => 'PDF (.pdf)',
			'pandoc_format' => 'pdf',
			'ext'           => 'pdf',
			'mime'          => 'application/pdf',
		],
		'html' => [
			'label'         => 'HTML (.html)',
			'pandoc_format' => 'html5',
			'ext'           => 'html',
			'mime'          => 'text/html',
		],
		'rtf' => [
			'label'         => 'Rich Text Format (.rtf)',
			'pandoc_format' => 'rtf',
			'ext'           => 'rtf',
			'mime'          => 'application/rtf',
		],
		'txt' => [
			'label'         => 'Plain Text (.txt)',
			'pandoc_format' => 'plain',
			'ext'           => 'txt',
			'mime'          => 'text/plain',
		],
	];

	private Config $config;
	private MediaWikiServices $mwServices;
	/** @var \User */
	private $user;
	/** @var \RepoGroup */
	private $repoGroup;

	public function __construct() {
		$mwConfig  = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'PandocUltimateConverter' );
		$userRight = $mwConfig->get( 'PandocUltimateConverter_PandocCustomUserRight' ) ?? '';

		parent::__construct( 'PandocExport', $userRight );

		$this->config     = $mwConfig;
		$this->mwServices = MediaWikiServices::getInstance();
		$this->user       = RequestContext::getMain()->getUser();
		$this->repoGroup  = $this->mwServices->getRepoGroup();
	}

	protected function getGroupName(): string {
		return 'media';
	}

	public function execute( $par ): void {
		$this->setHeaders();
		$this->checkPermissions();

		$request = $this->getRequest();
		$output  = $this->getOutput();

		// If `format` is present in the query string, treat this as a download request.
		if ( $request->getVal( 'format' ) !== null ) {
			$this->handleExportRequest( $request, $output );
			return;
		}

		// Otherwise render the interactive Codex UI.
		$output->addModules( 'ext.PandocUltimateConverter.export' );
		$output->addJsConfigVars( [
			'pandocExportFormats'  => self::SUPPORTED_FORMATS,
			'pandocExportEndpoint' => $this->getPageTitle()->getLocalURL(),
		] );
		$output->addHTML( Html::element( 'div', [ 'class' => 'mw-pandoc-export-root' ] ) );
	}

	// -----------------------------------------------------------------------
	// Export request handling
	// -----------------------------------------------------------------------

	private function handleExportRequest( \WebRequest $request, \OutputPage $output ): void {
		$format = $request->getVal( 'format', 'docx' );
		if ( !array_key_exists( $format, self::SUPPORTED_FORMATS ) ) {
			$output->addWikiTextAsInterface(
				wfMessage( 'pandocultimateconverter-export-error-invalid-format' )->text()
			);
			return;
		}

		$rawPages = $request->getArray( 'pages' ) ?? [];
		$pages    = array_values( array_filter(
			array_map( 'trim', $rawPages ),
			static function ( string $p ): bool {
				return $p !== '';
			}
		) );

		if ( $pages === [] ) {
			$output->addWikiTextAsInterface(
				wfMessage( 'pandocultimateconverter-export-error-no-pages' )->text()
			);
			return;
		}

		// Build a safe filename for the download (keep only filesystem-safe characters)
		$rawBaseName = count( $pages ) > 1 ? 'export' : $pages[0];
		$baseName = preg_replace( '/[\/\\\\:\*\?"<>\|]/', '_', $rawBaseName );
		$baseName = trim( $baseName, '. ' );
		if ( $baseName === '' ) {
			$baseName = 'export';
		}
		$formatInfo   = self::SUPPORTED_FORMATS[$format];
		$downloadName = $baseName . '.' . $formatInfo['ext'];

		try {
			$outputFile = $this->doExport( $pages, $format );
		} catch ( \RuntimeException $e ) {
			$output->addWikiTextAsInterface(
				wfMessage( 'pandocultimateconverter-export-error-failed', $e->getMessage() )->text()
			);
			return;
		}

		$this->streamDownload( $outputFile, $downloadName, $formatInfo['mime'] );
	}

	// -----------------------------------------------------------------------
	// Core export logic
	// -----------------------------------------------------------------------

	/**
	 * Run the full export pipeline and return the path to the generated file.
	 *
	 * @param string[] $pages  List of wiki page titles to export.
	 * @param string   $format Format key from SUPPORTED_FORMATS.
	 * @return string Absolute path to the temporary output file.
	 * @throws \RuntimeException On any conversion failure.
	 */
	private function doExport( array $pages, string $format ): string {
		$tempBase = $this->config->get( 'PandocUltimateConverter_TempFolderPath' )
			?? sys_get_temp_dir();
		$workDir  = $tempBase . DIRECTORY_SEPARATOR . 'pandoc-export-' . uniqid( '', true );
		mkdir( $workDir, 0755, true );

		try {
			return $this->runExport( $pages, $format, $workDir );
		} catch ( \Exception $e ) {
			PandocWrapper::deleteDirectory( $workDir );
			throw new \RuntimeException( $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Set up the temp directory, gather images, and invoke Pandoc.
	 *
	 * @param string[] $pages
	 * @param string   $format
	 * @param string   $workDir
	 * @return string Absolute path to the output file.
	 */
	private function runExport( array $pages, string $format, string $workDir ): string {
		$mediaDir = $workDir . DIRECTORY_SEPARATOR . 'media';
		mkdir( $mediaDir, 0755, true );

		$parts = [];
		foreach ( $pages as $pageName ) {
			$wikitext = $this->getPageWikitext( $pageName );
			$this->gatherImages( $wikitext, $mediaDir );
			// Add a top-level heading before each page's wikitext when combining several pages.
			// wfEscapeWikiText() prevents page names with wikitext-special characters from
			// accidentally altering the document structure.
			if ( count( $pages ) > 1 ) {
				$parts[] = '= ' . wfEscapeWikiText( $pageName ) . " =\n\n" . $wikitext;
			} else {
				$parts[] = $wikitext;
			}
		}

		$combinedWikitext = implode( "\n\n----\n\n", $parts );

		// Write wikitext to a temp file for pandoc to read.
		$inputFile = $workDir . DIRECTORY_SEPARATOR . 'input.mediawiki';
		file_put_contents( $inputFile, $combinedWikitext );

		$formatInfo = self::SUPPORTED_FORMATS[$format];
		$outputFile = $workDir . DIRECTORY_SEPARATOR . 'output.' . $formatInfo['ext'];

		$pandocPath = $this->config->get( 'PandocUltimateConverter_PandocExecutablePath' ) ?? 'pandoc';

		$cmd = [
			$pandocPath,
			'--from=mediawiki',
			'--to=' . $formatInfo['pandoc_format'],
			'--resource-path=' . $mediaDir,
			'--output=' . $outputFile,
			'--standalone',
		];

		// Add title metadata into the document; passed as separate arguments so that
		// Shell::command() (which uses proc_open, not a shell) handles quoting correctly.
		if ( count( $pages ) === 1 ) {
			$cmd[] = '--metadata';
			$cmd[] = 'title:' . $pages[0];
		} else {
			$cmd[] = '--metadata';
			$cmd[] = 'title:Export';
		}

		$cmd[] = $inputFile;

		PandocWrapper::invokePandoc( $cmd );

		return $outputFile;
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Return the raw wikitext of a page.
	 *
	 * @param string $pageName
	 * @return string
	 * @throws \RuntimeException If the page does not exist or contains no wikitext.
	 */
	private function getPageWikitext( string $pageName ): string {
		$title = \Title::newFromText( $pageName );
		if ( $title === null || !$title->exists() ) {
			throw new \RuntimeException( "Page not found: $pageName" );
		}

		$page    = $this->mwServices->getWikiPageFactory()->newFromTitle( $title );
		$content = $page->getContent();

		if ( !( $content instanceof \WikitextContent ) ) {
			throw new \RuntimeException( "Page '$pageName' does not contain wikitext." );
		}

		return $content->getText();
	}

	/**
	 * Scan wikitext for [[File:…]] / [[Image:…]] references, look each up in the
	 * local file repository, and copy it into $mediaDir so Pandoc can embed it.
	 *
	 * @param string $wikitext
	 * @param string $mediaDir Absolute path to the temp media directory.
	 */
	private function gatherImages( string $wikitext, string $mediaDir ): void {
		if ( !preg_match_all( '/\[\[(?:File|Image):([^\|\[\]#]+)/iu', $wikitext, $matches ) ) {
			return;
		}

		foreach ( array_unique( $matches[1] ) as $rawName ) {
			$rawName = trim( $rawName );
			if ( $rawName === '' ) {
				continue;
			}

			$fileTitle = \Title::newFromText( $rawName, NS_FILE );
			if ( $fileTitle === null ) {
				continue;
			}

			$localFile = $this->repoGroup->findFile( $fileTitle );
			if ( !$localFile || !$localFile->exists() ) {
				continue;
			}

			$srcPath = $localFile->getLocalRefPath();
			if ( !$srcPath || !file_exists( $srcPath ) ) {
				continue;
			}

			// Pandoc looks for the image by the exact name that appears after "File:"
			// in the wikitext.  MediaWiki stores files with underscores, so we copy
			// the file under both the space form and the underscore form.
			$nameSpaces      = $rawName;
			$nameUnderscores = str_replace( ' ', '_', $rawName );

			foreach ( array_unique( [ $nameSpaces, $nameUnderscores ] ) as $destName ) {
				$destPath = $mediaDir . DIRECTORY_SEPARATOR . $destName;
				if ( !file_exists( $destPath ) ) {
					$copied = copy( $srcPath, $destPath );
					if ( !$copied ) {
						wfDebugLog( 'PandocUltimateConverter', "gatherImages: failed to copy $srcPath → $destPath" );
					}
				}
			}
		}
	}

	/**
	 * Send $filePath to the browser as a file download, then clean up and exit.
	 *
	 * @param string $filePath      Absolute path to the file to send.
	 * @param string $downloadName  Filename suggested to the browser.
	 * @param string $mime          Content-Type value.
	 */
	private function streamDownload( string $filePath, string $downloadName, string $mime ): void {
		// Prevent MediaWiki from writing any HTML after us.
		$this->getOutput()->disable();

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Content-Type: ' . $mime );
		// Provide both the plain ASCII fallback (filename=) and the RFC 5987-encoded form
		// (filename*=) so that all browsers can display/save the file under the correct name.
		$asciiName  = preg_replace( '/[^\x20-\x7E]/', '_', $downloadName );
		$encodedName = "UTF-8''" . rawurlencode( $downloadName );
		header( 'Content-Disposition: attachment; filename="' . str_replace( [ '"', '\\' ], [ '\\"', '\\\\' ], $asciiName ) . '"; filename*=' . $encodedName );
		header( 'Content-Length: ' . filesize( $filePath ) );
		header( 'Cache-Control: no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );

		readfile( $filePath );

		// Clean up the entire working directory (parent of $filePath).
		PandocWrapper::deleteDirectory( dirname( $filePath ) );

		exit;
	}
}
