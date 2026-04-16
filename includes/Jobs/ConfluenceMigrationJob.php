<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Jobs;

use Job;
use MediaWiki\Extension\PandocUltimateConverter\ConfluenceClient;
use MediaWiki\Extension\PandocUltimateConverter\LlmPolishService;
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
 *  - pageList       (string)  Optional newline-separated page titles to import.
 *                             When empty all pages in the space are imported.
 *  - userId         (int)     MediaWiki user ID of the initiating user.
 */
class ConfluenceMigrationJob extends Job {

	public function __construct( \Title $title, array $params ) {
		parent::__construct( 'confluenceMigration', $title, $params );
		// Prevent duplicate jobs for the same space from piling up.
		$this->removeDuplicates = true;
	}

	/**
	 * Do not retry failed migration jobs — failures (invalid URL, auth errors,
	 * unreachable server) are typically permanent and retrying just keeps the
	 * job stuck in the queue.
	 */
	public function allowRetries(): bool {
		return false;
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
		$categorize    = (bool)(   $this->params['categorize']     ?? true );
		$llmPolish     = (bool)(   $this->params['llmPolish']      ?? false );
		$pageListRaw   = (string)( $this->params['pageList']       ?? '' );
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

		$llmService = null;
		if ( $llmPolish ) {
			$llmService = LlmPolishService::newFromConfig( $config );
		}

		try {
			if ( $pageListRaw !== '' ) {
				$patterns = array_values( array_filter(
					array_map( 'trim', explode( "\n", $pageListRaw ) ),
					static fn ( string $t ) => $t !== ''
				) );
				// If any pattern is a subtree pattern ("pageName/*") or a glob we need
				// the full page list to resolve the hierarchy / match all titles;
				// otherwise use the cheaper per-title lookup.
				// Note: "pageName/*" contains '*', so this check covers both cases.
				$hasWildcard = (bool)array_filter(
					$patterns,
					static fn ( string $p ) => strpos( $p, '*' ) !== false || strpos( $p, '?' ) !== false
				);
				if ( $hasWildcard ) {
					$allPages = $client->fetchAllPages( $spaceKey );
					$pages    = self::filterPagesByPatterns( $allPages, $patterns );
				} else {
					$pages = $client->fetchPagesByTitles( $spaceKey, $patterns );
				}
			} else {
				$pages = $client->fetchAllPages( $spaceKey );
			}
		} catch ( \RuntimeException $e ) {
			$msg = 'Could not fetch page list from Confluence: ' . $e->getMessage();
			wfDebugLog( 'PandocUltimateConverter', "ConfluenceMigrationJob: $msg" );
			$this->notifyError( $user, $spaceKey, $msg );
			$this->writeReportPage( $spaceKey, $confluenceUrl, $targetPrefix, 0, [], [], [], $msg, $services, $user, 0 );
			$this->setLastError( $msg );
			return false;
		}

		$migratedCount = 0;
		$migratedPages = [];
		$skippedEmpty  = [];
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
				$saved = $this->migratePage( $page, $pageTitle, $client, $wrapper, $services, $user, $llmService );
				if ( $saved ) {
					$migratedCount++;
					$migratedPages[] = $pageTitle;
				} else {
					$skippedEmpty[] = $page['title'];
				}
			} catch ( \RuntimeException $e ) {
				$errMsg = "Page '{$page['title']}': " . $e->getMessage();
				wfDebugLog( 'PandocUltimateConverter', "ConfluenceMigrationJob: $errMsg" );
				$errors[] = $errMsg;
				// Continue with remaining pages even if one fails.
			}
		}

		$this->notifyDone( $user, $spaceKey, $migratedCount, $errors );

		// Auto-categorization: create MediaWiki categories mirroring the
		// Confluence page hierarchy.
		$categoriesCreated = 0;
		if ( $categorize && $migratedCount > 0 ) {
			try {
				$categoriesCreated = $this->applyCategories(
					$pages, $targetPrefix, $services, $user
				);
			} catch ( \RuntimeException $e ) {
				wfDebugLog( 'PandocUltimateConverter',
					'ConfluenceMigrationJob: categorization error: ' . $e->getMessage()
				);
				$errors[] = 'Categorization: ' . $e->getMessage();
			}
		}

		$this->writeReportPage( $spaceKey, $confluenceUrl, $targetPrefix, $migratedCount, $migratedPages, $skippedEmpty, $errors, null, $services, $user, $categoriesCreated );

		return true;
	}

	// -----------------------------------------------------------------------
	// Page migration helpers
	// -----------------------------------------------------------------------

	/**
	 * Migrate a single Confluence page to a MediaWiki page.
	 *
	 * @param array{id: string, title: string} $page
	 * @return bool True if the page was saved, false if skipped (empty body).
	 * @throws \RuntimeException On conversion or save failure.
	 */
	private function migratePage(
		array $page,
		string $pageTitle,
		ConfluenceClient $client,
		PandocWrapper $wrapper,
		MediaWikiServices $services,
		mixed $user,
		?LlmPolishService $llmService = null
	): bool {
		// 1. Fetch Confluence storage-format HTML.
		$html = $client->fetchPageBody( $page['id'] );
		if ( $html === '' ) {
			wfDebugLog( 'PandocUltimateConverter', "ConfluenceMigrationJob: page '{$page['title']}' has empty body, skipping" );
			return false;
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
				$this->migrateAttachments( $page['id'], $client, $wrapper, $pandocOutput['baseName'], $services, $user )
			);
		} finally {
			PandocWrapper::deleteDirectory( $pandocOutput['mediaFolder'] );
		}

		// 5. Post-process wikitext and save the page (first revision — raw conversion).
		$wikitext = PandocTextPostprocessor::postprocess( $pandocOutput['text'], $imagesVocabulary );
		$this->savePage( $pageTitle, $wikitext, $services, $user );

		// 6. Optional LLM polish — saved as a second revision so the raw
		//    conversion is always preserved in page history.
		if ( $llmService !== null ) {
			try {
				$polished = $llmService->polish( $wikitext );
				$this->savePage( $pageTitle, $polished, $services, $user,
					wfMessage( 'confluencemigration-llm-history-comment' )->text()
				);
			} catch ( \RuntimeException $e ) {
				wfDebugLog( 'PandocUltimateConverter',
					"ConfluenceMigrationJob: LLM polish failed for '{$page['title']}': " . $e->getMessage()
				);
				// The raw conversion is already saved — nothing else to do.
			}
		}

		return true;
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
		string $baseName,
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

			return $wrapper->processImages( $tempDir, $baseName );
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

		// <ac:image …><ri:attachment ri:filename="X" /></ac:image> → <img src="X" />
		// Must run BEFORE the generic <ac:*>/<ri:*> stripping so image refs survive.
		$html = preg_replace_callback(
			'/<ac:image([^>]*)>\s*<ri:attachment\s+[^>]*ri:filename="([^"]+)"[^>]*(?:\/>|>[\s\S]*?<\/ri:attachment>)\s*<\/ac:image>/si',
			static function ( array $m ): string {
				$attrs    = $m[1];
				$filename = htmlspecialchars( $m[2], ENT_QUOTES );
				$imgAttrs = "src=\"$filename\"";
				if ( preg_match( '/ac:alt="([^"]*)"/', $attrs, $a ) ) {
					$imgAttrs .= ' alt="' . htmlspecialchars( $a[1], ENT_QUOTES ) . '"';
				}
				if ( preg_match( '/ac:width="(\d+)"/', $attrs, $w ) ) {
					$imgAttrs .= ' width="' . $w[1] . '"';
				}
				if ( preg_match( '/ac:height="(\d+)"/', $attrs, $h ) ) {
					$imgAttrs .= ' height="' . $h[1] . '"';
				}
				return "<img $imgAttrs />";
			},
			$html
		) ?? $html;

		// <ac:image …><ri:url ri:value="URL" /></ac:image> → <img src="URL" />
		$html = preg_replace_callback(
			'/<ac:image([^>]*)>\s*<ri:url\s+[^>]*ri:value="([^"]+)"[^>]*(?:\/>|>[\s\S]*?<\/ri:url>)\s*<\/ac:image>/si',
			static function ( array $m ): string {
				$attrs    = $m[1];
				$url      = htmlspecialchars( $m[2], ENT_QUOTES );
				$imgAttrs = "src=\"$url\"";
				if ( preg_match( '/ac:alt="([^"]*)"/', $attrs, $a ) ) {
					$imgAttrs .= ' alt="' . htmlspecialchars( $a[1], ENT_QUOTES ) . '"';
				}
				if ( preg_match( '/ac:width="(\d+)"/', $attrs, $w ) ) {
					$imgAttrs .= ' width="' . $w[1] . '"';
				}
				if ( preg_match( '/ac:height="(\d+)"/', $attrs, $h ) ) {
					$imgAttrs .= ' height="' . $h[1] . '"';
				}
				return "<img $imgAttrs />";
			},
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
	 * Filter a flat list of Confluence pages by user-supplied patterns.
	 *
	 * Each pattern may be:
	 *  - An exact page title (no wildcard characters): only that page is selected.
	 *  - A subtree pattern "pageName/*": the page whose title exactly matches
	 *    "pageName" is selected together with ALL of its descendants in the
	 *    Confluence hierarchy (direct and indirect children at every level).
	 *  - A glob-style pattern containing '*' or '?' (but not ending with '/*'):
	 *    every page whose title matches the glob is selected (title matching only,
	 *    no automatic descendant expansion).
	 *
	 * @param list<array{id: string, title: string, parentId: string|null}> $allPages
	 * @param string[] $patterns
	 * @return list<array{id: string, title: string, parentId: string|null}>
	 */
	public static function filterPagesByPatterns( array $allPages, array $patterns ): array {
		if ( $allPages === [] || $patterns === [] ) {
			return [];
		}

		// Build lookup and parent→children maps.
		$byId     = [];
		$children = [];
		foreach ( $allPages as $page ) {
			$byId[ $page['id'] ] = $page;
			if ( $page['parentId'] !== null ) {
				$children[ $page['parentId'] ][] = $page['id'];
			}
		}

		/** @var array<string, array{id: string, title: string, parentId: string|null}> $matched id → page */
		$matched = [];

		foreach ( $patterns as $pattern ) {
			$pattern = trim( $pattern );
			if ( $pattern === '' ) {
				continue;
			}

			// "pageName/*" — match pageName exactly and include the full subtree.
			if ( substr( $pattern, -2 ) === '/*' ) {
				$baseName = substr( $pattern, 0, -2 );
				if ( $baseName === '' ) {
					continue;
				}
				foreach ( $allPages as $page ) {
					if ( $page['title'] === $baseName ) {
						$matched[ $page['id'] ] = $page;
						self::collectDescendants( $page['id'], $children, $byId, $matched );
					}
				}
				continue;
			}

			// Glob or exact match — title matching only, no descendant expansion.
			foreach ( $allPages as $page ) {
				if ( fnmatch( $pattern, $page['title'] ) ) {
					$matched[ $page['id'] ] = $page;
				}
			}
		}

		return array_values( $matched );
	}

	/**
	 * Recursively add all descendants of $pageId to $matched.
	 *
	 * @param string                                                                     $pageId   ID of the root page whose descendants to collect
	 * @param array<string, list<string>>                                                $children id → child id list
	 * @param array<string, array{id: string, title: string, parentId: string|null}>     $byId     id → page
	 * @param array<string, array{id: string, title: string, parentId: string|null}>     $matched  id → page (mutated in place)
	 */
	private static function collectDescendants(
		string $pageId,
		array $children,
		array $byId,
		array &$matched
	): void {
		foreach ( $children[ $pageId ] ?? [] as $childId ) {
			if ( !isset( $matched[ $childId ] ) && isset( $byId[ $childId ] ) ) {
				$matched[ $childId ] = $byId[ $childId ];
				self::collectDescendants( $childId, $children, $byId, $matched );
			}
		}
	}

	// -----------------------------------------------------------------------
	// Auto-categorization
	// -----------------------------------------------------------------------

	/**
	 * Create MediaWiki categories that mirror the Confluence page hierarchy.
	 *
	 * For every page that has sub-pages a category is created with the same
	 * name.  The page itself and each of its direct children are added to
	 * that category.  When a child page also has children its own category
	 * page is placed inside the parent category, producing nested categories.
	 *
	 * @param list<array{id: string, title: string, parentId: string|null}> $pages
	 * @return int Number of category pages created/updated.
	 */
	private function applyCategories(
		array $pages,
		string $targetPrefix,
		MediaWikiServices $services,
		mixed $user
	): int {
		// Build lookup maps.
		$pagesById  = [];    // id → page array
		$childrenOf = [];    // parentId → [ childId, … ]
		// Map Confluence page id → wiki page title used during migration.
		$wikiTitles = [];

		foreach ( $pages as $page ) {
			$pagesById[ $page['id'] ] = $page;
			$wikiTitles[ $page['id'] ] = $this->buildPageTitle( $page['title'], $targetPrefix );
			if ( $page['parentId'] !== null && isset( $pagesById[ $page['parentId'] ] ) ) {
				$childrenOf[ $page['parentId'] ][] = $page['id'];
			}
		}

		// Second pass: some children may appear before their parents in the
		// flat list, so re-check parentId links now that all pages are indexed.
		foreach ( $pages as $page ) {
			if (
				$page['parentId'] !== null
				&& isset( $pagesById[ $page['parentId'] ] )
				&& !isset( $childrenOf[ $page['parentId'] ] )
			) {
				$childrenOf[ $page['parentId'] ] = [];
			}
			if (
				$page['parentId'] !== null
				&& isset( $pagesById[ $page['parentId'] ] )
				&& !in_array( $page['id'], $childrenOf[ $page['parentId'] ] ?? [], true )
			) {
				$childrenOf[ $page['parentId'] ][] = $page['id'];
			}
		}

		// Identify parent pages (pages that have at least one child within the space).
		$parentIds = array_keys( $childrenOf );

		if ( $parentIds === [] ) {
			return 0;
		}

		$categorySummary = wfMessage( 'confluencemigration-category-comment' )->text();
		$categoriesCreated = 0;

		foreach ( $parentIds as $parentId ) {
			$parentTitle = $wikiTitles[ $parentId ];
			$categoryTag = "\n[[" . 'Category:' . $parentTitle . ']]';

			// Append category tag to the parent page itself.
			$this->appendToPage( $parentTitle, $categoryTag, $services, $user, $categorySummary );

			// Append category tag to each direct child page.
			foreach ( $childrenOf[ $parentId ] as $childId ) {
				$childTitle = $wikiTitles[ $childId ];
				$this->appendToPage( $childTitle, $categoryTag, $services, $user, $categorySummary );
			}

			// Create (or update) the category page.
			$categoryPageTitle = 'Category:' . $parentTitle;

			// If this parent is itself a child of another parent that also
			// has children, nest the category inside the grandparent category.
			$parentPage   = $pagesById[ $parentId ];
			$grandparent  = $parentPage['parentId'];
			$categoryBody = '';
			if ( $grandparent !== null && isset( $childrenOf[ $grandparent ] ) ) {
				$grandparentTitle = $wikiTitles[ $grandparent ];
				$categoryBody = '[[Category:' . $grandparentTitle . ']]';
			}

			$this->savePage( $categoryPageTitle, $categoryBody, $services, $user, $categorySummary );
			$categoriesCreated++;
		}

		return $categoriesCreated;
	}

	/**
	 * Append text to an existing wiki page (creates a new revision).
	 *
	 * If the page does not exist the append is silently skipped — this can
	 * happen when the page was skipped during migration (e.g. already existed
	 * and overwrite was disabled).
	 */
	private function appendToPage(
		string $pageTitle,
		string $textToAppend,
		MediaWikiServices $services,
		mixed $user,
		string $summary
	): void {
		$title = \Title::newFromText( $pageTitle );
		if ( $title === null || !$title->exists() ) {
			return;
		}

		$wikiPage = $services->getWikiPageFactory()->newFromTitle( $title );
		$content  = $wikiPage->getContent();
		if ( !( $content instanceof \WikitextContent ) ) {
			return;
		}

		$existingText = $content->getText();

		// Avoid adding a duplicate category tag if one is already present.
		if ( str_contains( $existingText, trim( $textToAppend ) ) ) {
			return;
		}

		$newText = $existingText . $textToAppend;
		$this->savePage( $pageTitle, $newText, $services, $user, $summary );
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
		mixed $user,
		?string $summary = null
	): void {
		$title = \Title::newFromText( $pageTitle );
		if ( $title === null ) {
			throw new \RuntimeException( "Invalid page title: $pageTitle" );
		}

		if ( $summary === null ) {
			$summary = wfMessage( 'confluencemigration-history-comment' )->text();
		}

		$wikiPage    = $services->getWikiPageFactory()->newFromTitle( $title );
		$pageUpdater = $wikiPage->newPageUpdater( $user );
		$content     = new \WikitextContent( $wikitext );
		$pageUpdater->setContent( SlotRecord::MAIN, $content );
		$pageUpdater->saveRevision(
			\CommentStoreComment::newUnsavedComment( $summary ),
			EDIT_INTERNAL
		);
	}

	// -----------------------------------------------------------------------
	// Report page
	// -----------------------------------------------------------------------

	/**
	 * Write a migration report to a wiki page.
	 *
	 * @param string[] $migratedPages Titles of successfully migrated pages.
	 * @param string[] $skippedEmpty  Confluence page titles skipped due to empty body.
	 * @param string[] $errors Per-page error messages.
	 * @param string|null $fatalError If set, the migration failed entirely.
	 */
	private function writeReportPage(
		string $spaceKey,
		string $confluenceUrl,
		string $targetPrefix,
		int $migratedCount,
		array $migratedPages,
		array $skippedEmpty,
		array $errors,
		?string $fatalError,
		MediaWikiServices $services,
		mixed $user,
		int $categoriesCreated = 0
	): void {
		$datetime = date( 'Y-m-d H:i:s' );
		$pageTitle = "Migration from Confluence - $datetime";

		$lines = [];
		$lines[] = '== Migration Report ==';
		$lines[] = '';
		$lines[] = "* '''Space Key:''' $spaceKey";
		$lines[] = "* '''Confluence URL:''' $confluenceUrl";
		if ( $targetPrefix !== '' ) {
			$lines[] = "* '''Target Prefix:''' $targetPrefix";
		}
		$lines[] = "* '''Date:''' $datetime";
		$lines[] = '';

		if ( $fatalError !== null ) {
			$lines[] = '=== Fatal Error ===';
			$lines[] = '<div class="error">' . htmlspecialchars( $fatalError ) . '</div>';
		} else {
			$lines[] = "* '''Pages migrated:''' $migratedCount";
			if ( $categoriesCreated > 0 ) {
				$lines[] = "* '''Categories created:''' $categoriesCreated";
			}
			if ( count( $errors ) > 0 ) {
				$lines[] = "* '''Errors:''' " . count( $errors );
				$lines[] = '';
				$lines[] = '=== Errors ===';
				foreach ( $errors as $err ) {
					$lines[] = '* ' . htmlspecialchars( $err );
				}
			} else {
				$lines[] = '';
				$lines[] = 'Migration completed successfully with no errors.';
			}

			if ( count( $migratedPages ) > 0 ) {
				$lines[] = '';
				$lines[] = '=== Migrated pages ===';
				foreach ( $migratedPages as $mp ) {
					$lines[] = '* [[' . $mp . ']]';
				}
			}

			if ( count( $skippedEmpty ) > 0 ) {
				$lines[] = '';
				$lines[] = '=== Skipped (empty body) ===';
				foreach ( $skippedEmpty as $sp ) {
					$lines[] = '* ' . htmlspecialchars( $sp );
				}
			}
		}

		$wikitext = implode( "\n", $lines );

		try {
			$this->savePage( $pageTitle, $wikitext, $services, $user );
		} catch ( \RuntimeException $e ) {
			wfDebugLog( 'PandocUltimateConverter',
				'ConfluenceMigrationJob: failed to write report page: ' . $e->getMessage()
			);
		}
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
