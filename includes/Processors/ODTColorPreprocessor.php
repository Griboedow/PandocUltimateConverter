<?php

declare(strict_types=1);

namespace MediaWiki\Extension\PandocUltimateConverter\Processors;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * ODT Color Extractor and Injector
 *
 * Pre-processes ODT files to extract color information, injects placeholder tokens
 * into the XML before handing off to Pandoc, and post-processes the Pandoc output
 * to replace those tokens with HTML color spans / wiki table cell styles.
 */
class ODTColorPreprocessor extends AbstractColorPreprocessor
{
    /** @var array<string, array{text: string, colors: array<string,string>, isTableCell?: bool}> */
    private array $colorPlaceholders = [];

    public function __construct( string $pandocPath = 'pandoc', array $luaFilters = [] )
    {
        parent::__construct( $pandocPath, $luaFilters );
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pandoc_odt_colors_' . uniqid();
    }

    /**
     * Convert an ODT file to the requested output format while preserving colour information.
     *
     * @param string      $inputFile Absolute path to the ODT file.
     * @param string|null $mediaDir  Directory where extracted media should be placed.
     * @return string Converted document text.
     */
    public function processODTFile( string $inputFile, ?string $mediaDir = null ): string
    {
        mkdir( $this->tempDir );
        $this->mediaOutputDir = $mediaDir;

        try {
            $this->extractODT( $inputFile );
            $this->parseODTColors();
            $modifiedOdtPath = $this->repackageODT();
            $extraArgs = $this->mediaOutputDir !== null ? [ '--extract-media=' . $this->mediaOutputDir ] : [];
            $pandocOutput    = $this->runPandoc( $modifiedOdtPath, 'odt', $extraArgs );
            return $this->injectColorsIntoOutput( $pandocOutput );
        } finally {
            $this->deleteDirectory( $this->tempDir );
        }
    }

    private function extractODT( string $odtFile ): void
    {
        $zip = new ZipArchive();
        if ( $zip->open( $odtFile ) !== true ) {
            throw new \RuntimeException( 'Failed to extract ODT file: ' . $odtFile );
        }
        $zip->extractTo( $this->tempDir );
        $zip->close();
        $this->extractODTMedia();
    }

    private function extractODTMedia(): void
    {
        $picturesDir = $this->tempDir . DIRECTORY_SEPARATOR . 'Pictures';
        if ( !is_dir( $picturesDir ) || $this->mediaOutputDir === null ) {
            return;
        }
        foreach ( glob( $picturesDir . DIRECTORY_SEPARATOR . '*' ) ?: [] as $mediaFile ) {
            if ( is_file( $mediaFile ) ) {
                copy( $mediaFile, $this->mediaOutputDir . DIRECTORY_SEPARATOR . basename( $mediaFile ) );
            }
        }
    }

    private function repackageODT(): string
    {
        $modifiedOdtPath = $this->tempDir . DIRECTORY_SEPARATOR . 'modified.odt';
        $this->repackageDir( $this->tempDir, $modifiedOdtPath );
        return $modifiedOdtPath;
    }

    /**
     * Parse color information from ODT content.xml
     */
    private function parseODTColors()
    {
        $contentXml = $this->tempDir . DIRECTORY_SEPARATOR . 'content.xml';
        if (!file_exists($contentXml)) {
            return;
        }

        $xmlContent = file_get_contents($contentXml);

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        $dom->loadXML($xmlContent);

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('office', 'urn:oasis:names:tc:opendocument:xmlns:office:1.0');
        $xpath->registerNamespace('style', 'urn:oasis:names:tc:opendocument:xmlns:style:1.0');
        $xpath->registerNamespace('text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0');
        $xpath->registerNamespace('table', 'urn:oasis:names:tc:opendocument:xmlns:table:1.0');
        $xpath->registerNamespace('fo', 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0');

        $styleMap = [];

        foreach ($xpath->query('//style:style[@style:name]') as $styleNode) {
            if (!($styleNode instanceof \DOMElement)) {
                continue;
            }
            $styleName = $styleNode->getAttribute('style:name');
            $colors = [];

            foreach (['style:text-properties', 'style:paragraph-properties', 'style:table-cell-properties'] as $propertyName) {
                foreach ($xpath->query($propertyName, $styleNode) as $propNode) {
                    if (!($propNode instanceof \DOMElement)) {
                        continue;
                    }
                    if ($propNode->hasAttribute('fo:color')) {
                        $colors['color'] = $this->normalizeColor($propNode->getAttribute('fo:color'));
                    }
                    if ($propNode->hasAttribute('fo:background-color')) {
                        $bg = $propNode->getAttribute('fo:background-color');
                        if ($bg !== 'transparent') {
                            $colors['background-color'] = $this->normalizeColor($bg);
                        }
                    }
                }
            }

            if (!empty($colors)) {
                $styleMap[$styleName] = $colors;
            }
        }

        wfDebugLog('PandocUltimateConverter', 'ODTColorPreprocessor[parseODTColors]: styleMap=' . json_encode($styleMap));

        $placeholderCounter = 0;

        // Process table cells first to keep cell-level background and row/table inheritance from interfering with inline spans.
        foreach ($xpath->query('//table:table-cell') as $node) {
            if (!($node instanceof \DOMElement)) {
                continue;
            }

            $colors = $this->resolveCellColors($node, $styleMap);
            if ($node->hasAttribute('fo:color')) {
                $colors['color'] = $this->normalizeColor($node->getAttribute('fo:color'));
            }
            if ($node->hasAttribute('fo:background-color')) {
                $bg = $node->getAttribute('fo:background-color');
                if ($bg !== 'transparent') {
                    $colors['background-color'] = $this->normalizeColor($bg);
                }
            }

            $text = trim($node->textContent);
            if ($text === '') {
                // Keep an NBSP in empty table cells so they are preserved.
                $text = "\xC2\xA0";
            }

            if (empty($colors) || $text === '') {
                continue;
            }

            $placeholderId = '__COLOR_PLACEHOLDER_' . $placeholderCounter . '__';
            $this->colorPlaceholders[$placeholderId] = [
                'text' => $text,
                'colors' => $colors,
                'isTableCell' => true,
            ];
            $placeholderCounter++;

            wfDebugLog('PandocUltimateConverter', 'ODTColorPreprocessor[parseODTColors]: table-cell placeholder=' . $placeholderId . ', text=' . $text . ', colors=' . json_encode($colors));

            while ($node->firstChild) {
                $node->removeChild($node->firstChild);
            }
            // ODT requires text inside <text:p>; bare text nodes are ignored by Pandoc
            $textP = $dom->createElementNS('urn:oasis:names:tc:opendocument:xmlns:text:1.0', 'text:p');
            $textP->appendChild($dom->createTextNode($placeholderId));
            $node->appendChild($textP);
        }

        // Process inline text nodes after table-cell placeholders are set
        foreach (array_merge(iterator_to_array($xpath->query('//text:span')), iterator_to_array($xpath->query('//text:p'))) as $node) {
            if (!($node instanceof \DOMElement)) {
                continue;
            }

            $styleName = $node->getAttribute('text:style-name');
            $colors = [];
            if ($styleName && isset($styleMap[$styleName])) {
                $colors = $styleMap[$styleName];
            }

            if ($node->hasAttribute('fo:color')) {
                $colors['color'] = $this->normalizeColor($node->getAttribute('fo:color'));
            }
            if ($node->hasAttribute('fo:background-color')) {
                $bg = $node->getAttribute('fo:background-color');
                if ($bg !== 'transparent') {
                    $colors['background-color'] = $this->normalizeColor($bg);
                }
            }

            $text = trim($node->textContent);
            if (empty($colors) || $text === '') {
                continue;
            }

            $placeholderId = '__COLOR_PLACEHOLDER_' . $placeholderCounter . '__';
            $this->colorPlaceholders[$placeholderId] = [
                'text' => $text,
                'colors' => $colors,
            ];
            $placeholderCounter++;

            wfDebugLog('PandocUltimateConverter', 'ODTColorPreprocessor[parseODTColors]: inline placeholder=' . $placeholderId . ', text=' . $text . ', style=' . $styleName . ', colors=' . json_encode($colors));

            while ($node->firstChild) {
                $node->removeChild($node->firstChild);
            }
            $node->appendChild($dom->createTextNode($placeholderId));
        }

        $modifiedXml = $dom->saveXML();
        file_put_contents($contentXml, $modifiedXml);

        wfDebugLog('PandocUltimateConverter', 'ODTColorPreprocessor[parseODTColors]: generated ' . count($this->colorPlaceholders) . ' placeholders');
        wfDebugLog('PandocUltimateConverter', 'ODTColorPreprocessor[parseODTColors]: placeholders=' . json_encode($this->colorPlaceholders));
        return $modifiedXml;
    }

    /**
     * Normalise an ODT color value to a CSS hex string.
     */
    private function normalizeColor( string $color ): string
    {
        return $color; // Already in #rrggbb form from ODT
    }

    /** @param array<string, array<string,string>> $styleMap */
    private function resolveStyleColors( string $styleName, array $styleMap ): array
    {
        return $styleMap[$styleName] ?? [];
    }

    /** @param array<string, array<string,string>> $styleMap */
    private function resolveCellColors( \DOMElement $cell, array $styleMap )
    {
        $colors = [];

        // Table hierarchy: table -> row -> cell
        $row = null;
        $table = null;

        if ($cell->parentNode instanceof \DOMElement && $cell->parentNode->tagName === 'table:table-row') {
            $row = $cell->parentNode;
            if ($row->parentNode instanceof \DOMElement && $row->parentNode->tagName === 'table:table') {
                $table = $row->parentNode;
            }
        }

        if ($table !== null) {
            $tableStyle = $table->getAttribute('table:style-name');
            $colors = array_merge($colors, $this->resolveStyleColors($tableStyle, $styleMap));
        }

        if ($row !== null) {
            $rowStyle = $row->getAttribute('table:style-name');
            $colors = array_merge($colors, $this->resolveStyleColors($rowStyle, $styleMap));
        }

        $cellStyle = $cell->getAttribute('table:style-name');
        $colors = array_merge($colors, $this->resolveStyleColors($cellStyle, $styleMap));

        return $colors;
    }

    /**
     * Inject colors into Pandoc output by replacing placeholders
     */
    private function injectColorsIntoOutput($pandocOutput)
    {
        $processedOutput = $pandocOutput;

        // Replace each placeholder with styled span or table cell style marker
        wfDebugLog('PandocUltimateConverter', 'ODTColorPreprocessor[injectColorsIntoOutput]: injecting ' . count($this->colorPlaceholders) . ' placeholders into output');

        $tableCellMarkers = [];

        foreach ($this->colorPlaceholders as $placeholderId => $data) {
            $text = $data['text'];
            $colors = $data['colors'];
            $isTableCell = !empty($data['isTableCell']);

            $cellBgColor = null;
            $styleParts = [];

            foreach ($colors as $property => $value) {
                if ($isTableCell && $property === 'background-color') {
                    $cellBgColor = $value;
                    continue;
                }
                $styleParts[] = $property . ':' . $value;
            }

            $inlineReplacement = $text;
            if (!empty($styleParts)) {
                $inlineStyle = implode('; ', $styleParts);
                $inlineReplacement = '<span style="' . $inlineStyle . '">' . $text . '</span>';
            }

            if ($isTableCell && $cellBgColor !== null) {
                $tableCellMarkers[$placeholderId] = [
                    'background' => $cellBgColor,
                    'inline' => $inlineReplacement,
                ];

                $processedOutput = str_replace($placeholderId, '__TABLE_CELL_MARKER_' . $placeholderId . '__', $processedOutput);
            } else {
                $processedOutput = str_replace($placeholderId, $inlineReplacement, $processedOutput);
            }
        }

        // Convert table cell background-color markers into wiki table cell style declarations
        wfDebugLog('PandocUltimateConverter', 'ODTColorPreprocessor[injectColorsIntoOutput]: tableCellMarkers=' . json_encode($tableCellMarkers));

        foreach ($tableCellMarkers as $placeholderId => $markerData) {
            $cellStyle = 'background-color:' . $markerData['background'];
            $markerText = '__TABLE_CELL_MARKER_' . $placeholderId . '__';

            // Handle | data cells and ! header cells, with optional existing attributes
            $processedOutput = preg_replace(
                '/([|!][^|\n]*)\|\s*' . preg_quote($markerText, '/') . '/',
                '$1| style="' . $cellStyle . '" | ' . $markerData['inline'],
                $processedOutput
            );
            // Simple | MARKER and ! MARKER at line start
            $processedOutput = preg_replace(
                '/^([|!])\s*' . preg_quote($markerText, '/') . '/m',
                '$1 style="' . $cellStyle . '" | ' . $markerData['inline'],
                $processedOutput
            );
            // Fallback: bare marker
            $processedOutput = str_replace($markerText, $markerData['inline'], $processedOutput);
        }

        wfDebugLog('PandocUltimateConverter', 'ODTColorPreprocessor[injectColorsIntoOutput]: output=' . substr($processedOutput, 0, 1000));

        return $processedOutput;
    }
}
