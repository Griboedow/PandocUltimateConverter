<?php

declare(strict_types=1);

namespace MediaWiki\Extension\PandocUltimateConverter\Processors;

/**
 * Post-processes Pandoc wikitext output: rewrites [[File:…]] references to
 * the uploaded wiki file names, handling both absolute paths (from --extract-media)
 * and format-internal paths (e.g. "Pictures/img.png" from ODT colour preprocessor).
 *
 * Also handles Pandoc's HTML-passthrough placeholders for images it could not
 * resolve locally (e.g. Confluence attachment filenames that exist only on the
 * remote server).  These appear as:
 *   <span class="image placeholder" original-image-src="file.png" …>…</span>
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

        // 1. Rewrite [[File:…]] references.
        $text = preg_replace_callback(
            '/\[\[File:(?P<ref>[^|\]]+)/',
            static function ( array $m ) use ( $imagesVocabulary, $basenameMap ): string {
                $ref = $m['ref'];

                // Exact match with normalised separators (absolute path from --extract-media).
                $normRef = str_replace( [ '/', '\\' ], DIRECTORY_SEPARATOR, $ref );
                if ( isset( $imagesVocabulary[ $normRef ] ) ) {
                    return '[[File:' . $imagesVocabulary[ $normRef ];
                }

                // Basename fallback: for ODT/DOCX-internal paths like "Pictures/image.png".
                $base = basename( $normRef );
                if ( $base !== '' && isset( $basenameMap[ $base ] ) ) {
                    return '[[File:' . $basenameMap[ $base ];
                }

                // No match — normalise to forward slashes and leave unchanged.
                return '[[File:' . str_replace( '\\', '/', $ref );
            },
            $text
        ) ?? $text;

        // 2. Rewrite <span class="image placeholder" original-image-src="…">…</span>
        //    These are produced by Pandoc when it encounters <img src="filename">
        //    for a relative path it cannot resolve on disk.
        $text = preg_replace_callback(
            '/<span\b[^>]*\boriginal-image-src="(?P<src>[^"]+)"[^>]*>.*?<\/span>/si',
            static function ( array $m ) use ( $basenameMap ): string {
                $src  = $m['src'];
                $base = basename( $src );
                if ( $base !== '' && isset( $basenameMap[ $base ] ) ) {
                    return '[[File:' . $basenameMap[ $base ] . ']]';
                }
                // Fallback: emit a File link with the raw filename so it is at
                // least visible and fixable by an editor.
                return '[[File:' . $base . ']]';
            },
            $text
        ) ?? $text;

        // 3. Rewrite bare <img src="…"> tags that Pandoc may pass through.
        $text = preg_replace_callback(
            '/<img\b[^>]*\bsrc="(?P<src>[^"]+)"[^>]*\/?>/si',
            static function ( array $m ) use ( $basenameMap ): string {
                $src  = $m['src'];
                // Leave external URLs alone — only rewrite local/relative refs.
                if ( preg_match( '#^https?://#i', $src ) ) {
                    return $m[0];
                }
                $base = basename( $src );
                if ( $base !== '' && isset( $basenameMap[ $base ] ) ) {
                    return '[[File:' . $basenameMap[ $base ] . ']]';
                }
                return '[[File:' . $base . ']]';
            },
            $text
        ) ?? $text;

        return $text;
    }
}
