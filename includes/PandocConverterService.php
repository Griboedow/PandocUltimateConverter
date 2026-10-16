<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter;

use MediaWiki\Extension\PandocUltimateConverter\Processors\PandocTextPostprocessor;
use MediaWiki\Title\Title;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;

/**
 * Service that encapsulates the core conversion logic shared between the
 * Special page UI and the Action API module.
 */
class PandocConverterService {

	/** @var \MediaWiki\Config\Config */
	private $config;

	/** @var MediaWikiServices */
	private $mwServices;

	/** @var \User */
	private $user;

	/** @var PandocWrapper */
	private $pandocWrapper;

	/** @var \MediaWiki\Page\WikiPageFactory */
	private $titleFactory;

	/** @var \MediaWiki\FileRepo\RepoGroup */
	private $repoGroup;

	public function __construct( $config, MediaWikiServices $mwServices, $user ) {
		$this->config       = $config;
		$this->mwServices   = $mwServices;
		$this->user         = $user;
		$this->pandocWrapper = new PandocWrapper( $config, $mwServices, $user );
		$this->titleFactory = $mwServices->getWikiPageFactory();
		$this->repoGroup    = $mwServices->getRepoGroup();
	}

	/**
	 * Convert an already-uploaded MediaWiki file to a wiki page.
	 *
	 * The caller is responsible for deleting the source file afterwards if desired.
	 *
	 * @param string $fileName   Name of an existing file in the wiki repo
	 *                           (e.g. "Document.docx" or "File:Document.docx").
	 * @param string $pageName   Target wiki page title.
	 * @param bool   $llmPolish  Whether to apply LLM cleanup after conversion.
	 * @throws \RuntimeException If the file cannot be found or conversion fails.
	 */
	public function convertFileToPage( string $fileName, string $pageName, bool $llmPolish = false, bool $overwrite = true ): array {
		$fileTitle = Title::newFromTextThrow( $fileName, NS_FILE );
		$localFile = $this->repoGroup->findFile( $fileTitle );

		if ( !$localFile || !$localFile->exists() ) {
			throw new \RuntimeException( "File not found in repository: $fileName" );
		}

		$filePath    = $localFile->getLocalRefPath();
		$pandocOutput = $this->pandocWrapper->convertFile( $filePath );
		return $this->savePandocOutput( $pandocOutput, $pageName, $llmPolish, $overwrite );
	}

	/** Convert a private upload, removing it only after a save or accepted moderation queue entry. */
	public function convertStashedFileToPage( string $fileKey, string $pageName,
		bool $overwrite = false, ?string $sourceFilename = null
	): array {
		$stash = $this->repoGroup->getLocalRepo()->getUploadStash( $this->user );
		$file = $stash->getFile( $fileKey ); // Enforces ownership; never use noAuth.
		$virtualPath = $stash->getMetadata( $fileKey )['us_path'];
		// HTTP uploads may be stashed with a .tmp extension (notably DOCX on
		// Windows). Pandoc and the DOC/PDF preprocessors need the input suffix.
		$extension = strtolower( pathinfo( $sourceFilename ?? $fileKey, PATHINFO_EXTENSION ) );
		if ( !preg_match( '/^[a-z0-9]{1,16}$/D', $extension ) || $extension === 'tmp' ) {
			throw new \RuntimeException( 'The original source filename with its extension is required.' );
		}
		$base = tempnam( sys_get_temp_dir(), 'pandoc-source-' );
		if ( $base === false ) {
			throw new \RuntimeException( 'Unable to create a temporary source file.' );
		}
		$source = $base . '.' . $extension;
		try {
			if ( !rename( $base, $source ) || !copy( $file->getLocalRefPath(), $source ) ) {
				throw new \RuntimeException( 'Unable to prepare the stashed document for conversion.' );
			}
			$output = $this->pandocWrapper->convertFile( $source );
		} finally {
			if ( is_file( $source ) ) {
				unlink( $source );
			}
			if ( is_file( $base ) ) {
				unlink( $base );
			}
		}
		$result = $this->savePandocOutput( $output, $pageName, false, $overwrite );
		// Some MW versions resolve UploadStashFile's path before passing it to
		// freeTemp(), which expects the original virtual URL. Keep that URL from
		// the authenticated stash metadata and verify the physical cleanup too.
		try {
			$removed = $stash->removeFile( $fileKey );
			$repo = $this->repoGroup->getLocalRepo();
			if ( $repo->fileExists( $virtualPath ) ) {
				$removed = $repo->freeTemp( $virtualPath ) && $removed;
			}
			if ( !$removed ) {
				$result['cleanupwarning'] = true;
			}
		} catch ( \Exception $e ) {
			wfDebugLog( 'PandocUltimateConverter', 'Stash cleanup failed: ' . $e->getMessage() );
			$result['cleanupwarning'] = true;
		}
		return $result;
	}

	/**
	 * Fetch a URL and convert it to a wiki page.
	 *
	 * @param string $sourceUrl  URL to fetch and convert.
	 * @param string $pageName   Target wiki page title.
	 * @param bool   $llmPolish  Whether to apply LLM cleanup after conversion.
	 * @throws \RuntimeException If conversion fails.
	 */
	public function convertUrlToPage( string $sourceUrl, string $pageName, bool $llmPolish = false, bool $overwrite = true ): array {
		$pandocOutput = $this->pandocWrapper->convertUrl( $sourceUrl );
		return $this->savePandocOutput( $pandocOutput, $pageName, $llmPolish, $overwrite );
	}

	/**
	 * Process Pandoc output: upload extracted media, post-process wikitext, optionally polish
	 * with an LLM, then save the page.
	 *
	 * @param array  $pandocOutput  Return value of PandocWrapper::convertFile / convertUrl.
	 * @param string $pageName      Target wiki page title.
	 * @param bool   $llmPolish     Whether to run the LLM polishing step.
	 */
	private function savePandocOutput( array $pandocOutput, string $pageName, bool $llmPolish = false, bool $overwrite = true ): array {
		try {
			$imagesVocabulary = $this->pandocWrapper->processImages(
				$pandocOutput['mediaFolder'],
				$pandocOutput['baseName']
			);
		} finally {
			$this->pandocWrapper->deleteDirectory( $pandocOutput['mediaFolder'] );
		}

		$postprocessedText = PandocTextPostprocessor::postprocess(
			$pandocOutput['text'],
			$imagesVocabulary
		);

		if ( $llmPolish ) {
			$llmService = LlmPolishService::newFromConfig( $this->config );
			if ( $llmService !== null ) {
				$postprocessedText = $llmService->polish( $postprocessedText );
			}
		}

		return PageImporter::save( $this->user, $pageName, $postprocessedText,
			wfMessage( 'pandocultimateconverter-history-comment' )->text(), $overwrite );
	}
}
