<?php

namespace MediaWiki\Extension\PandocUltimateConverter\Processors;

use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

/**
 * DOCX Color Preprocessor
 * Extracts color information from DOCX files and injects it into Pandoc output
 */
class DOCXColorPreprocessor
{
    private $tempDir;
    private $pandocPath;
    private $mediaOutputDir;
    private $colorPlaceholders = [];

    public function __construct()
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pandoc_docx_colors_' . uniqid();
        $this->pandocPath = 'pandoc';
        $this->mediaOutputDir = null;
    }

    /**
     * Main processing function
     */
    public function processDOCXFile($inputFile, $outputFormat = 'mediawiki', $mediaDir = null)
    {
        // Create temp directory
        mkdir($this->tempDir);

        // Set media output directory
        $this->mediaOutputDir = $mediaDir;

        try {
            // Debug: Log that we're processing
            wfDebugLog('PandocUltimateConverter', 'DOCXColorPreprocessor: Processing file ' . $inputFile);

            // Extract DOCX (which is a ZIP file)
            $this->extractDOCX($inputFile);

            // Parse color information and inject placeholders in document.xml
            $this->parseDOCXColors();
            wfDebugLog('PandocUltimateConverter', 'DOCXColorPreprocessor: Found ' . count($this->colorPlaceholders) . ' color placeholders');

            // Repackage modified DOCX with placeholder text
            $modifiedDocxPath = $this->repackageDOCX();

            // Convert with Pandoc using the modified DOCX
            $pandocOutput = $this->convertWithPandoc($modifiedDocxPath, $outputFormat);

            // Inject actual colors into Pandoc output by replacing placeholders
            $processedOutput = $this->injectColors($pandocOutput);

            // Extract media files
            $this->extractDOCXMedia();

            return $processedOutput;

        } finally {
            // Clean up temp directory
            $this->removeDirectory($this->tempDir);
        }
    }

    /**
     * Extract DOCX file (ZIP archive)
     */
    private function extractDOCX($docxFile)
    {
        $zip = new ZipArchive();
        if ($zip->open($docxFile) === TRUE) {
            $zip->extractTo($this->tempDir);
            $zip->close();
        } else {
            throw new \Exception("Failed to extract DOCX file");
        }
    }

    /**
     * Parse color information from DOCX XML and replace runs with placeholders.
     */
    private function parseDOCXColors()
    {
        $documentXml = $this->tempDir . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . 'document.xml';
        if (!file_exists($documentXml)) {
            wfDebugLog('PandocUltimateConverter', 'DOCXColorPreprocessor: document.xml not found at ' . $documentXml);
            return;
        }

        $xmlContent = file_get_contents($documentXml);

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $dom->loadXML($xmlContent);

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $styleMap = [];
        $stylesXml = $this->tempDir . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . 'styles.xml';
        if (file_exists($stylesXml)) {
            $stylesDom = new \DOMDocument();
            $stylesDom->load($stylesXml);
            $stylesXpath = new \DOMXPath($stylesDom);
            $stylesXpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

            foreach ($stylesXpath->query('//w:style[@w:styleId]') as $styleNode) {
                if (!($styleNode instanceof \DOMElement)) {
                    continue;
                }
                $styleId = $styleNode->getAttribute('w:styleId');
                $styleColors = [];

                $rPrNode = $stylesXpath->query('.//w:rPr', $styleNode)->item(0);
                if ($rPrNode instanceof \DOMElement) {
                    $colorNode = $stylesXpath->query('.//w:color', $rPrNode)->item(0);
                    if ($colorNode instanceof \DOMElement && $colorNode->hasAttribute('w:val')) {
                        $styleColors['color'] = $this->normalizeDOCXColor($colorNode->getAttribute('w:val'));
                    }
                    $highlightNode = $stylesXpath->query('.//w:highlight', $rPrNode)->item(0);
                    if ($highlightNode instanceof \DOMElement && $highlightNode->hasAttribute('w:val')) {
                        $styleColors['background'] = $this->mapDOCXColor($highlightNode->getAttribute('w:val'));
                    }
                    $shdNode = $stylesXpath->query('.//w:shd', $rPrNode)->item(0);
                    if ($shdNode instanceof \DOMElement && $shdNode->hasAttribute('w:fill')) {
                        $styleColors['background'] = '#' . $this->normalizeDOCXColor($shdNode->getAttribute('w:fill'));
                    }
                }

                if (!empty($styleColors)) {
                    $styleMap[$styleId] = $styleColors;
                }
            }
        }

        $placeholderCounter = 0;

        // Process table cells first — cell-level shd fill color (w:tc > w:tcPr > w:shd)
        foreach ($xpath->query('//w:tc') as $cell) {
            if (!($cell instanceof \DOMElement)) {
                continue;
            }

            $tcPr = $xpath->query('w:tcPr', $cell)->item(0);
            if (!($tcPr instanceof \DOMElement)) {
                continue;
            }

            $shdNode = $xpath->query('w:shd', $tcPr)->item(0);
            if (!($shdNode instanceof \DOMElement)) {
                continue;
            }

            $fill = $shdNode->getAttribute('w:fill');
            if (empty($fill) || $fill === 'auto' || $fill === '000000') {
                continue;
            }

            $bgColor = '#' . strtolower($fill);

            // Get text content from all runs in this cell
            $cellText = '';
            foreach ($xpath->query('.//w:t', $cell) as $tNode) {
                $cellText .= $tNode->textContent;
            }
            $cellText = trim($cellText);
            if ($cellText === '') {
                $cellText = "\xC2\xA0"; // NBSP for empty colored cells
            }

            $placeholderId = '__DOCX_COLOR_PLACEHOLDER_' . $placeholderCounter . '__';
            $this->colorPlaceholders[$placeholderId] = [
                'text' => $cellText,
                'colors' => ['background' => $bgColor],
                'isTableCell' => true,
            ];
            $placeholderCounter++;

            wfDebugLog('PandocUltimateConverter', 'DOCXColorPreprocessor: table-cell placeholder=' . $placeholderId . ' fill=' . $bgColor . ' text=' . $cellText);

            // Replace all paragraph content in this cell with a single placeholder paragraph
            foreach (iterator_to_array($xpath->query('w:p', $cell)) as $para) {
                $cell->removeChild($para);
            }
            $newPara = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:p');
            $newRun = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:r');
            $newT = $dom->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
            $newT->setAttribute('xml:space', 'preserve');
            $newT->appendChild($dom->createTextNode($placeholderId));
            $newRun->appendChild($newT);
            $newPara->appendChild($newRun);
            $cell->appendChild($newPara);
        }

        // Process text runs for inline color/highlight
        foreach ($xpath->query('//w:r') as $run) {
            if (!($run instanceof \DOMElement)) {
                continue;
            }

            $rPr = $xpath->query('w:rPr', $run)->item(0);
            $runColors = [];

            if ($rPr instanceof \DOMElement) {
                $colorNode = $xpath->query('w:color', $rPr)->item(0);
                if ($colorNode instanceof \DOMElement && $colorNode->hasAttribute('w:val')) {
                    $runColors['color'] = $this->normalizeDOCXColor($colorNode->getAttribute('w:val'));
                }
                $highlightNode = $xpath->query('w:highlight', $rPr)->item(0);
                if ($highlightNode instanceof \DOMElement && $highlightNode->hasAttribute('w:val')) {
                    $runColors['background'] = $this->mapDOCXColor($highlightNode->getAttribute('w:val'));
                }
                $shdNode = $xpath->query('w:shd', $rPr)->item(0);
                if ($shdNode instanceof \DOMElement && $shdNode->hasAttribute('w:fill')) {
                    $runColors['background'] = '#' . $this->normalizeDOCXColor($shdNode->getAttribute('w:fill'));
                }
                $rStyleNode = $xpath->query('w:rStyle', $rPr)->item(0);
                if ($rStyleNode instanceof \DOMElement && $rStyleNode->hasAttribute('w:val')) {
                    $styleId = $rStyleNode->getAttribute('w:val');
                    if (isset($styleMap[$styleId])) {
                        $runColors = array_merge($styleMap[$styleId], $runColors);
                    }
                }
            }

            $text = '';
            foreach ($xpath->query('w:t', $run) as $textNode) {
                if ($textNode instanceof \DOMElement) {
                    $text .= $textNode->textContent;
                }
            }

            if (trim($text) === '' || empty($runColors)) {
                continue;
            }

            $placeholderId = '__DOCX_COLOR_PLACEHOLDER_' . $placeholderCounter . '__';
            $this->colorPlaceholders[$placeholderId] = ['text' => $text, 'colors' => $runColors];
            $placeholderCounter++;

            // Replace run text with placeholder to keep unique matching
            foreach ($xpath->query('w:t', $run) as $textNode) {
                if ($textNode instanceof \DOMElement) {
                    $textNode->nodeValue = '';
                }
            }

            $newTextNode = $dom->createElement('w:t', $placeholderId);
            $newTextNode->setAttribute('xml:space', 'preserve');
            $run->appendChild($newTextNode);
        }

        // Save modified document.xml back to file
        file_put_contents($documentXml, $dom->saveXML());

        wfDebugLog('PandocUltimateConverter', 'DOCXColorPreprocessor: parsed ' . count($this->colorPlaceholders) . ' run placeholders');
    }
    /**
     * Map DOCX highlight color names to CSS colors
     */
    private function mapDOCXColor($docxColor)
    {
        $colorMap = [
            'yellow' => '#ffff00',
            'green' => '#00ff00',
            'cyan' => '#00ffff',
            'magenta' => '#ff00ff',
            'blue' => '#0000ff',
            'red' => '#ff0000',
            'darkBlue' => '#000080',
            'darkCyan' => '#008080',
            'darkGreen' => '#008000',
            'darkMagenta' => '#800080',
            'darkRed' => '#800000',
            'darkYellow' => '#808000',
            'darkGray' => '#808080',
            'lightGray' => '#c0c0c0',
            'black' => '#000000',
            'white' => '#ffffff'
        ];

        return $colorMap[$docxColor] ?? '#' . $docxColor;
    }

    /**
     * Normalize DOCX color values
     */
    private function normalizeDOCXColor($color)
    {
        // Remove auto and convert to hex
        if ($color === 'auto' || empty($color)) {
            return '';
        }

        // If it's already a hex color, ensure it has #
        if (preg_match('/^[0-9a-fA-F]{6}$/', $color)) {
            return '#' . $color;
        }

        return $color;
    }

    /**
     * Convert file with Pandoc
     */
    private function convertWithPandoc($inputFile, $outputFormat)
    {
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w']  // stderr
        ];

        $process = proc_open(
            [$this->pandocPath, '--from=docx', '--to=' . $outputFormat, $inputFile],
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            throw new \Exception("Failed to start Pandoc process");
        }

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);

        if ($returnCode !== 0) {
            throw new \Exception("Pandoc conversion failed: " . $error);
        }

        return $output;
    }

    /**
     * Repackage modified DOCX with placeholder text
     */
    private function repackageDOCX()
    {
        $modifiedDocxPath = $this->tempDir . DIRECTORY_SEPARATOR . 'modified.docx';

        $zip = new ZipArchive();
        if ($zip->open($modifiedDocxPath, ZipArchive::CREATE) === TRUE) {
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

        return $modifiedDocxPath;
    }

    /**
     * Inject colors into Pandoc output
     */
    private function injectColors($pandocOutput)
    {
        wfDebugLog('PandocUltimateConverter', 'DOCXColorPreprocessor: Injecting ' . count($this->colorPlaceholders) . ' placeholders into output');

        $processedOutput = $pandocOutput;

        // Handle existing mark spans from Pandoc first
        $processedOutput = preg_replace_callback(
            '/<span class="mark">([^<]*)<\/span>/',
            function($matches) {
                $text = $matches[1];
                foreach ($this->colorPlaceholders as $placeholder => $data) {
                    if ($data['text'] === $text && isset($data['colors']['background'])) {
                        return '<span style="background-color: ' . $data['colors']['background'] . '">' . $text . '</span>';
                    }
                }
                return '<span style="background-color: #ffff00">' . $text . '</span>';
            },
            $processedOutput
        );

        $tableCellMarkers = [];

        // Replace placeholders inserted in DOCX with final span style
        foreach ($this->colorPlaceholders as $placeholderId => $data) {
            $text = $data['text'];
            $colors = $data['colors'];
            $isTableCell = !empty($data['isTableCell']);

            $cellBgColor = null;
            $styleParts = [];

            if (!empty($colors['color'])) {
                $styleParts[] = 'color: ' . $colors['color'];
            }
            if (!empty($colors['background'])) {
                if ($isTableCell) {
                    $cellBgColor = $colors['background'];
                } else {
                    $styleParts[] = 'background-color: ' . $colors['background'];
                }
            }

            $inlineReplacement = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if (!empty($styleParts)) {
                $inlineReplacement = '<span style="' . implode('; ', $styleParts) . '">' . $inlineReplacement . '</span>';
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

        // Emit wiki table cell style syntax (handles both | data cells and ! header cells)
        wfDebugLog('PandocUltimateConverter', 'DOCXColorPreprocessor: tableCellMarkers=' . json_encode($tableCellMarkers));
        foreach ($tableCellMarkers as $placeholderId => $markerData) {
            $cellStyle = 'background-color:' . $markerData['background'];
            $markerText = '__TABLE_CELL_MARKER_' . $placeholderId . '__';

            // | cell  and  ! header cell variants, with optional attributes before the marker (e.g. | style="..."|)
            $processedOutput = preg_replace(
                '/([|!][^|\n]*)\|\s*' . preg_quote($markerText, '/') . '/',
                '$1| style="' . $cellStyle . '" | ' . $markerData['inline'],
                $processedOutput
            );
            // Simple | MARKER and ! MARKER (no nested pipe)
            $processedOutput = preg_replace(
                '/^([|!])\s*' . preg_quote($markerText, '/') . '/m',
                '$1 style="' . $cellStyle . '" | ' . $markerData['inline'],
                $processedOutput
            );
            // Fallback: bare marker anywhere
            $processedOutput = str_replace($markerText, $markerData['inline'], $processedOutput);
        }

        wfDebugLog('PandocUltimateConverter', 'DOCXColorPreprocessor: output=' . substr($processedOutput, 0, 1000));

        return $processedOutput;
    }

    /**
     * Extract media files from DOCX
     */
    private function extractDOCXMedia()
    {
        $mediaDir = $this->tempDir . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . 'media';
        if (is_dir($mediaDir) && $this->mediaOutputDir) {
            $mediaFiles = glob($mediaDir . DIRECTORY_SEPARATOR . '*');
            foreach ($mediaFiles as $mediaFile) {
                if (is_file($mediaFile)) {
                    $fileName = basename($mediaFile);
                    copy($mediaFile, $this->mediaOutputDir . DIRECTORY_SEPARATOR . $fileName);
                }
            }
        }
    }

    /**
     * Recursively remove directory
     */
    private function removeDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}

// Usage example
if ($argc > 1) {
    $processor = new DOCXColorPreprocessor();
    $result = $processor->processDOCXFile($argv[1]);
    echo $result;
}