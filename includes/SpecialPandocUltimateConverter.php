<?php
namespace MediaWiki\Extension\PandocUltimateConverter;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\CommentStore\CommentStoreComment;

class SpecialPandocUltimateConverter extends SpecialPage
{
	private static $TITLE_MIN_LENGTH = 4;
	private static $TITLE_MAX_LENGTH = 255;

	function __construct()
	{
		parent::__construct('PandocUltimateConverter', '', false);
	}

	protected function getGroupName()
	{
		return 'media';
	}


	function execute($par)
	{
		$this->setHeaders();
		$this->checkPermissions();

		$output = $this->getOutput();
		$output->addModules("ext.PandocUltimateConverter");

		$wikitext = wfMessage("pandocultimateconverter-special-upload-description");
		$output->addWikiTextAsInterface($wikitext);

		$formDescriptor = [
			'UploadFile' => [
				'class' => \UploadSourceField::class,
				'section' => 'pandocultimateconverter-special-upload-file-section',
				'type' => 'file',
				'id' => 'wpUploadFile',
				'radio-id' => 'wpSourceTypeFile',
				'label-message' => 'pandocultimateconverter-special-upload-file',
				'upload-type' => 'File'
			],
			'UploadedFileName' => [
				'type' => 'text',
				'id' => 'wpUploadedFileName',
				'class' => 'HTMLHiddenField',
				'section' => 'pandocultimateconverter-special-upload-target-page-section',
			],
			'ConvertToArticleName' => [
				'type' => 'text',
				'id' => 'wpArticleTitle',
				'size' => 80,
				'placeholder' => "Type in the title for the article created here. Existing article will get overwritten.",
				'section' => 'pandocultimateconverter-special-upload-target-page-section',
				'default' => 'test'
			]
		];

		$context = \RequestContext::getMain();
		$htmlForm = \HTMLForm::factory(
			'table',
			$formDescriptor,
			$context
		);
		$htmlForm->setSubmitText(wfMessage("pandocultimateconverter-special-upload-button-label"));
		$htmlForm->setId('mw-pandoc-upload-form');
		$htmlForm->setSubmitID('mw-pandoc-upload-form-submit');
		$htmlForm->setSubmitCallback([$this, 'processForm']);
		$htmlForm->setTitle($this->getPageTitle()); // Remove subpage

		$htmlForm->show();
	}

	public static function processForm($formData)
	{
		try {
			$fileName = $formData['UploadedFileName'];
			$pageName = self::getArticleTitle($formData['ConvertToArticleName']);

			self::convertFileToPage($fileName, $pageName);
			header('location: ' . Title::newFromText($pageName)->getFullUrl());
		} catch (\Exception $e) {
			throw $e;
			exit;
		} finally {
			// TODO: ideally do file deletion here			
		}
	}


	private static function getArticleTitle($titleString)
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

	public static function convertFileToPage($fileName, $pageName)
	{
		$services = MediaWikiServices::getInstance();
		$titleFactory = $services->getWikiPageFactory();

		$context = \RequestContext::getMain();
		$user = $context->getUser();

		$repoGroup = $services->getRepoGroup();
		$fileTitle =  Title::newFromTextThrow( $fileName, NS_FILE);
		
		$localFile = $repoGroup->findFile($fileTitle);
		$filePath = $localFile->getLocalRefPath(); 
		
		// Run pandoc executable
		$pandocOutput = PandocWrapper::convert($filePath);
		// Media processing
		try{
			$imagesVocabulary = PandocWrapper::processImages($pandocOutput['mediaFolder'], $pandocOutput['baseName'], $user);
		} catch (\Exception $e) {
			throw $e;
		}
		finally{
			PandocWrapper::deleteDirectory($pandocOutput['mediaFolder']);
		}
		
		// Text postprocessing
		$postprocessedText = PandocTextPostporcessor::postprocess($pandocOutput['text'], $imagesVocabulary);
		
		// Save page
		$title =  Title::newFromText( $pageName );
		$pageUpdater = $titleFactory->newFromTitle($title)->newPageUpdater($user);
		$content = new \WikitextContent($postprocessedText); 
		$pageUpdater->setContent(SlotRecord::MAIN, $content);
		$pageUpdater->saveRevision(CommentStoreComment::newUnsavedComment(wfMessage("pandocultimateconverter-history-comment")), EDIT_INTERNAL);

		// Delete file after conversion
		$delPageFactory = $services->getDeletePageFactory();
		$delPage = $delPageFactory->newDeletePage( $titleFactory->newFromTitle($fileTitle), $user );
		$status = $delPage
                ->forceImmediate( true )
                ->deleteUnsafe( wfMessage("pandocultimateconverter-conversion-complete-comment") ); 
		if ( !$status->isOK() ) {
			// TODO: error handling
		}
	}
}
