<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Jobs;

use Job;
use MediaWiki\Extension\PandocUltimateConverter\ConfluenceClient;
use MediaWiki\Extension\PandocUltimateConverter\PandocWrapper;
use MediaWiki\Extension\PandocUltimateConverter\Processors\PandocTextPostprocessor;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;

/**
 * Background job that migrates all pages from a Confluence space into this wiki.
 *
 * The job is queued by Special:ConfluenceMigration and runs asynchronously
 * via the MediaWiki job queue (runJobs.php / maintenance/runJobs.php).
 *
 * For each Confluence page the job:
 *  1. Fetches the page body in Confluence storage format (XHTML).
 *  2. Writes the HTML to a temporary file.
 *  3. Calls Pandoc (--from=html --to=mediawiki) via PandocWrapper.
 *  4. Uploads any extracted media files to the wiki.
 *  5. Saves the resulting wikitext as a new or updated wiki page.
 *
 * Additionally, file attachments are downloaded from Confluence and uploaded
 * to the MediaWiki file repository so that [[File:…]] links remain valid.
 *
 * When the job finishes (successfully or with an error) an Echo notification
 * is sent to the user who initiated the migration, if the Echo extension is
 * installed.
 *
 * Job parameters (all required unless noted):
 *  - confluenceUrl  (string)  Confluence base URL.
 *  - spaceKey       (string)  Confluence space key.
 *  - apiUser        (string)  Email (Cloud) or username (Server).
 *  - apiToken       (string)  API token (Cloud) or password/PAT (Server).
 *  - targetPrefix   (string)  Optional wiki page prefix (e.g. "Confluence/DOCS").
 *  - overwrite      (bool)    Whether to overwrite existing wiki pages.
 *  - userId         (int)     MediaWiki user ID of the initiating user.
 */
class ConfluenceMigrationJob extends Job {

	public function __construct( \Title $title, array $params ) {
		parent::__construct( 'confluenceMigration', $title, $params );
		// Prevent duplicate jobs for the same space from piling up.
		$this->removeDuplicates = true;
	}

	// -----------------------------------------------------------------------
	// Job execution
	// -----------------------------------------------------------------------

	/** @inheritDoc */
	public function run(): bool {
		$confluenceUrl = (string)( $this->params['confluenceUrl'] ?? '' );
		$spaceKey      = (string)( $this->params['spaceKey']      ?? '' );
		$apiUser       = (string)( $this->params['apiUser']        ?? '' );
		$apiToken      = (string)( $this->params['apiToken']       ?? '' );
		$targetPrefix  = (string)( $this->params['targetPrefix']   ?? '' );
		$overwrite     = (bool)(   $this->params['overwrite']      ?? false );
		$userId        = (int)(    $this->params['userId']         ?? 0 );

		if ( $confluenceUrl === '' || $spaceKey === '' ) {
			$this->setLastError( 'ConfluenceMigrationJob: missing confluenceUrl or spaceKey' );
			return false;
		}

		$services = MediaWikiServices::getInstance();
		$config   = $services->getConfigFactory()->makeConfig( 'PandocUltimateConverter' );
		$user     = $services->getUserFactory()->newFromId( $userId );

		$client  = new ConfluenceClient( $confluenceUrl, $apiUser, $apiToken );
		$wrapper = new PandocWrapper( $config, $services, $user );

		try {
			$pages = $client->fetchAllPages( $spaceKey );
		} catch ( \RuntimeException $e ) {
			$msg = 'Could not fetch page list from Confluence: ' . $e->getMessage();
			wfDebugLog( 'PandocUltimateConverter', "ConfluenceMigrationJob: $msg" );
			$this->notifyError( $user, $spaceKey, $msg );
			$this->setLastError( $msg );
			return false;
		}

		$migratedCount = 0;
		$errors        = [];

		foreach ( $pages as $page ) {
			$pageTitle = $this->buildPageTitle( $page['title'], $targetPrefix );

			// Skip if page exists and overwrite is disabled.
			$titleObj = \Title::newFromText( $pageTitle );
			if ( $titleObj !== null && $titleObj->exists() && !$overwrite ) {
				wfDebugLog( 'PandocUltimateConverter', "ConfluenceMigrationJob: skipping existing page '$pageTitle'" );
				continue;
			}

			try {
				$this->migratePage( $page, $pageTitle, $client, $wrapper, $services, $user );
				$migratedCount++;
			} catch ( \RuntimeException $e ) {
				$errMsg = "Page '{$page['title']}': " . $e->getMessage();
				wfDebugLog( 'PandocUltimateConverter', "ConfluenceMigrationJob: $errMsg" );
				$errors[] = $errMsg;
				// Continue with remaining pages even if one fails.
			}
		}

		$this->notifyDone( $user, $spaceKey, $migratedCount, $errors );

		return true;
	}

	// -----------------------------------------------------------------------
	// Page migration helpers
	// -----------------------------------------------------------------------

	/**
	 * Migrate a single Confluence page to a MediaWiki page.
	 *
	 * @param array{id: string, title: string} $page
	 * @throws \RuntimeException On conversion or save failure.
	 */
	private function migratePage(
		array $page,
		string $pageTitle,
		ConfluenceClient $client,
		PandocWrapper $wrapper,
		MediaWikiServices $services,
		mixed $user
	): void {
		// 1. Fetch Confluence storage-format HTML.
		$html = $client->fetchPageBody( $page['id'] );
		if ( $html === '' ) {
			wfDebugLog( 'PandocUltimateConverter', "ConfluenceMigrationJob: page '{$page['title']}' has empty body, skipping" );
			return;
		}

		// 2. Pre-process Confluence-specific XML elements to plain HTML so that
		//    Pandoc can understand the content.
		$html = $this->sanitizeConfluenceHtml( $html );

		// 3. Write the HTML to a temporary file and run Pandoc on it.
		$tempFile = tempnam( sys_get_temp_dir(), 'confluence_' ) . '.html';
		try {
			file_put_contents( $tempFile, $html );

			// baseName is used as the prefix for extracted media files.
			$baseName     = preg_replace( '/[^A-Za-z0-9_\-]/', '_', $page['title'] ) ?: ( 'confluence_page_' . $page['id'] );
			$pandocOutput = $wrapper->convertInternal( $tempFile, $baseName, 'html' );
		} finally {
			if ( file_exists( $tempFile ) ) {
				unlink( $tempFile );
			}
		}

		// 4. Upload extracted media; also download and upload Confluence attachments.
		try {
			$imagesVocabulary = $wrapper->processImages(
				$pandocOutput['mediaFolder'],
				$pandocOutput['baseName']
			);
			$imagesVocabulary = array_merge(
				$imagesVocabulary,
				$this->migrateAttachments( $page['id'], $client, $wrapper, $services, $user )
			);
		} finally {
			PandocWrapper::deleteDirectory( $pandocOutput['mediaFolder'] );
		}

		// 5. Post-process wikitext and save the page.
		$wikitext = PandocTextPostprocessor::postprocess( $pandocOutput['text'], $imagesVocabulary );

		$this->savePage( $pageTitle, $wikitext, $services, $user );
	}

	/**
	 * Download Confluence attachments and upload them to the MediaWiki file repository.
	 *
	 * Returns a vocabulary map (original filename → MediaWiki file title) that
	 * can be passed to PandocTextPostprocessor::postprocess().
	 *
	 * @return array<string, string>
	 */
	private function migrateAttachments(
		string $pageId,
		ConfluenceClient $client,
		PandocWrapper $wrapper,
		MediaWikiServices $services,
		mixed $user
	): array {
		$attachments = $client->fetchAttachments( $pageId );
		if ( $attachments === [] ) {
			return [];
		}

		// Create a temporary directory to hold the downloaded attachments.
		// Sanitize the page ID to avoid any path traversal concerns.
		$safePageId = preg_replace( '/[^0-9A-Za-z_\-]/', '', $pageId );
		$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'confluence_att_' . $safePageId;
		if ( !is_dir( $tempDir ) ) {
			mkdir( $tempDir, 0755, true );
		}

		try {
			foreach ( $attachments as $attachment ) {
				$filePath = $tempDir . DIRECTORY_SEPARATOR . $attachment['title'];
				try {
					$fileBytes = $client->downloadFile( $attachment['downloadUrl'] );
					file_put_contents( $filePath, $fileBytes );
				} catch ( \RuntimeException $e ) {
					wfDebugLog( 'PandocUltimateConverter', 'ConfluenceMigrationJob: failed to download attachment "' . $attachment['title'] . '": ' . $e->getMessage() );
				}
			}

			return $wrapper->processImages( $tempDir, 'confluence' );
		} finally {
			PandocWrapper::deleteDirectory( $tempDir );
		}
	}

	/**
	 * Remove or replace Confluence-specific XML/HTML elements that Pandoc cannot process.
	 *
	 * Confluence "storage format" contains custom tags like <ac:structured-macro>,
	 * <ri:attachment>, and <ac:image>.  This method converts the most common ones
	 * to their nearest HTML equivalent so Pandoc produces reasonable output.
	 */
	private function sanitizeConfluenceHtml( string $html ): string {
		// Wrap content in a minimal HTML document so Pandoc handles it correctly.
		if ( stripos( $html, '<html' ) === false ) {
			$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
		}

		// <ac:structured-macro ac:name="code"> … <ac:plain-text-body>…</ac:plain-text-body> → <pre>
		$html = preg_replace(
			'/<ac:structured-macro[^>]*ac:name="code"[^>]*>.*?<ac:plain-text-body>(.*?)<\/ac:plain-text-body>.*?<\/ac:structured-macro>/si',
			'<pre>$1</pre>',
			$html
		) ?? $html;

		// <ac:structured-macro ac:name="info/note/warning/tip"> → <blockquote>
		$html = preg_replace(
			'/<ac:structured-macro[^>]*ac:name="(?:info|note|warning|tip)"[^>]*>(.*?)<\/ac:structured-macro>/si',
			'<blockquote>$1</blockquote>',
			$html
		) ?? $html;

		// Strip remaining <ac:*> and <ri:*> tags (pass their inner text through).
		$html = preg_replace( '/<\/?ac:[^>]*>/si', '', $html ) ?? $html;
		$html = preg_replace( '/<\/?ri:[^>]*>/si', '', $html ) ?? $html;

		return $html;
	}

	/**
	 * Build the target MediaWiki page title from the Confluence page title and prefix.
	 */
	private function buildPageTitle( string $confluenceTitle, string $prefix ): string {
		$title = $prefix !== '' ? $prefix . '/' . $confluenceTitle : $confluenceTitle;
		// MediaWiki page titles must not be longer than 255 bytes (not characters).
		if ( strlen( $title ) > 255 ) {
			$title = mb_strcut( $title, 0, 255, 'UTF-8' );
		}
		return $title;
	}

	/**
	 * Save wikitext to a MediaWiki page, creating or overwriting it.
	 *
	 * @throws \RuntimeException If the title is invalid or the save fails.
	 */
	private function savePage(
		string $pageTitle,
		string $wikitext,
		MediaWikiServices $services,
		mixed $user
	): void {
		$title = \Title::newFromText( $pageTitle );
		if ( $title === null ) {
			throw new \RuntimeException( "Invalid page title: $pageTitle" );
		}

		$wikiPage    = $services->getWikiPageFactory()->newFromTitle( $title );
		$pageUpdater = $wikiPage->newPageUpdater( $user );
		$content     = new \WikitextContent( $wikitext );
		$pageUpdater->setContent( SlotRecord::MAIN, $content );
		$pageUpdater->saveRevision(
			\CommentStoreComment::newUnsavedComment(
				wfMessage( 'confluencemigration-history-comment' )->text()
			),
			EDIT_INTERNAL
		);
	}

	// -----------------------------------------------------------------------
	// Echo notifications
	// -----------------------------------------------------------------------

	/**
	 * Send an Echo notification to the user when the migration is complete.
	 *
	 * @param string[] $errors Per-page error messages (empty = all succeeded).
	 */
	private function notifyDone( mixed $user, string $spaceKey, int $count, array $errors ): void {
		if ( !class_exists( 'EchoEvent' ) ) {
			return;
		}

		\EchoEvent::create( [
			'type'  => 'confluence-migration-done',
			'title' => \Title::newMainPage(),
			'extra' => [
				'spaceKey'      => $spaceKey,
				'migratedCount' => $count,
				'errorCount'    => count( $errors ),
			],
			'agent' => $user,
		] );
	}

	/**
	 * Send an Echo notification to the user when the migration fails entirely.
	 */
	private function notifyError( mixed $user, string $spaceKey, string $errorMessage ): void {
		if ( !class_exists( 'EchoEvent' ) ) {
			return;
		}

		\EchoEvent::create( [
			'type'  => 'confluence-migration-done',
			'title' => \Title::newMainPage(),
			'extra' => [
				'spaceKey'      => $spaceKey,
				'migratedCount' => 0,
				'errorCount'    => 1,
				'fatalError'    => $errorMessage,
			],
			'agent' => $user,
		] );
	}
}
