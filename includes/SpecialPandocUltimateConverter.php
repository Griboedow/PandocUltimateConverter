<?php

namespace MediaWiki\Extension\PandocUltimateConverter;

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;

class SpecialPandocUltimateConverter extends \SpecialPage
{
	private static $TITLE_MIN_LENGTH = 4;
	private static $TITLE_MAX_LENGTH = 255;

	// context
	private $context;
	private $mwServices;
	private $user;
	private $titleFactory;
	private $repoGroup;

	//Config
	private $config;

	// Helpers
	private $pandocWrapper;

	function __construct()
	{
		// Get custom permissions if exist
		$mwConfig = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig('PandocUltimateConverter');
		$userRight = $mwConfig->get('PandocUltimateConverter_PandocCustomUserRight') ?? '';

		parent::__construct('PandocUltimateConverter', $userRight);

		$this->context = \RequestContext::getMain();
		$this->user = $this->context->getUser();
		$this->mwServices = MediaWikiServices::getInstance();
		$this->titleFactory = $this->mwServices->getWikiPageFactory();
		$this->repoGroup = $this->mwServices->getRepoGroup();

		$this->config = $mwConfig;

		$this->pandocWrapper = new PandocWrapper($this->config, $this->mwServices, $this->user);
	}

	protected function getGroupName()
	{
		return 'media';
	}


	function  execute($par)
	{
		$this->setHeaders();
		$this->checkPermissions();

		$output = $this->getOutput();
		$output->addModules("ext.PandocUltimateConverter");

		$wikitext = wfMessage("pandocultimateconverter-special-upload-description");
		$output->addWikiTextAsInterface($wikitext);

		$formDescriptor = [
			'SourceType' => [
				'section' => 'pandocultimateconverter-special-upload-file-section',
				'type' => 'select',
				'id' => 'wpConvertSourceType',
				'label' => 'Type: ',
				// The options available within the menu (displayed => value)
				'options' => [
					'File' => 'file',
					'URL' => 'url',
				],
			],
			'UploadFile' => [
				'class' => \UploadSourceField::class,
				'section' => 'pandocultimateconverter-special-upload-file-section',
				'type' => 'file',
				'id' => 'wpUploadFile',
				'radio-id' => 'wpSourceTypeFile',
				'label-message' => 'pandocultimateconverter-special-upload-file',
				'upload-type' => 'File',
				'hide-if' => [
					'!==',
					'SourceType',
					'file',
				],
			],
			'UploadedFileName' => [
				'section' => 'pandocultimateconverter-special-upload-file-section',
				'type' => 'text',
				'id' => 'wpUploadedFileName',
				'class' => 'HTMLHiddenField',
				'section' => 'pandocultimateconverter-special-upload-target-page-section',
			],
			//todo: label-message
			'SourceUrl' => [
				'section' => 'pandocultimateconverter-special-upload-file-section',
				'type' => 'url',
				'size' => 80,
				'id' => 'wpUrlToConvert',
				'name' => 'web-url',
				'label' => 'URL: ',
				'hide-if' => [
					'!==',
					'SourceType',
					'url',
				],
			],
			//todo: label-message
			'ConvertToArticleName' => [
				'type' => 'title',
				'id' => 'wpArticleTitle',
				'name' => 'page-title',
				'label' => 'Page: ',
				'size' => 80,
				'placeholder' => "Type in the title for the article created here. Existing article will get overwritten.",
				'section' => 'pandocultimateconverter-special-upload-target-page-section',
				'default' => 'test'
			]
		];

		$htmlForm = \HTMLForm::factory(
			'table',
			$formDescriptor,
			$this->context
		);
		$htmlForm->setSubmitText(wfMessage("pandocultimateconverter-special-upload-button-label"));
		$htmlForm->setId('mw-pandoc-upload-form');
		$htmlForm->setSubmitID('mw-pandoc-upload-form-submit');
		$htmlForm->setSubmitCallback([$this, 'processForm']);
		$htmlForm->setTitle($this->getPageTitle()); // Remove subpage

		$htmlForm->show();
	}

	private function deleteFile($fileName)
	{
		$fileTitle =  \Title::newFromTextThrow($fileName, NS_FILE);
		$reason = wfMessage("pandocultimateconverter-conversion-complete-comment")->text();

		//Delete file itself if it is local
		try {
			$fileOnDisk = $this->repoGroup->findFile(
				$fileTitle,
				['ignoreRedirect' => true] // To be sure we don't remove smth useful
			);
			if ($fileOnDisk && $fileOnDisk->isLocal()) {
				$fileOnDisk->deleteFile($reason, $this->user);
				$fileOnDisk->purgeEverything();
			}
		} catch (\Exception $e) {
			//TODO: logging
			throw $e;
		}

		// Delete file page after the file itself is deleted
		try {

			$delPageFactory = $this->mwServices->getDeletePageFactory();
			$delPage = $delPageFactory->newDeletePage($this->titleFactory->newFromTitle($fileTitle), $this->user);
			$status = $delPage
				->forceImmediate(true)
				->deleteUnsafe($reason);
			if (!$status->isOK()) {
				// TODO: error handling
			}
		} catch (\Exception $e) {
			//TODO: logging
			throw $e;
		}
	}

	public function processForm($formData)
	{
		$sourceType = $formData['SourceType'];
		$pageName = $this->getArticleTitle($formData['ConvertToArticleName']);

		if ($sourceType == 'file') {
			try {
				$fileName = $formData['UploadedFileName'];

				self::convertFileToPage($fileName, $pageName);
				header('location: ' . \Title::newFromText($pageName)->getFullUrl());
			} catch (\Exception $e) {
				throw $e;
				exit;
			} finally {
				if ($fileName) {
					self::deleteFile($fileName);
				}
			}
			return;
		}

		if ($sourceType == 'url') {
			$sourceUrl = $formData['SourceUrl'];
			self::convertUrlToPage($sourceUrl, $pageName);
			header('location: ' . \Title::newFromText($pageName)->getFullUrl());
			return;
		}
	}


	private function getArticleTitle($titleString)
	{
		if (strlen($titleString) > self::$TITLE_MAX_LENGTH) {
			$titleString = substr($titleString, 0, self::$TITLE_MAX_LENGTH);
		}
		if (strlen($titleString) < self::$TITLE_MIN_LENGTH) {
			// TODO: move small title prefix to a param
			$titleString = "PandocUltimateConverter" . $titleString;
		}
		return $titleString;
	}


	private function convertPandocOutputToPageInternal($pandocOutput, $pageName)
	{
		// Media processing
		try {
			$imagesVocabulary = $this->pandocWrapper->processImages($pandocOutput['mediaFolder'], $pandocOutput['baseName']);
		} catch (\Exception $e) {
			throw $e;
		} finally {
			$this->pandocWrapper->deleteDirectory($pandocOutput['mediaFolder']);
		}

		// Text postprocessing
		$postprocessedText = PandocTextPostporcessor::postprocess($pandocOutput['text'], $imagesVocabulary);

		// Save page
		$title =  \Title::newFromText($pageName);
		$pageUpdater = $this->titleFactory->newFromTitle($title)->newPageUpdater($this->user);
		$content = new \WikitextContent($postprocessedText);
		$pageUpdater->setContent(SlotRecord::MAIN, $content);
		$pageUpdater->saveRevision(\CommentStoreComment::newUnsavedComment(wfMessage("pandocultimateconverter-history-comment")), EDIT_INTERNAL);
	}

	private function convertUrlToPage($sourceUrl, $pageName)
	{
		$pandocOutput = $this->pandocWrapper->convertUrl($sourceUrl);
		self::convertPandocOutputToPageInternal($pandocOutput, $pageName);
	}

	private function convertFileToPage($fileName, $pageName)
	{
		$fileTitle =  \Title::newFromTextThrow($fileName, NS_FILE);

		$localFile = $this->repoGroup->findFile($fileTitle);
		$filePath = $localFile->getLocalRefPath();

		$pandocOutput = $this->pandocWrapper->convertFile($filePath);
		self::convertPandocOutputToPageInternal($pandocOutput, $pageName);
	}
}
