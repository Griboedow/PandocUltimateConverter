<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\SpecialPages;

use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\PandocUltimateConverter\PandocWrapper;
use MediaWiki\Html\Html;
use MediaWiki\Title\Title;
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
		return 'pagetools';
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

		// Pre-fill the page list from the subpage path (Special:PandocExport/Page_Name)
		$initialPages = [];
		if ( $par !== null && $par !== '' ) {
			$initialPages[] = str_replace( '_', ' ', $par );
		}

		$output->addJsConfigVars( [
			'pandocExportFormats'      => self::SUPPORTED_FORMATS,
			'pandocExportEndpoint'     => $this->getPageTitle()->getLocalURL(),
			'pandocExportInitialPages' => $initialPages,
		] );
		$output->addHTML( Html::element( 'div', [ 'class' => 'mw-pandoc-export-root' ] ) );
	}

	// -----------------------------------------------------------------------
	// Export request handling
	// -----------------------------------------------------------------------

	private function handleExportRequest( \WebRequest $request, \OutputPage $output ): void {
		$format = $request->getVal( 'format', 'docx' );
		if ( !array_key_exists( $format, self::SUPPORTED_FORMATS ) ) {
			$this->sendJsonError(
				$output,
				wfMessage( 'pandocultimateconverter-export-error-invalid-format' )->text()
			);
			return;
		}

		$rawItems = $request->getArray( 'items' ) ?? [];
		$items    = array_values( array_filter(
			array_map( 'trim', $rawItems ),
			static function ( string $p ): bool {
				return $p !== '';
			}
		) );

		// Auto-detect categories vs pages and resolve category members.
		$pages = [];
		foreach ( $items as $itemName ) {
			$title = Title::newFromText( $itemName );
			if ( $title !== null && $title->getNamespace() === NS_CATEGORY ) {
				$visited = [];
				$categoryPages = $this->getCategoryPages( $title->getText(), $visited );
				$pages = array_merge( $pages, $categoryPages );
			} else {
				$pages[] = $itemName;
			}
		}

		// Deduplicate while preserving order.
		$pages = array_values( array_unique( $pages ) );

		if ( $pages === [] ) {
			$this->sendJsonError(
				$output,
				wfMessage( 'pandocultimateconverter-export-error-no-pages' )->text()
			);
			return;
		}

		$separate = $request->getBool( 'separate' );
		$formatInfo = self::SUPPORTED_FORMATS[$format];

		// Use page name as filename when exporting a single page.
		// When "separate" is requested with multiple pages, export each individually.
		if ( $separate && count( $pages ) > 1 ) {
			try {
				$zipFile = $this->doExportSeparate( $pages, $format );
			} catch ( \RuntimeException $e ) {
				$this->sendJsonError( $output, $e->getMessage() );
				return;
			}
			$downloadName = 'export.zip';
			$this->streamDownload( $zipFile, $downloadName, 'application/zip' );
		} else {
			// Single file export — use the page name as the filename.
			$rawBaseName  = count( $pages ) === 1 ? $pages[0] : 'export';
			$baseName     = self::sanitizeFilename( $rawBaseName );
			$downloadName = $baseName . '.' . $formatInfo['ext'];

			try {
				$outputFile = $this->doExport( $pages, $format );
			} catch ( \RuntimeException $e ) {
				$this->sendJsonError( $output, $e->getMessage() );
				return;
			}

			$this->streamDownload( $outputFile, $downloadName, $formatInfo['mime'] );
		}
	}

	/**
	 * Send a JSON error response so the fetch()-based JS client can parse it cleanly,
	 * instead of returning a full MediaWiki HTML page.
	 */
	private function sendJsonError( \OutputPage $output, string $message ): void {
		$output->disable();
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		http_response_code( 500 );
		header( 'Content-Type: application/json; charset=UTF-8' );
		echo json_encode( [ 'error' => $message ], JSON_UNESCAPED_UNICODE );
		exit;
	}

	// -----------------------------------------------------------------------
	// Public static helpers (pure logic; no MediaWiki dependencies)
	// -----------------------------------------------------------------------

	/**
	 * Sanitize a raw string into a safe download filename component.
	 *
	 * Replaces filesystem-unsafe characters with underscores and strips leading /
	 * trailing dots and spaces.  Falls back to "export" when the result would be
	 * empty.
	 *
	 * Extracted as a public static method so it can be unit-tested in isolation.
	 */
	public static function sanitizeFilename( string $raw ): string {
		$name = preg_replace( '/[\/\\\\:\*\?"<>\|]/', '_', $raw );
		$name = trim( $name ?? '', '. ' );
		return $name !== '' ? $name : 'export';
	}

	/**
	 * Build the combined wikitext for a multi-page export.
	 *
	 * When only one page is exported the wikitext is returned as-is.  For
	 * multiple pages each section is preceded by a level-1 heading and sections
	 * are separated by a horizontal rule.
	 *
	 * Extracted as a public static method so it can be unit-tested in isolation.
	 *
	 * @param string[] $pages     Ordered list of page names.
	 * @param string[] $wikitexts Wikitext for each page, same order as $pages.
	 * @return string
	 */
	public static function buildCombinedWikitext( array $pages, array $wikitexts ): string {
		$parts = [];
		$multi = count( $pages ) > 1;
		foreach ( $pages as $i => $pageName ) {
			$wikitext = $wikitexts[$i] ?? '';
			if ( $multi ) {
				$parts[] = '= ' . wfEscapeWikiText( $pageName ) . " =\n\n" . $wikitext;
			} else {
				$parts[] = $wikitext;
			}
		}
		return implode( "\n\n----\n\n", $parts );
	}

	/**
	 * Extract candidate file-link targets from wikitext using a broad regex.
	 *
	 * Returns every unique link target that contains a ":" character (i.e. has
	 * a namespace prefix).  The caller is responsible for filtering to actual
	 * file-namespace titles via Title::newFromText().
	 *
	 * Extracted as a public static method so the regex logic can be
	 * unit-tested independently of the MediaWiki Title API.
	 *
	 * @return string[] Unique trimmed link targets, e.g. ["File:foo.png", "Media:bar.jpg"]
	 */
	public static function extractWikilinkTargets( string $wikitext ): array {
		if ( !preg_match_all( '/\[\[([^\|\[\]#\n]+)/u', $wikitext, $matches ) ) {
			return [];
		}
		$results = [];
		foreach ( array_unique( $matches[1] ) as $rawLink ) {
			$rawLink = trim( $rawLink );
			if ( $rawLink !== '' && strpos( $rawLink, ':' ) !== false ) {
				$results[] = $rawLink;
			}
		}
		return $results;
	}

	// -----------------------------------------------------------------------
	// Category resolution
	// -----------------------------------------------------------------------

	/**
	 * Recursively fetch all content pages belonging to a category.
	 *
	 * Walks subcategories depth-first and uses a visited set to prevent
	 * infinite loops when the category graph contains cycles.
	 *
	 * @param string   $categoryName  Category name (with or without "Category:" prefix).
	 * @param string[] &$visited      Set of already-visited category DB keys (cycle guard).
	 * @return string[] Page titles (main namespace) found in the category tree.
	 */
	private function getCategoryPages( string $categoryName, array &$visited ): array {
		$title = Title::newFromText( $categoryName, NS_CATEGORY );
		if ( $title === null ) {
			return [];
		}

		$dbKey = $title->getDBkey();
		if ( in_array( $dbKey, $visited, true ) ) {
			// Cycle detected — stop recursion.
			return [];
		}
		$visited[] = $dbKey;

		$dbr = $this->mwServices->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$pages = [];

		// Fetch all members of this category.
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'cl_from' ] )
			->from( 'categorylinks' )
			->where( [ 'cl_target_id' => $dbKey ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $res as $row ) {
			$memberTitle = Title::newFromID( (int)$row->cl_from );
			if ( $memberTitle === null ) {
				continue;
			}

			if ( $memberTitle->getNamespace() === NS_CATEGORY ) {
				// Recurse into subcategory.
				$subPages = $this->getCategoryPages(
					$memberTitle->getPrefixedText(),
					$visited
				);
				$pages = array_merge( $pages, $subPages );
			} else {
				$pages[] = $memberTitle->getPrefixedText();
			}
		}

		return $pages;
	}

	// -----------------------------------------------------------------------
	// Core export logic
	// -----------------------------------------------------------------------

	/**
	 * Export each page as a separate file and bundle them in a ZIP archive.
	 *
	 * @param string[] $pages  List of wiki page titles to export.
	 * @param string   $format Format key from SUPPORTED_FORMATS.
	 * @return string Absolute path to the temporary ZIP file.
	 * @throws \RuntimeException On any conversion failure.
	 */
	private function doExportSeparate( array $pages, string $format ): string {
		$tempBase = $this->config->get( 'PandocUltimateConverter_TempFolderPath' )
			?? sys_get_temp_dir();
		$workDir  = $tempBase . DIRECTORY_SEPARATOR . 'pandoc-export-' . uniqid( '', true );
		mkdir( $workDir, 0755, true );

		$formatInfo = self::SUPPORTED_FORMATS[$format];
		$zipPath = $workDir . DIRECTORY_SEPARATOR . 'export.zip';

		// Track individual export workDirs so we can clean them up after zipping.
		$exportDirs = [];

		try {
			$zip = new \ZipArchive();
			if ( $zip->open( $zipPath, \ZipArchive::CREATE ) !== true ) {
				throw new \RuntimeException( 'Failed to create ZIP archive.' );
			}

			foreach ( $pages as $pageName ) {
				$singleFile = $this->doExport( [ $pageName ], $format );
				$exportDirs[] = dirname( $singleFile );
				$entryName = self::sanitizeFilename( $pageName ) . '.' . $formatInfo['ext'];
				$zip->addFile( $singleFile, $entryName );
			}

			// ZipArchive reads addFile() paths on close(), so files must exist until now.
			$zip->close();

			// Clean up individual export temp dirs now that the ZIP is written.
			foreach ( $exportDirs as $dir ) {
				PandocWrapper::deleteDirectory( $dir );
			}

			return $zipPath;
		} catch ( \Exception $e ) {
			foreach ( $exportDirs as $dir ) {
				PandocWrapper::deleteDirectory( $dir );
			}
			PandocWrapper::deleteDirectory( $workDir );
			throw new \RuntimeException( $e->getMessage(), 0, $e );
		}
	}

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

		$parts     = [];
		$wikitexts = [];
		$parser        = $this->mwServices->getParser();
		$parserOptions = \ParserOptions::newFromAnon();
		foreach ( $pages as $pageName ) {
			$wikitext = $this->getPageWikitext( $pageName );

			// Expand templates / parser functions while keeping the result as wikitext
			// so that {{TemplateName}} and {{#if:…}} are resolved before Pandoc sees them.
			$title    = Title::newFromText( $pageName );
			$wikitext = $parser->preprocess( $wikitext, $title, $parserOptions );

			$wikitexts[] = $wikitext;
			$this->gatherImages( $wikitext, $mediaDir );
		}

		$combinedWikitext = self::buildCombinedWikitext( $pages, $wikitexts );

		// Write wikitext to a temp file for pandoc to read.
		$inputFile = $workDir . DIRECTORY_SEPARATOR . 'input.mediawiki';
		file_put_contents( $inputFile, $combinedWikitext );

		$pandocPath = $this->config->get( 'PandocUltimateConverter_PandocExecutablePath' ) ?? 'pandoc';

		// PDF: Pandoc needs a LaTeX engine for --to=pdf which is often absent.
		// Use a two-step pipeline instead: mediawiki → docx (Pandoc) → pdf (LibreOffice).
		if ( $format === 'pdf' ) {
			return $this->exportPdfViaLibreOffice( $pages, $inputFile, $mediaDir, $workDir, $pandocPath );
		}

		$formatInfo = self::SUPPORTED_FORMATS[$format];
		$outputFile = $workDir . DIRECTORY_SEPARATOR . 'output.' . $formatInfo['ext'];

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

	/**
	 * Export to PDF using a two-step pipeline: mediawiki → docx (Pandoc) → pdf (LibreOffice).
	 *
	 * This avoids requiring a LaTeX engine, which is rarely available on Windows.
	 *
	 * @param string[] $pages
	 * @param string   $inputFile  Path to the mediawiki input file.
	 * @param string   $mediaDir   Path to the media directory.
	 * @param string   $workDir    Working temporary directory.
	 * @param string   $pandocPath Path to the Pandoc executable.
	 * @return string Absolute path to the generated PDF file.
	 * @throws \RuntimeException On conversion failure.
	 */
	private function exportPdfViaLibreOffice(
		array $pages, string $inputFile, string $mediaDir,
		string $workDir, string $pandocPath
	): string {
		// Step 1: mediawiki → docx via Pandoc
		$docxFile = $workDir . DIRECTORY_SEPARATOR . 'output.docx';
		$cmd = [
			$pandocPath,
			'--from=mediawiki',
			'--to=docx',
			'--resource-path=' . $mediaDir,
			'--output=' . $docxFile,
			'--standalone',
		];

		if ( count( $pages ) === 1 ) {
			$cmd[] = '--metadata';
			$cmd[] = 'title:' . $pages[0];
		} else {
			$cmd[] = '--metadata';
			$cmd[] = 'title:Export';
		}

		$cmd[] = $inputFile;
		PandocWrapper::invokePandoc( $cmd );

		if ( !file_exists( $docxFile ) ) {
			throw new \RuntimeException(
				'Pandoc did not produce the intermediate DOCX file: ' . $docxFile
			);
		}

		// Step 2: docx → pdf via LibreOffice
		$libreofficePath = $this->config->get( 'PandocUltimateConverter_LibreOfficeExecutablePath' )
			?? 'libreoffice';

		$loCmd = [
			$libreofficePath,
			'-env:UserInstallation=file:///' . str_replace( '\\', '/', $workDir . DIRECTORY_SEPARATOR . '.lo_profile' ),
			'--headless',
			'--convert-to', 'pdf',
			'--outdir', $workDir,
			$docxFile,
		];

		$loProfileDir = $workDir . DIRECTORY_SEPARATOR . '.lo_profile';
		if ( !is_dir( $loProfileDir ) ) {
			mkdir( $loProfileDir, 0755, true );
		}

		wfDebugLog( 'PandocUltimateConverter', 'exportPdfViaLibreOffice: running ' . implode( ' ', $loCmd ) );

		// Pass through environment so LibreOffice gets TEMP, PATH, etc.
		$envArr = getenv();
		$result = \MediaWiki\Shell\Shell::command( $loCmd )
			->includeStderr()
			->environment( is_array( $envArr ) ? $envArr : [] )
			->execute();

		wfDebugLog( 'PandocUltimateConverter',
			'exportPdfViaLibreOffice: exit=' . $result->getExitCode()
			. ' stdout=' . $result->getStdout()
		);

		// LibreOffice places the output file in --outdir with the same base name
		// but a .pdf extension. It may exit non-zero (e.g. crash during cleanup)
		// yet still produce the file, so check for the file first.
		$pdfFile = $workDir . DIRECTORY_SEPARATOR . 'output.pdf';

		if ( !file_exists( $pdfFile ) ) {
			// Scan for any .pdf in workDir — LibreOffice might use a slightly different name
			$foundPdf = null;
			foreach ( scandir( $workDir ) as $entry ) {
				if ( strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) ) === 'pdf' ) {
					$foundPdf = $workDir . DIRECTORY_SEPARATOR . $entry;
					break;
				}
			}
			if ( $foundPdf !== null ) {
				$pdfFile = $foundPdf;
			}
		}

		if ( !file_exists( $pdfFile ) ) {
			// List what LibreOffice actually produced for debugging
			$files = implode( ', ', array_diff( scandir( $workDir ), [ '.', '..' ] ) );
			$output = trim( $result->getStdout() );
			$detail = $output !== '' ? $output : 'No output from LibreOffice';
			throw new \RuntimeException(
				'LibreOffice docx→pdf conversion failed (exit '
				. $result->getExitCode() . '): ' . $detail
				. ' | Files in workDir: ' . $files
			);
		}

		return $pdfFile;
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
		$title = Title::newFromText( $pageName );
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
	 * Scan wikitext for file links and copy the referenced files into $mediaDir
	 * so Pandoc can embed them in the output document.
	 *
	 * Handles all link forms that resolve to files:
	 *  - Standard English:  [[File:…]], [[Image:…]]
	 *  - Media pseudo-ns:   [[Media:…]]
	 *  - Localized aliases: [[Datei:…]], [[Файл:…]], etc.
	 *
	 * Rather than maintaining a hard-coded list of prefixes, the full link target
	 * (including namespace prefix) is passed to Title::newFromText() which uses
	 * MediaWiki's own namespace registry (including all localized names and aliases).
	 * Any title that resolves to NS_FILE or NS_MEDIA is treated as a file reference.
	 *
	 * @param string $wikitext
	 * @param string $mediaDir Absolute path to the temp media directory.
	 */
	private function gatherImages( string $wikitext, string $mediaDir ): void {
		// Use the static helper to extract candidate file-link targets (those with a ":").
		// Title::newFromText() then does the authoritative namespace validation.
		$candidates = self::extractWikilinkTargets( $wikitext );

		foreach ( $candidates as $rawLink ) {
			$title = Title::newFromText( $rawLink );
			if ( $title === null ) {
				continue;
			}

			$ns = $title->getNamespace();
			if ( $ns !== NS_FILE && $ns !== NS_MEDIA ) {
				continue;
			}

			// Media: links point at the same underlying files as File: links.
			// Convert to NS_FILE so RepoGroup::findFile() can locate the file.
			$fileTitle = $ns === NS_MEDIA
				? Title::makeTitleSafe( NS_FILE, $title->getDBkey() )
				: $title;

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

			// Pandoc resolves images by the name that follows the namespace prefix in
			// the wikitext.  Copy the file under both the space-form and the
			// underscore-form so Pandoc can find it regardless of normalisation.
			$rawName         = $title->getText();
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
