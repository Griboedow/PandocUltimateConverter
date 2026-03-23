<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter;

use MediaWiki\Extension\PandocUltimateConverter\Processors\PandocTextPostprocessor;
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
	public function convertFileToPage( string $fileName, string $pageName, bool $llmPolish = false ): void {
		$fileTitle = \Title::newFromTextThrow( $fileName, NS_FILE );
		$localFile = $this->repoGroup->findFile( $fileTitle );

		if ( !$localFile || !$localFile->exists() ) {
			throw new \RuntimeException( "File not found in repository: $fileName" );
		}

		$filePath    = $localFile->getLocalRefPath();
		$pandocOutput = $this->pandocWrapper->convertFile( $filePath );
		$this->savePandocOutput( $pandocOutput, $pageName, $llmPolish );
	}

	/**
	 * Fetch a URL and convert it to a wiki page.
	 *
	 * @param string $sourceUrl  URL to fetch and convert.
	 * @param string $pageName   Target wiki page title.
	 * @param bool   $llmPolish  Whether to apply LLM cleanup after conversion.
	 * @throws \RuntimeException If conversion fails.
	 */
	public function convertUrlToPage( string $sourceUrl, string $pageName, bool $llmPolish = false ): void {
		$pandocOutput = $this->pandocWrapper->convertUrl( $sourceUrl );
		$this->savePandocOutput( $pandocOutput, $pageName, $llmPolish );
	}

	/**
	 * Process Pandoc output: upload extracted media, post-process wikitext, optionally polish
	 * with an LLM, then save the page.
	 *
	 * @param array  $pandocOutput  Return value of PandocWrapper::convertFile / convertUrl.
	 * @param string $pageName      Target wiki page title.
	 * @param bool   $llmPolish     Whether to run the LLM polishing step.
	 */
	private function savePandocOutput( array $pandocOutput, string $pageName, bool $llmPolish = false ): void {
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

		$title       = \Title::newFromText( $pageName );
		$pageUpdater = $this->titleFactory->newFromTitle( $title )->newPageUpdater( $this->user );
		$content     = new \WikitextContent( $postprocessedText );
		$pageUpdater->setContent( SlotRecord::MAIN, $content );
		$pageUpdater->saveRevision(
			\CommentStoreComment::newUnsavedComment( wfMessage( 'pandocultimateconverter-history-comment' ) ),
			EDIT_INTERNAL
		);
	}
}
