<?php

declare(strict_types=1);

namespace MediaWiki\Extension\PandocUltimateConverter;

use MediaWiki\Shell\Shell;
use MediaWiki\Title\Title;
use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\PandocUltimateConverter\Processors\DOCPreprocessor;
use MediaWiki\Extension\PandocUltimateConverter\Processors\DOCXColorPreprocessor;
use MediaWiki\Extension\PandocUltimateConverter\Processors\ODTColorPreprocessor;
use MediaWiki\Extension\PandocUltimateConverter\Processors\PDFPreprocessor;

class PandocWrapper
{
    private string $pandocExecutablePath;
    private string $tempFolderPath;
    private array $mediaFilesExtensionsToSkip;
    private array $customPandocFilters;
    private string $filtersFolderPath;
    private bool $useColorProcessors;
    private string $pdfToHtmlExecutablePath;
    private string $pdftoppmExecutablePath;
    private string $pdfToTextExecutablePath;
    private string $libreofficeExecutablePath;
    private string $tesseractExecutablePath;
    private string $ocrLanguage;

    /** @var MediaWikiServices */
    private $mwServices;
    /** @var \User */
    private $user;

    public function __construct( $config, MediaWikiServices $mwServices, $user )
    {
        // Support legacy global variable overrides
        global $wgPandocExecutablePath, $wgPandocTmpFolderPath,
               $wgPandocUltimateConverter_UseColorProcessors,
               $wgExtensionDirectory, $IP;

        $this->pandocExecutablePath = $wgPandocExecutablePath
            ?? $config->get( 'PandocUltimateConverter_PandocExecutablePath' )
            ?? 'pandoc';

        $this->tempFolderPath = $wgPandocTmpFolderPath
            ?? $config->get( 'PandocUltimateConverter_TempFolderPath' )
            ?? sys_get_temp_dir();

        $this->mediaFilesExtensionsToSkip = $config->get( 'PandocUltimateConverter_MediaFileExtensionsToSkip' ) ?? [];
        $this->customPandocFilters        = $config->get( 'PandocUltimateConverter_FiltersToUse' ) ?? [];

        $this->useColorProcessors = $wgPandocUltimateConverter_UseColorProcessors
            ?? $config->get( 'PandocUltimateConverter_UseColorProcessors' )
            ?? false;

        $this->pdfToHtmlExecutablePath = $config->get( 'PandocUltimateConverter_PdfToHtmlExecutablePath' )
            ?? 'pdftohtml';

        $this->pdftoppmExecutablePath = $config->get( 'PandocUltimateConverter_PdfToPpmExecutablePath' )
            ?? 'pdftoppm';

        $this->pdfToTextExecutablePath = $config->get( 'PandocUltimateConverter_PdfToTextExecutablePath' )
            ?? 'pdftotext';

        $this->libreofficeExecutablePath = $config->get( 'PandocUltimateConverter_LibreOfficeExecutablePath' )
            ?? 'libreoffice';

        $this->tesseractExecutablePath = $config->get( 'PandocUltimateConverter_TesseractExecutablePath' )
            ?? 'tesseract';

        $this->ocrLanguage = $config->get( 'PandocUltimateConverter_OcrLanguage' )
            ?? 'eng';

        $extensionsDir = $wgExtensionDirectory ?? ( $IP . DIRECTORY_SEPARATOR . 'extensions' );
        $this->filtersFolderPath = $extensionsDir
            . DIRECTORY_SEPARATOR . 'PandocUltimateConverter'
            . DIRECTORY_SEPARATOR . 'filters'
            . DIRECTORY_SEPARATOR;

        $this->mwServices = $mwServices;
        $this->user = $user;
    }

    /**
     * Recursively list all files under a directory.
     *
     * @param string $dir
     * @return string[]
     */
    private static function walkFiles( string $dir ): array
    {
        $results = [];
        foreach ( scandir( $dir ) as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }
            $path = realpath( $dir . DIRECTORY_SEPARATOR . $entry );
            if ( $path === false ) {
                continue;
            }
            if ( is_dir( $path ) ) {
                $results = array_merge( $results, self::walkFiles( $path ) );
            } else {
                $results[] = $path;
            }
        }
        return $results;
    }

    /**
     * Core conversion routine. Dispatches to color preprocessors or Pandoc directly.
     *
     * @param string      $source  File path or URL to convert.
     * @param string      $baseName  Base name used for the temp media folder and uploaded filenames.
     * @param string|null $format  Explicit input format override (e.g. 'html', 'odt', 'docx').
     * @param bool|null   $useColorProcessors  Override the instance-level flag for testing.
     * @return array{text: string, baseName: string, mediaFolder: string}
     */
    public function convertInternal( string $source, string $baseName, ?string $format = null, ?bool $useColorProcessors = null ): array
    {
        $useColorProcessors ??= $this->useColorProcessors;

        $mediaFolder = str_replace(
            [ '/', '\\' ],
            DIRECTORY_SEPARATOR,
            $this->tempFolderPath . DIRECTORY_SEPARATOR . $baseName
        );
        if ( !is_dir( $mediaFolder ) ) {
            mkdir( $mediaFolder, 0755, true );
        }

        wfDebugLog( 'PandocUltimateConverter', "convertInternal: source=$source, baseName=$baseName, format=" . ( $format ?? 'null' ) );

        $fileExtension = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );
        $isDoc  = $fileExtension === 'doc';
        $isOdt  = $format === 'odt'  || $fileExtension === 'odt';
        $isDocx = $format === 'docx' || $fileExtension === 'docx';
        $isPdf  = $format === 'pdf'  || $fileExtension === 'pdf';

        wfDebugLog( 'PandocUltimateConverter', "convertInternal: ext=$fileExtension, DOC=" . ( $isDoc ? 'yes' : 'no' ) . ', ODT=' . ( $isOdt ? 'yes' : 'no' ) . ', DOCX=' . ( $isDocx ? 'yes' : 'no' ) . ', PDF=' . ( $isPdf ? 'yes' : 'no' ) );

        // .doc is not supported by Pandoc — convert to .docx via LibreOffice first
        if ( $isDoc ) {
            wfDebugLog( 'PandocUltimateConverter', "convertInternal: converting .doc to .docx via LibreOffice for $source" );
            $preprocessor = new DOCPreprocessor( $this->libreofficeExecutablePath );
            $source = $preprocessor->convertToDocx( $source, $mediaFolder );
            $isDocx = true;
            $format = 'docx';
        }

        // Build lua filter args once — shared with colour preprocessors
        $luaFilterArgs = [];
        foreach ( $this->customPandocFilters as $filter ) {
            $luaFilterArgs[] = '--lua-filter=' . $this->filtersFolderPath . $filter;
        }

        if ( $isPdf ) {
            wfDebugLog( 'PandocUltimateConverter', "convertInternal: using PDF preprocessor for $source" );
            $preprocessor = new PDFPreprocessor(
                $this->pdfToHtmlExecutablePath,
                $this->pdfToTextExecutablePath,
                $this->pdftoppmExecutablePath,
                $this->tesseractExecutablePath,
                $this->ocrLanguage
            );

            try {
                $isScanned = $preprocessor->isScannedPdf( $source );
            } catch ( \Throwable $e ) {
                wfDebugLog( 'PandocUltimateConverter',
                    "convertInternal: failed to detect scanned PDF, assuming text-based: " . $e->getMessage()
                );
                $isScanned = false;
            }

            if ( $isScanned ) {
                // Scanned PDF: OCR produces wikitext directly — no Pandoc step needed.
                wfDebugLog( 'PandocUltimateConverter', "convertInternal: scanned PDF detected, using OCR pipeline for $source" );
                $text = $preprocessor->processScannedPdfFile( $source, $mediaFolder );
                return [ 'text' => $text, 'baseName' => $baseName, 'mediaFolder' => $mediaFolder ];
            }
            
            // Text-based PDF: pdftohtml → HTML → Pandoc → mediawiki wikitext.
            wfDebugLog( 'PandocUltimateConverter', "convertInternal: text-based PDF detected, using pdftohtml pipeline for $source" );
            $htmlFile = $preprocessor->processPDFFile( $source, $mediaFolder );

            // Convert the intermediate HTML to mediawiki wikitext via Pandoc.
            // --resource-path tells Pandoc where to find the images referenced
            // in the HTML (pdftohtml places them next to the HTML file).
            $commands = array_merge(
                [
                    $this->pandocExecutablePath,
                    '--from=html',
                    '--to=mediawiki',
                    '--extract-media=' . $mediaFolder,
                    '--resource-path=' . $mediaFolder,
                ],
                $luaFilterArgs,
                [ $htmlFile ]
            );

            wfDebugLog( 'PandocUltimateConverter', 'convertInternal (PDF→HTML→MW): running ' . implode( ' ', $commands ) );

            $text = self::invokeShell( $commands );
            return [ 'text' => $text, 'baseName' => $baseName, 'mediaFolder' => $mediaFolder ];
        }

        if ( $useColorProcessors && $isOdt ) {
            $preprocessor = new ODTColorPreprocessor( $this->pandocExecutablePath, $luaFilterArgs );
            $text = $preprocessor->processODTFile( $source, $mediaFolder );
            return [ 'text' => $text, 'baseName' => $baseName, 'mediaFolder' => $mediaFolder ];
        }

        if ( $useColorProcessors && $isDocx ) {
            wfDebugLog( 'PandocUltimateConverter', "convertInternal: using DOCX color preprocessor for $source" );
            $preprocessor = new DOCXColorPreprocessor( $this->pandocExecutablePath, $luaFilterArgs );
            $text = $preprocessor->processDOCXFile( $source, $mediaFolder );
            return [ 'text' => $text, 'baseName' => $baseName, 'mediaFolder' => $mediaFolder ];
        }

        // Standard Pandoc conversion
        $commands = array_merge(
            [
                $this->pandocExecutablePath,
                '--to=mediawiki',
                '--extract-media=' . $mediaFolder,
                '--request-header=User-Agent:"Mozilla/5.0"',
            ],
            $luaFilterArgs
        );
        if ( $format !== null ) {
            $commands[] = '--from=' . $format;
        }
        $commands[] = $source;

        wfDebugLog( 'PandocUltimateConverter', 'convertInternal: running ' . implode( ' ', $commands ) );

        return [
            'text'        => self::invokeShell( $commands, true ),
            'baseName'    => $baseName,
            'mediaFolder' => $mediaFolder,
        ];
    }

    /**
     * Convert a file on disk to MediaWiki wikitext.
     *
     * @param string $filePath Absolute file path.
     * @return array{text: string, baseName: string, mediaFolder: string}
     */
    public function convertFile( string $filePath ): array
    {
        $baseName = pathinfo( $filePath, PATHINFO_FILENAME );
        return $this->convertInternal( $filePath, $baseName );
    }

    /**
     * Convert a URL to MediaWiki wikitext.
     *
     * @param string $sourceUrl
     * @return array{text: string, baseName: string, mediaFolder: string}
     */
    public function convertUrl( string $sourceUrl ): array
    {
        $baseName = (string)( parse_url( $sourceUrl, PHP_URL_HOST ) ?? 'url-import' );
        // Specifying 'html' improves results for real web pages (e.g. GitHub rendered pages)
        return $this->convertInternal( $sourceUrl, $baseName, 'html' );
    }

    /**
     * Upload all media files found in the temp folder to the wiki.
     *
     * @param string $mediaFolder Absolute path to the folder containing extracted media.
     * @param string $baseName    Prefix used for uploaded file names.
     * @return array<string, string> Map of local file path → uploaded wiki file name.
     */
    public function processImages( string $mediaFolder, string $baseName ): array
    {
        $imagesVocabulary = [];

        foreach ( self::walkFiles( $mediaFolder ) as $file ) {
            if ( is_dir( $file ) ) {
                continue;
            }

            $extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

            // Always skip LibreOffice/ODF internal files that Pandoc may extract.
            static $internalExtensions = [ 'xcu', 'rels', 'xml', 'rdf' ];
            if ( in_array( $extension, $internalExtensions, true ) ) {
                continue;
            }

            if ( in_array( $extension, array_map( 'strtolower', $this->mediaFilesExtensionsToSkip ), true ) ) {
                continue;
            }

            $imagesVocabulary[$file] = $this->uploadFile( $file, $baseName );
        }

        return $imagesVocabulary;
    }

    /**
     * Upload a single file to the MediaWiki file repository.
     *
     * @param string $file     Absolute path to the file on disk.
     * @param string $baseName Prefix applied to the wiki file name.
     * @return string The resulting wiki file name (e.g. "PageName-image.png").
     * @throws \Exception On upload failure.
     */
    private function uploadFile( string $file, string $baseName ): string
    {
        $base          = wfBaseName( $file );
        $filePageName  = $baseName . '-' . $base;
        $title         = Title::makeTitleSafe( NS_FILE, $filePageName );
        $image         = $this->mwServices->getRepoGroup()->getLocalRepo()->newFile( $title );

        $sha1  = \FSFile::getSha1Base36FromPath( $file );
        $dupes = $image->getRepo()->findBySha1( $sha1 );
        if ( $dupes ) {
            // Reuse existing identical file instead of uploading a duplicate
            return $dupes[0]->getName();
        }

        // If a file with the same title already exists (e.g. from a previous
        // import attempt), reuse it to avoid archive-path collisions.
        if ( $image->exists() ) {
            return $filePageName;
        }

        $archive = $image->publish( $file, 0, [] );
        if ( !$archive->isGood() ) {
            throw new \Exception( $archive->getMessage( false, false, 'en' )->text() );
        }

        $mwProps = new \MWFileProps( $this->mwServices->getMimeAnalyzer() );
        $status  = $image->recordUpload3(
            $archive->value,
            wfMessage( 'pandocultimateconverter-history-comment' )->text(),
            '',
            $this->user,
            $mwProps->getPropsFromPath( $file, true )
        );
        if ( !$status->isOK() ) {
            throw new \Exception( 'Failed to upload ' . $file );
        }

        return $filePageName;
    }

    /**
     * Execute a shell command and return its stdout.
     *
     * This is the single place in the codebase that invokes Shell::command().
     * Use this for Pandoc, LibreOffice, and any other external tool.
     *
     * @param string[] $cmd         Full command array starting with the executable.
     * @param bool     $inheritEnv  Pass the current process environment to the child process.
     * @param array    $extraEnv    Additional environment variables merged on top of the inherited env.
     * @param string|null $cwd      Working directory for the child process.
     * @return string Command stdout.
     * @throws \RuntimeException On non-zero exit code.
     */
    public static function invokeShell( array $cmd, bool $inheritEnv = false, array $extraEnv = [], ?string $cwd = null ): string
    {
        $result = self::invokeShellRaw( $cmd, $inheritEnv, $extraEnv, $cwd );
        if ( $result['exitCode'] !== 0 ) {
            $output = trim( $result['output'] );
            $stderr = trim( $result['stderr'] );
            $detail = $output;
            if ( $stderr !== '' ) {
                $detail = $detail !== '' ? ( $detail . PHP_EOL . $stderr ) : $stderr;
            }
            throw new \RuntimeException(
                'Shell command failed (exit ' . $result['exitCode'] . '): ' . $detail
            );
        }
        return $result['output'];
    }

    /**
     * Execute a shell command and return the raw result without throwing on errors.
     *
     * Useful for tools like LibreOffice that may exit non-zero yet still produce
     * a valid output file.
     *
     * @param string[] $cmd         Full command array starting with the executable.
     * @param bool     $inheritEnv  Pass the current process environment to the child process.
     * @param array    $extraEnv    Additional environment variables merged on top of the inherited env.
     * @param string|null $cwd      Working directory for the child process.
     * @return array{exitCode: int, output: string, stderr: string}
     */
    public static function invokeShellRaw( array $cmd, bool $inheritEnv = false, array $extraEnv = [], ?string $cwd = null ): array
    {
        $runner = Shell::command( $cmd );
        $env = [];
        if ( $inheritEnv ) {
            $envArr = getenv();
            $env = is_array( $envArr ) ? $envArr : [];
        }
        if ( $extraEnv !== [] ){
            $env = array_merge( $env, $extraEnv );
        }
        if ( $env !== [] ) {
            $runner = $runner->environment( $env );
        }
        if ( $cwd !== null ) {
            $runner = $runner->workingDirectory( $cwd );
        }
        $result = $runner->execute();
        return [
            'exitCode' => $result->getExitCode(),
            'output'   => $result->getStdout(),
            'stderr'   => $result->getStderr(),
        ];
    }

    /**
     * Recursively delete a directory and all its contents.
     *
     * @param string $dir
     * @return bool
     */
    public static function deleteDirectory( string $dir ): bool
    {
        if ( !file_exists( $dir ) ) {
            return true;
        }
        if ( !is_dir( $dir ) ) {
            return unlink( $dir );
        }
        foreach ( scandir( $dir ) as $item ) {
            if ( $item === '.' || $item === '..' ) {
                continue;
            }
            if ( !self::deleteDirectory( $dir . DIRECTORY_SEPARATOR . $item ) ) {
                return false;
            }
        }
        return rmdir( $dir );
    }
}
