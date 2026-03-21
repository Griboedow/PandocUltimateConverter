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
        $xpath->registerNamespace('fo', 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0');

        $styleMap = [];

        foreach ($xpath->query('//style:style[@style:name]') as $styleNode) {
            if (!($styleNode instanceof \DOMElement)) {
                continue;
            }
            $styleName = $styleNode->getAttribute('style:name');
            $colors = [];

            foreach (['style:text-properties', 'style:paragraph-properties'] as $propertyName) {
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

        foreach ($xpath->query('//text:span | //text:p') as $node) {
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

            wfDebugLog('PandocUltimateConverter', 'ODTColorPreprocessor[parseODTColors]: placeholder=' . $placeholderId . ', text=' . $text . ', style=' . $styleName . ', colors=' . json_encode($colors));

            while ($node->firstChild) {
                $node->removeChild($node->firstChild);
            }
            $node->appendChild($dom->createTextNode($placeholderId));
        }

        $modifiedXml = $dom->saveXML();
        file_put_contents($contentXml, $modifiedXml);

        wfDebugLog('PandocUltimateConverter', 'ODTColorPreprocessor[parseODTColors]: generated ' . count($this->colorPlaceholders) . ' placeholders');
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

        // Replace each placeholder with styled span
        wfDebugLog('PandocUltimateConverter', 'ODTColorPreprocessor[injectColorsIntoOutput]: injecting ' . count($this->colorPlaceholders) . ' placeholders into output');
        foreach ($this->colorPlaceholders as $placeholderId => $data) {
            $text = $data['text'];
            $colors = $data['colors'];

            // Create style string
            $styleParts = [];
            foreach ($colors as $property => $value) {
                $styleParts[] = $property . ':' . $value;
            }

            if (!empty($styleParts)) {
                $styleString = implode('; ', $styleParts);
                $replacement = '<span style="' . $styleString . '">' . $text . '</span>';
                $processedOutput = str_replace($placeholderId, $replacement, $processedOutput);
            }
        }

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