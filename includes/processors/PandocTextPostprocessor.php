<?php

declare(strict_types=1);

namespace MediaWiki\Extension\PandocUltimateConverter\Processors;

/**
 * Post-processes Pandoc wikitext output: rewrites [[File:…]] references to
 * the uploaded wiki file names, handling both absolute paths (from --extract-media)
 * and format-internal paths (e.g. "Pictures/img.png" from ODT colour preprocessor).
 */
class PandocTextPostprocessor
{
    /**
     * @param string               $text
     * @param array<string,string> $imagesVocabulary  Map of local absolute path → wiki file name.
     * @return string
     */
    public static function postprocess( string $text, array $imagesVocabulary ): string
    {
        if ( $imagesVocabulary === [] ) {
            return $text;
        }

        // Build a basename → wikiName lookup for fallback matching (ODT/DOCX internal paths).
        $basenameMap = [];
        foreach ( $imagesVocabulary as $localPath => $wikiName ) {
            $basenameMap[ basename( $localPath ) ] = $wikiName;
        }

        return preg_replace_callback(
            '/\[\[File:(?P<ref>[^|\]]+)/',
            static function ( array $m ) use ( $imagesVocabulary, $basenameMap ): string {
                $ref = $m['ref'];

                // 1. Exact match with normalised separators (absolute path from --extract-media).
                $normRef = str_replace( [ '/', '\\' ], DIRECTORY_SEPARATOR, $ref );
                if ( isset( $imagesVocabulary[ $normRef ] ) ) {
                    return '[[File:' . $imagesVocabulary[ $normRef ];
                }

                // 2. Basename fallback: for ODT/DOCX-internal paths like "Pictures/image.png".
                $base = basename( $normRef );
                if ( $base !== '' && isset( $basenameMap[ $base ] ) ) {
                    return '[[File:' . $basenameMap[ $base ];
                }

                // 3. No match — normalise to forward slashes and leave unchanged.
                return '[[File:' . str_replace( '\\', '/', $ref );
            },
            $text
        ) ?? $text;
    }
}
