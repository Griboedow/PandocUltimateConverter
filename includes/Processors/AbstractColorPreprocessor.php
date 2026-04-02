<?php

declare(strict_types=1);

namespace MediaWiki\Extension\PandocUltimateConverter\Processors;

use MediaWiki\Extension\PandocUltimateConverter\PandocWrapper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Shared infrastructure for ODT and DOCX colour preprocessors.
 *
 * Subclasses implement the format-specific extraction, XML colour parsing,
 * archive repackaging, and colour injection steps; this class provides the
 * common Pandoc invocation, ZIP repackaging helpers, and temp-dir cleanup.
 */
abstract class AbstractColorPreprocessor
{
    protected string $tempDir;
    protected string $pandocPath;
    protected ?string $mediaOutputDir = null;
    /** @var string[] Pre-built --lua-filter=… arguments, passed in from PandocWrapper. */
    protected array $luaFilters;

    public function __construct( string $pandocPath = 'pandoc', array $luaFilters = [] )
    {
        $this->pandocPath  = $pandocPath;
        $this->luaFilters  = $luaFilters;
    }

    // -------------------------------------------------------------------------
    // Shared: Pandoc invocation
    // -------------------------------------------------------------------------

    /**
     * Run Pandoc on $inputFile and return its stdout.
     * Output format is always 'mediawiki'. Lua filters configured in PandocWrapper
     * are automatically appended. Delegates to PandocWrapper::invokeShell() —
     * the single place in the codebase that calls Shell::command().
     *
     * @param string   $inputFile   Absolute path to the (possibly modified) input file.
     * @param string   $inputFormat Value passed to --from (e.g. 'odt', 'docx').
     * @param string[] $extraArgs   Additional Pandoc arguments (e.g. --extract-media=…).
     * @return string Pandoc stdout.
     * @throws \RuntimeException On process or conversion failure.
     */
    protected function runPandoc(
        string $inputFile,
        string $inputFormat,
        array $extraArgs = []
    ): string {
        $cmd = array_merge(
            [ $this->pandocPath, '--from=' . $inputFormat, '--to=mediawiki' ],
            $extraArgs,
            $this->luaFilters,
            [ $inputFile ]
        );
        return PandocWrapper::invokeShell( $cmd );
    }

    // -------------------------------------------------------------------------
    // Shared: ZIP repackaging
    // -------------------------------------------------------------------------

    /**
     * Repack the contents of $sourceDir into a new ZIP archive at $destPath.
     * ZIP entry names always use forward slashes (required by the ZIP spec).
     *
     * @throws \RuntimeException If the archive cannot be created.
     */
    protected function repackageDir( string $sourceDir, string $destPath ): void
    {
        $zip = new ZipArchive();
        if ( $zip->open( $destPath, ZipArchive::CREATE ) !== true ) {
            throw new \RuntimeException( 'Failed to create archive: ' . $destPath );
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $sourceDir ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ( $files as $file ) {
            if ( !$file->isDir() ) {
                $absPath      = $file->getRealPath();
                $relativePath = str_replace(
                    DIRECTORY_SEPARATOR, '/',
                    substr( $absPath, strlen( $sourceDir ) + 1 )
                );
                $zip->addFile( $absPath, $relativePath );
            }
        }
        $zip->close();
    }

    // -------------------------------------------------------------------------
    // Shared: temp-dir cleanup
    // -------------------------------------------------------------------------

    /**
     * Delegates to PandocWrapper::deleteDirectory so the logic lives in one place.
     */
    protected function deleteDirectory( string $dir ): bool
    {
        return \MediaWiki\Extension\PandocUltimateConverter\PandocWrapper::deleteDirectory( $dir );
    }
}
