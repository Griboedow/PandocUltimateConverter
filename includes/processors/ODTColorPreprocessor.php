<?php

namespace MediaWiki\Extension\PandocUltimateConverter\Processors;

use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

/**
 * ODT Color Extractor and Injector
 * Pre-processes ODT files to extract color information and inject it into Pandoc AST
 */

class ODTColorPreprocessor
{
    private $tempDir;
    private $pandocPath;
    private $mediaOutputDir;
    private $colorPlaceholders = [];

    public function __construct()
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pandoc_odt_colors_' . uniqid();
        $this->pandocPath = 'pandoc';
        $this->mediaOutputDir = null;
        $this->colorPlaceholders = [];
    }

    /**
     * Main processing function
     */
    public function processODTFile($inputFile, $outputFormat = 'mediawiki', $mediaDir = null)
    {
        // Create temp directory
        mkdir($this->tempDir);

        // Set media output directory
        $this->mediaOutputDir = $mediaDir;

        try {
            // Extract ODT file
            $this->extractODT($inputFile);

            // Parse color information and modify XML with placeholders
            $this->parseODTColors();

            // Repackage the modified ODT
            $modifiedOdtPath = $this->repackageODT();

            // Convert with Pandoc using the modified ODT
            $pandocOutput = $this->convertWithPandoc($modifiedOdtPath, $outputFormat);

            // Post-process to inject colors by replacing placeholders
            $finalOutput = $this->injectColorsIntoOutput($pandocOutput);

            return $finalOutput;

        } finally {
            // Cleanup
            $this->cleanup();
        }
    }

    /**
     * Extract ODT file (ZIP archive) and handle media
     */
    private function extractODT($odtFile)
    {
        $zip = new ZipArchive();
        if ($zip->open($odtFile) === TRUE) {
            // Extract all files
            $zip->extractTo($this->tempDir);
            $zip->close();

            // Extract media files to the media directory if they exist
            $this->extractODTMedia();
        } else {
            throw new \Exception("Failed to extract ODT file");
        }
    }

    /**
     * Extract media files from ODT to the media directory
     */
    private function extractODTMedia()
    {
        $picturesDir = $this->tempDir . DIRECTORY_SEPARATOR . 'Pictures';
        if (is_dir($picturesDir)) {
            // Copy all files from Pictures directory to the media output directory
            $mediaFiles = glob($picturesDir . DIRECTORY_SEPARATOR . '*');
            foreach ($mediaFiles as $mediaFile) {
                if (is_file($mediaFile)) {
                    $fileName = basename($mediaFile);
                    copy($mediaFile, $this->mediaOutputDir . DIRECTORY_SEPARATOR . $fileName);
                }
            }
        }
    }

    /**
     * Repackage the modified XML back into an ODT file
     */
    private function repackageODT()
    {
        $modifiedOdtPath = $this->tempDir . DIRECTORY_SEPARATOR . 'modified.odt';

        $zip = new ZipArchive();
        if ($zip->open($modifiedOdtPath, ZipArchive::CREATE) === TRUE) {
            // Add all files from the temp directory back to the ZIP
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tempDir),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($this->tempDir) + 1);

                    $zip->addFile($filePath, $relativePath);
                }
            }

            $zip->close();
        }

        return $modifiedOdtPath;
    }

    /**
     * Parse color information from ODT content.xml
     */
    private function parseODTColors()
    {
        $contentXml = $this->tempDir . DIRECTORY_SEPARATOR . 'content.xml';
        if (!file_exists($contentXml)) {
            return '';
        }

        $xmlContent = file_get_contents($contentXml);

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
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
     * Normalize color values
     */
    private function normalizeColor($color)
    {
        // Convert ODT color format to CSS
        if (preg_match('/^#([0-9a-f]{6})$/i', $color)) {
            return $color;
        }
        return $color;
    }

    /**
     * Get colors from a style name if present
     */
    private function resolveStyleColors($styleName, $styleMap)
    {
        if (!$styleName || !isset($styleMap[$styleName])) {
            return [];
        }

        return $styleMap[$styleName];
    }

    /**
     * Resolve cell color settings with row/table inheritance
     */
    private function resolveCellColors(\DOMElement $cell, $styleMap)
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
     * Convert with Pandoc
     */
    private function convertWithPandoc($inputFile, $outputFormat)
    {
        $command = sprintf(
            '%s --from=odt --to=%s "%s"',
            escapeshellarg($this->pandocPath),
            escapeshellarg($outputFormat),
            escapeshellarg($inputFile)
        );

        $output = shell_exec($command);
        return $output;
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

    /**
     * Cleanup temporary files
     */
    private function cleanup()
    {
        if (is_dir($this->tempDir)) {
            $this->deleteDirectory($this->tempDir);
        }
    }

    /**
     * Recursively delete directory
     */
    private function deleteDirectory($dir)
    {
        if (!file_exists($dir)) {
            return true;
        }

        if (!is_dir($dir)) {
            return unlink($dir);
        }

        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }

            if (!$this->deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }

        return rmdir($dir);
    }
}

// Usage example
if ($argc > 1) {
    $processor = new ODTColorPreprocessor();
    $result = $processor->processODTFile($argv[1]);
    echo $result;
}