<?php

namespace MediaWiki\Extension\PandocUltimateConverter;

class PandocTextPostporcessor
{
    private static function fixFileLinks(&$line, $imagesVocabulary)
    {
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

    public static function postprocess($text, $imagesVocabulary)
    {
        $linesArray = explode("\n", $text);
        foreach ($linesArray as &$line) {
            PandocTextPostporcessor::fixFileLinks($line, $imagesVocabulary);

            # TODO: fix headers if header starts from level 1 (=header=)
            # TODO: remove styles from headers

        }
        return  implode("\n", $linesArray);
    }
}
