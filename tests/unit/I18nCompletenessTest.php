<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that every i18n translation file contains all message keys
 * defined in the canonical English source (i18n/en.json).
 *
 * A translation may add no extra keys (compared with en.json), but it must
 * not be missing any.  The special qqq.json (message documentation) is
 * intentionally excluded because its purpose is different.
 *
 * @coversNothing
 */
class I18nCompletenessTest extends TestCase {

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
	// Test
	// ------------------------------------------------------------------

	/**
	 * @dataProvider provideTranslationFiles
	 */
	public function testTranslationContainsAllEnglishKeys( string $lang, string $path ): void {
		$enKeys  = self::messageKeys( self::loadJson( self::i18nDir() . '/en.json' ) );
		$langKeys = self::messageKeys( self::loadJson( $path ) );

		$missing = array_diff( $enKeys, $langKeys );

		$this->assertEmpty(
			$missing,
			sprintf(
				"Translation '%s' is missing %d message key(s):\n  %s",
				$lang,
				count( $missing ),
				implode( "\n  ", $missing )
			)
		);
	}
}
