<?php

namespace MediaWiki\Extension\PandocUltimateConverter;

class PandocTextPostporcessor
{
    public static function postprocess($text, $imagesVocabulary)
    {
        $linesArray = explode("\n", $text);
        foreach ($linesArray as &$line) {
            // tables processing
            if ($line == "{|") {
                $line = "{| class='wikitable'";
            }

            // images voc processing -- bind to new file names
            // TODO: rewrite, its not effective probably

            // hack for windows where pandoc may write incorrect slashes in file path
            preg_match_all('/\[\[File:(?<filename>[^]^|]+)/', $line, $matches);
            foreach ($matches as $match) {
                $match_fixed = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $match);
                $line = str_replace($match, $match_fixed, $line);
            }

            // Replace images links to real ones
            foreach ($imagesVocabulary as $key => $value) {
                $line = str_replace($key, $value, $line);
            }
        }
        return  implode("\n", $linesArray);
    }
}
