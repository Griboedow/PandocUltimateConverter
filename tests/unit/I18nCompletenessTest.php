<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that every i18n translation file is complete and actually translated.
 *
 * ## Rules enforced by this test suite
 *
 * 1. **No missing keys** – every key in en.json must appear in every translation file.
 *    `testTranslationContainsAllEnglishKeys` catches this.
 *
 * 2. **No English fallbacks** – no translation value may be a verbatim copy of the
 *    English value.  `testTranslationValuesAreNotEnglishFallbacks` catches this.
 *
 * ## !! IMPORTANT — READ BEFORE ADDING NEW MESSAGES !!
 *
 * When you add a new key to i18n/en.json you MUST also add a real translation of
 * that key to EVERY other language file in i18n/.  The complete list of languages
 * is: ar, cs, de, es, fr, hu, it, ja, ko, nl, pl, pt-br, ru, sk, sv, tr, uk,
 * zh-hans, zh-hant.
 *
 * Do NOT copy-paste the English text as a placeholder — that is what this test
 * is designed to catch and fail on.
 *
 * If a value is legitimately identical to English (e.g. it is a widely-recognised
 * technical term or brand name), add the key to the `IDENTICAL_TO_ENGLISH_ALLOWED`
 * constant below with a brief justification comment.  Every entry in that list will
 * be reviewed during code review.
 *
 * The special qqq.json (message documentation) is intentionally excluded from both
 * tests because its purpose is different.
 *
 * @coversNothing
 */
class I18nCompletenessTest extends TestCase {

	/**
	 * Keys whose value is allowed to be identical to English across all translations.
	 *
	 * Only add a key here when the English text is genuinely a proper noun,
	 * abbreviation, or technical term that does not change in translation.
	 * Include a short justification comment next to each entry.
	 *
	 * @var list<string>
	 */
	private const IDENTICAL_TO_ENGLISH_ALLOWED = [
		// "Pandoc Ultimate Converter" is the brand/product name of this extension;
		// it is intentionally kept in English across all languages.
		'pandocultimateconverter',
		// "Pandoc Export" is the proper name of this special page; all languages
		// may keep it in this form.
		'pandocexport',
		// "URL:" is an internationally standardised acronym used unchanged in every language.
		'pandocultimateconverter-special-url-label',
		// "URLs" — same rationale as above.
		'pandocultimateconverter-codex-tab-urls',
		// "Status" is a widely adopted loanword in many European languages (de, nl, pl, sv, …)
		// and is the standard term in Brazilian Portuguese. Those translations are correct.
		'pandocultimateconverter-codex-column-status',
		// "Source" is the standard term in French (it originates from Latin via French anyway).
		'pandocultimateconverter-codex-column-source',
		// "Actions" is the standard term in French.
		'pandocultimateconverter-codex-column-actions',
		// "Error: $1" — "error" is used unchanged in Spanish.
		'pandocultimateconverter-codex-status-error',
		// "Type:" is used unchanged in Dutch (it is a common loanword).
		'pandocultimateconverter-special-source-type-label',
		// "File:" is used unchanged in Italian (the English word is standard in the IT domain).
		'pandocultimateconverter-special-upload-file',
		// "Confluence URL:" — "Confluence" is a brand name and "URL" is a universal acronym;
		// the combined label is technically correct in most languages without modification.
		'confluencemigration-url-label',
		// The url-help line contains technical URL examples that must stay in English;
		// the surrounding language structure is often identical to English too.
		'confluencemigration-url-help',
	];

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private static function i18nDir(): string {
		return dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'i18n';
	}

	/**
	 * Parse a MediaWiki i18n JSON file, transparently handling the UTF-8 BOM
	 * that many editors write and that json_decode() cannot handle natively.
	 *
	 * @return array<string, mixed>
	 */
	private static function loadJson( string $path ): array {
		$raw = file_get_contents( $path );
		if ( $raw === false ) {
			throw new \RuntimeException( "Cannot read $path" );
		}
		// Strip UTF-8 BOM (EF BB BF) if present.
		if ( str_starts_with( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		}
		$data = json_decode( $raw, true );
		if ( !is_array( $data ) ) {
			throw new \RuntimeException( "Invalid JSON in $path: " . json_last_error_msg() );
		}
		return $data;
	}

	/**
	 * Extract user-visible message keys from a decoded i18n array,
	 * skipping the "@metadata" control block.
	 *
	 * @return list<string>
	 */
	private static function messageKeys( array $data ): array {
		return array_values(
			array_filter( array_keys( $data ), static fn( $k ) => !str_starts_with( $k, '@' ) )
		);
	}

	// ------------------------------------------------------------------
	// Data provider
	// ------------------------------------------------------------------

	/**
	 * Returns one [langCode, filePath] pair for every translation file
	 * (all *.json files except en.json and qqq.json).
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function provideTranslationFiles(): array {
		$cases = [];
		foreach ( glob( self::i18nDir() . DIRECTORY_SEPARATOR . '*.json' ) ?: [] as $path ) {
			$lang = basename( $path, '.json' );
			if ( $lang === 'en' || $lang === 'qqq' ) {
				continue;
			}
			$cases[$lang] = [ $lang, $path ];
		}
		return $cases;
	}

	// ------------------------------------------------------------------
	// Tests
	// ------------------------------------------------------------------

	/**
	 * Every key present in en.json must also be present in the translation file.
	 *
	 * Failure means a new en.json key was added without adding it to this language.
	 * Fix: add a proper translation of the missing key(s) to the language file —
	 * do NOT copy-paste the English text.
	 *
	 * @dataProvider provideTranslationFiles
	 */
	public function testTranslationContainsAllEnglishKeys( string $lang, string $path ): void {
		$enKeys   = self::messageKeys( self::loadJson( self::i18nDir() . '/en.json' ) );
		$langKeys = self::messageKeys( self::loadJson( $path ) );

		$missing = array_diff( $enKeys, $langKeys );

		$this->assertEmpty(
			$missing,
			sprintf(
				"Translation '%s' is missing %d message key(s):\n  %s\n\n"
				. "ACTION REQUIRED: add a real translation of each key listed above to\n"
				. "i18n/%s.json.  Do NOT use the English text as a placeholder value;\n"
				. "that will be caught by testTranslationValuesAreNotEnglishFallbacks.",
				$lang,
				count( $missing ),
				implode( "\n  ", $missing ),
				$lang
			)
		);
	}

	/**
	 * No translation value may be a verbatim copy of the English source value.
	 *
	 * A value identical to English almost always means the key was added with the
	 * English text as a placeholder and never actually translated.
	 *
	 * Exceptions: keys listed in IDENTICAL_TO_ENGLISH_ALLOWED are skipped.  Only
	 * add a key there when the English text is a proper noun or abbreviation that
	 * genuinely does not change in translation, and justify it with a comment.
	 *
	 * Failure means one or more keys still contain their English fallback text.
	 * Fix: translate each listed value into the target language.
	 *
	 * @dataProvider provideTranslationFiles
	 */
	public function testTranslationValuesAreNotEnglishFallbacks( string $lang, string $path ): void {
		$enData   = self::loadJson( self::i18nDir() . '/en.json' );
		$langData = self::loadJson( $path );

		$untranslated = [];
		foreach ( $enData as $key => $enValue ) {
			if ( str_starts_with( $key, '@' ) ) {
				continue;
			}
			if ( in_array( $key, self::IDENTICAL_TO_ENGLISH_ALLOWED, true ) ) {
				continue;
			}
			if ( !isset( $langData[$key] ) ) {
				// Missing keys are caught by testTranslationContainsAllEnglishKeys.
				continue;
			}
			if ( $langData[$key] === $enValue ) {
				$untranslated[] = $key;
			}
		}

		$this->assertEmpty(
			$untranslated,
			sprintf(
				"Translation '%s' has %d untranslated (English-fallback) value(s):\n  %s\n\n"
				. "ACTION REQUIRED: replace each English-text value above with a real\n"
				. "translation in i18n/%s.json.\n"
				. "If a value is legitimately identical to English (e.g. it is a brand name\n"
				. "or technical abbreviation), add the key to IDENTICAL_TO_ENGLISH_ALLOWED\n"
				. "in tests/unit/I18nCompletenessTest.php with a justification comment.",
				$lang,
				count( $untranslated ),
				implode( "\n  ", $untranslated ),
				$lang
			)
		);
	}
}
