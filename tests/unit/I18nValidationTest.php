<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Validates that every i18n/*.json file is valid JSON and follows the
 * standard MediaWiki i18n format:
 *
 *  - The file decodes to a JSON object (associative array).
 *  - A "@metadata" key is present whose value is an object with an
 *    "authors" array.
 *  - Every non-metadata key maps to a plain string value.
 *
 * @coversNothing
 */
class I18nValidationTest extends TestCase {

	/** @return array<string, array{string}> */
	public static function provideI18nFiles(): array {
		$i18nDir = __DIR__ . '/../../i18n';
		$files   = glob( $i18nDir . '/*.json' );
		if ( empty( $files ) ) {
			throw new \RuntimeException( 'No i18n JSON files found in ' . $i18nDir );
		}

		$cases = [];
		foreach ( $files as $path ) {
			$cases[ basename( $path ) ] = [ $path ];
		}
		return $cases;
	}

	/**
	 * Decode a JSON file and return the data as an associative array,
	 * or null if decoding fails.
	 *
	 * @return array<string, mixed>|null
	 */
	private function decodeJsonFile( string $path ): ?array {
		$raw = file_get_contents( $path );
		if ( $raw === false ) {
			return null;
		}
		$data = json_decode( $raw, true );
		if ( json_last_error() !== JSON_ERROR_NONE || !is_array( $data ) ) {
			return null;
		}
		return $data;
	}

	/** @dataProvider provideI18nFiles */
	public function testFileIsValidJson( string $path ): void {
		$this->assertFileExists( $path );

		$raw = file_get_contents( $path );
		$this->assertNotFalse( $raw, "Could not read $path" );

		$data = json_decode( $raw, true );
		$this->assertSame(
			JSON_ERROR_NONE,
			json_last_error(),
			sprintf( '%s is not valid JSON: %s', basename( $path ), json_last_error_msg() )
		);
		$this->assertIsArray(
			$data,
			sprintf( '%s must decode to a JSON object', basename( $path ) )
		);
	}

	/** @dataProvider provideI18nFiles */
	public function testFileHasMetadata( string $path ): void {
		$data = $this->decodeJsonFile( $path );
		$this->assertNotNull( $data, sprintf( '%s must be valid JSON', basename( $path ) ) );

		$this->assertArrayHasKey(
			'@metadata',
			$data,
			sprintf( '%s must contain a "@metadata" key', basename( $path ) )
		);
		$this->assertIsArray(
			$data['@metadata'],
			sprintf( '%s: "@metadata" must be a JSON object', basename( $path ) )
		);
		$this->assertArrayHasKey(
			'authors',
			$data['@metadata'],
			sprintf( '%s: "@metadata" must contain an "authors" key', basename( $path ) )
		);
		$this->assertIsArray(
			$data['@metadata']['authors'],
			sprintf( '%s: "@metadata.authors" must be a JSON array', basename( $path ) )
		);
	}

	/** @dataProvider provideI18nFiles */
	public function testMessageValuesAreStrings( string $path ): void {
		$data = $this->decodeJsonFile( $path );
		$this->assertNotNull( $data, sprintf( '%s must be valid JSON', basename( $path ) ) );

		foreach ( $data as $key => $value ) {
			if ( str_starts_with( $key, '@' ) ) {
				continue;
			}
			$this->assertIsString(
				$value,
				sprintf(
					'%s: message key "%s" must have a string value, got %s',
					basename( $path ),
					$key,
					gettype( $value )
				)
			);
		}
	}
}
