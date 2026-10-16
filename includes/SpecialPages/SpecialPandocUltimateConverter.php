<?php

declare(strict_types=1);

namespace MediaWiki\Extension\PandocUltimateConverter\SpecialPages;

use MediaWiki\Config\Config;
use MediaWiki\Title\Title;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\PandocUltimateConverter\LlmPolishService;
use MediaWiki\Extension\PandocUltimateConverter\PandocConverterService;
use MediaWiki\Extension\PandocUltimateConverter\PandocWrapper;
use MediaWiki\Extension\PandocUltimateConverter\Processors\PandocTextPostprocessor;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\SlotRecord;

class SpecialPandocUltimateConverter extends \SpecialPage
{
    private const TITLE_MIN_LENGTH = 4;
    private const TITLE_MAX_LENGTH = 255;

    private RequestContext $context;
    private MediaWikiServices $mwServices;
    /** @var \User */
    private $user;
    private WikiPageFactory $titleFactory;
    /** @var \RepoGroup */
    private $repoGroup;
    private Config $config;
    private PandocWrapper $pandocWrapper;
    private bool $useCodex;

    public function __construct()
    {
        $mwConfig  = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'PandocUltimateConverter' );
        $userRight = $mwConfig->get( 'PandocUltimateConverter_PandocCustomUserRight' ) ?? '';

        parent::__construct( 'PandocUltimateConverter', $userRight );

        $this->context      = RequestContext::getMain();
        $this->user         = $this->context->getUser();
        $this->mwServices   = MediaWikiServices::getInstance();
        $this->titleFactory = $this->mwServices->getWikiPageFactory();
        $this->repoGroup    = $this->mwServices->getRepoGroup();
        $this->config       = $mwConfig;
        $this->pandocWrapper = new PandocWrapper( $this->config, $this->mwServices, $this->user );
        // Codex UI is the default on MW 1.43+; use ?codex=0 to opt out
        $request = $this->context->getRequest();
        $mwVersion = defined( 'MW_VERSION' ) ? MW_VERSION : '0';
        $defaultCodex = version_compare( $mwVersion, '1.43', '>=' );
        $this->useCodex = $request->getRawVal( 'codex' ) !== null
            ? $request->getBool( 'codex' )
            : $defaultCodex;
    }

    protected function getGroupName(): string
    {
        return 'media';
    }

    public function execute( $par ): void
    {
        $this->setHeaders();
        $this->checkPermissions();

        $output = $this->getOutput();

        if ( $this->useCodex ) {
            $output->addModules( 'ext.PandocUltimateConverter.codex' );
            $llmAvailable = LlmPolishService::newFromConfig( $this->config ) !== null;
            $output->addJsConfigVars( [
                'pandocCodexTitleMinLength' => self::TITLE_MIN_LENGTH,
                'pandocCodexTitleMaxLength' => self::TITLE_MAX_LENGTH,
                'pandocCodexLlmAvailable'   => $llmAvailable,
            ] );
            $output->addHTML( Html::element( 'div', [ 'class' => 'mw-pandoc-codex-root' ] ) );
            return;
        }

        $output->addModules( 'ext.PandocUltimateConverter' );

        $output->addWikiTextAsInterface( wfMessage( 'pandocultimateconverter-special-upload-description' ) );

        $formDescriptor = [
            'SourceType' => [
                'section' => 'pandocultimateconverter-special-upload-file-section',
                'type'    => 'select',
                'id'      => 'wpConvertSourceType',
                'label'   => 'Type: ',
                'options' => [
                    'File' => 'file',
                    'URL'  => 'url',
                ],
            ],
            'UploadFile' => [
                'class'         => \UploadSourceField::class,
                'section'       => 'pandocultimateconverter-special-upload-file-section',
                'type'          => 'file',
                'id'            => 'wpUploadFile',
                'radio-id'      => 'wpSourceTypeFile',
                'label-message' => 'pandocultimateconverter-special-upload-file',
                'upload-type'   => 'File',
                'hide-if'       => [ '!==', 'SourceType', 'file' ],
            ],
            'UploadedFileName' => [
                'section' => 'pandocultimateconverter-special-upload-target-page-section',
                'type'    => 'text',
                'id'      => 'wpUploadedFileName',
                'class'   => 'HTMLHiddenField',
            ],
            'SourceUrl' => [
                'section' => 'pandocultimateconverter-special-upload-file-section',
                'type'    => 'url',
                'size'    => 80,
                'id'      => 'wpUrlToConvert',
                'name'    => 'web-url',
                'label'   => 'URL: ',
                'hide-if' => [ '!==', 'SourceType', 'url' ],
            ],
            'ConvertToArticleName' => [
                'section'     => 'pandocultimateconverter-special-upload-target-page-section',
                'type'        => 'title',
                'id'          => 'wpArticleTitle',
                'name'        => 'page-title',
                'label'       => 'Page: ',
                'size'        => 80,
                'placeholder' => 'Type in the title for the article created here. Existing article will get overwritten.',
                'default'     => 'test',
            ],
        ];

        $htmlForm = \HTMLForm::factory( 'table', $formDescriptor, $this->context );
        $htmlForm->setSubmitText( wfMessage( 'pandocultimateconverter-special-upload-button-label' ) );
        $htmlForm->setId( 'mw-pandoc-upload-form' );
        $htmlForm->setSubmitID( 'mw-pandoc-upload-form-submit' );
        $htmlForm->setSubmitCallback( [ $this, 'processForm' ] );
        $htmlForm->setTitle( $this->getPageTitle() );
        $htmlForm->show();
    }

    public function processForm( array $formData ): void
    {
        $pageName = $this->sanitizeArticleTitle( $formData['ConvertToArticleName'] );
        $service = new PandocConverterService( $this->config, $this->mwServices, $this->user );
        try {
            $result = $formData['SourceType'] === 'file'
                ? $service->convertStashedFileToPage( (string)$formData['UploadedFileName'], $pageName, true )
                : $service->convertUrlToPage( (string)$formData['SourceUrl'], $pageName );
        } catch ( \Exception $e ) {
            $this->getOutput()->showErrorPage( 'pandocultimateconverter-error-title',
                'pandocultimateconverter-error-conversion', [ $e->getMessage() ] );
            return;
        }
        if ( $result['result'] === 'queued' ) {
            // Moderation may have set a redirect while intercepting the edit.
            $this->getOutput()->redirect( '' );
            $this->getOutput()->addHTML( Html::element( 'p', [],
                $this->msg( 'pandocultimateconverter-codex-status-pending' )->text() ) );
        } else {
            $this->getOutput()->redirect( Title::newFromText( $pageName )->getFullURL() );
        }
    }

    private function sanitizeArticleTitle( string $titleString ): string
    {
        if ( strlen( $titleString ) > self::TITLE_MAX_LENGTH ) {
            $titleString = substr( $titleString, 0, self::TITLE_MAX_LENGTH );
        }
        if ( strlen( $titleString ) < self::TITLE_MIN_LENGTH ) {
            $titleString = 'PandocUltimateConverter' . $titleString;
        }
        return $titleString;
    }

}
