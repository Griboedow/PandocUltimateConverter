<?php

declare(strict_types=1);

namespace MediaWiki\Extension\PandocUltimateConverter\Processors;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * DOCX Color Preprocessor
 *
 * Extracts color information from DOCX files, injects placeholder tokens into the XML
 * before handing off to Pandoc, and post-processes the output to replace those tokens
 * with HTML color spans / wiki table cell styles.
 */
class DOCXColorPreprocessor extends AbstractColorPreprocessor
{
    /** @var array<string, array{text: string, colors: array<string,string>, isTableCell?: bool}> */
    private array $colorPlaceholders = [];

    public function __construct( string $pandocPath = 'pandoc', array $luaFilters = [] )
    {
        parent::__construct( $pandocPath, $luaFilters );
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pandoc_docx_colors_' . uniqid();
    }

    /**
     * Convert a DOCX file to the requested output format while preserving colour information.
     *
     * @param string      $inputFile Absolute path to the DOCX file.
     * @param string|null $mediaDir  Directory where extracted media should be placed.
     * @return string Converted document text.
     */
    public function processDOCXFile( string $inputFile, ?string $mediaDir = null ): string
    {
        mkdir( $this->tempDir );
        $this->mediaOutputDir = $mediaDir;

        try {
            wfDebugLog( 'PandocUltimateConverter', 'DOCXColorPreprocessor: Processing file ' . $inputFile );

            $this->extractDOCX( $inputFile );
            $this->parseDOCXColors();
            wfDebugLog( 'PandocUltimateConverter', 'DOCXColorPreprocessor: Found ' . count( $this->colorPlaceholders ) . ' color placeholders' );

            $modifiedDocxPath = $this->repackageDOCX();
            $pandocOutput     = $this->runPandoc( $modifiedDocxPath, 'docx' );
            $processedOutput  = $this->injectColors( $pandocOutput );
            $this->extractDOCXMedia();

            return $processedOutput;
        } finally {
            $this->deleteDirectory( $this->tempDir );
        }
    }

    /**
     * Extract DOCX file (ZIP archive)
     */
    private function extractDOCX( string $docxFile ): void
    {
        $zip = new ZipArchive();
        if ( $zip->open( $docxFile ) !== true ) {
            throw new \RuntimeException( 'Failed to extract DOCX file: ' . $docxFile );
        }
        $zip->extractTo( $this->tempDir );
        $zip->close();
    }

    /**
     * Parse color information from DOCX XML and replace runs with placeholders.
     */
    private function parseDOCXColors(): void
    {
        $documentXml = $this->tempDir . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . 'document.xml';
        if (!file_exists($documentXml)) {
            wfDebugLog('PandocUltimateConverter', 'DOCXColorPreprocessor: document.xml not found at ' . $documentXml);
            return;
        }

        $xmlContent = file_get_contents($documentXml);

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
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
                        $bg = $this->normalizeDOCXColor($shdNode->getAttribute('w:fill'));
                        if ($bg !== '') {
                            $styleColors['background'] = $bg;
                        }
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
                    $bg = $this->normalizeDOCXColor($shdNode->getAttribute('w:fill'));
                    if ($bg !== '') {
                        $runColors['background'] = $bg;
                    }
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
    private function mapDOCXColor( string $docxColor ): string
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
    private function normalizeDOCXColor( string $color ): string
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
     * Repackage modified DOCX with placeholder text
     */
    private function repackageDOCX(): string
    {
        $modifiedDocxPath = $this->tempDir . DIRECTORY_SEPARATOR . 'modified.docx';
        $this->repackageDir( $this->tempDir, $modifiedDocxPath );
        return $modifiedDocxPath;
    }

    /**
     * Inject colors into Pandoc output
     */
    private function injectColors( string $pandocOutput ): string
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

    private function extractDOCXMedia(): void
    {
        $mediaDir = $this->tempDir . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . 'media';
        if ( !is_dir( $mediaDir ) || $this->mediaOutputDir === null ) {
            return;
        }
        foreach ( glob( $mediaDir . DIRECTORY_SEPARATOR . '*' ) ?: [] as $mediaFile ) {
            if ( is_file( $mediaFile ) ) {
                copy( $mediaFile, $this->mediaOutputDir . DIRECTORY_SEPARATOR . basename( $mediaFile ) );
            }
        }
    }
}
