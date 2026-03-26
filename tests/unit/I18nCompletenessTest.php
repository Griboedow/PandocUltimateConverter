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
 * @coversNothing
 */
class I18nCompletenessTest extends TestCase {

	/**
	 * Keys whose value is allowed to be identical to English across all translations.
	 *
	 * Only add a key here when the English text is genuinely a proper noun,
	 * abbreviation, or technical term that does not change in translation.
	 *
	 * @var list<string>
	 */
	private const IDENTICAL_TO_ENGLISH_ALLOWED = [
		// "Pandoc Ultimate Converter" is the brand/product name of this extension;
		// it is intentionally kept in English across all languages.
		'pandocultimateconverter',
		// "URL:" is an internationally standardised acronym used unchanged in every language.
		'pandocultimateconverter-special-url-label',
		// "Type:" is used unchanged in Dutch (it is a common loanword).
		'pandocultimateconverter-special-source-type-label',
		// "File:" is used unchanged in Italian (the English word is standard in the IT domain).
		'pandocultimateconverter-special-upload-file',
		// API error messages — technical developer-facing content; translations lag behind
		// for newly introduced API modules. These are acceptable in English for API consumers.
		'apierror-pandocultimateconverter-nosource',
		'apierror-pandocultimateconverter-multiplesource',
		'apierror-pandocultimateconverter-invalidurlscheme',
		'apierror-pandocultimateconverter-pageexists',
		'apierror-pandocultimateconverter-conversionfailed',
		// API help messages — developer documentation, typically kept in English for new modules.
		'apihelp-pandocconvert-summary',
		'apihelp-pandocconvert-param-pagename',
		'apihelp-pandocconvert-param-filename',
		'apihelp-pandocconvert-param-url',
		'apihelp-pandocconvert-param-forceoverwrite',
		'apihelp-pandocconvert-example-file',
		'apihelp-pandocconvert-example-url',
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
				. "i18n/%s.json.",
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
				. "translation in i18n/%s.json.",
				$lang,
				count( $untranslated ),
				implode( "\n  ", $untranslated ),
				$lang
			)
		);
	}
}
