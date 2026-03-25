<?php

/**
 * Stubs for MediaWiki global functions and classes.
 *
 * This file mixes global-namespace code with namespace-scoped class definitions,
 * so it must use the bracketed namespace syntax for all blocks.
 */

// ---------------------------------------------------------------------------
// Global namespace — function stubs and constants
// ---------------------------------------------------------------------------

namespace {

	if ( !function_exists( 'wfDebugLog' ) ) {
		/**
		 * MediaWiki debug-log stub — silently discards messages in test context.
		 */
		function wfDebugLog( string $logGroup, string $message, string $dest = 'private', array $context = [] ): void {
			// no-op in tests
		}
	}

	if ( !function_exists( 'wfBaseName' ) ) {
		/**
		 * MediaWiki wfBaseName stub — mirrors the real implementation.
		 */
		function wfBaseName( string $path, string $suffix = '' ): string {
			$path = rtrim( $path, '/\\' );
			$base = basename( $path );
			if ( $suffix !== '' && str_ends_with( $base, $suffix ) ) {
				$base = substr( $base, 0, -strlen( $suffix ) );
			}
			return $base;
		}
	}

	if ( !function_exists( 'wfMessage' ) ) {
		/**
		 * MediaWiki wfMessage stub — returns a minimal message object.
		 */
		function wfMessage( string $key, mixed ...$args ): object {
			return new class( $key ) {
				private string $key;
				public function __construct( string $k ) { $this->key = $k; }
				public function text(): string { return $this->key; }
				public function __toString(): string { return $this->key; }
			};
		}
	}

	if ( !function_exists( 'wfEscapeWikiText' ) ) {
		/**
		 * MediaWiki wfEscapeWikiText stub — escapes characters that have special
		 * meaning in wikitext so the string is displayed literally.
		 */
		function wfEscapeWikiText( string $input ): string {
			// Mirror the real MediaWiki implementation: escape the characters that are
			// special in wikitext markup.
			return strtr( $input, [
				'['  => '&#91;',
				']'  => '&#93;',
				'{'  => '&#123;',
				'}'  => '&#125;',
				'|'  => '&#124;',
				"'"  => '&#39;',
				'='  => '&#61;',
				'<'  => '&lt;',
				'>'  => '&gt;',
				'#'  => '&#35;',
				'*'  => '&#42;',
				';'  => '&#59;',
				':'  => '&#58;',
				"\n" => ' ',
			] );
		}
	}

	// MediaWiki namespace constants used by SpecialPandocExport.
	if ( !defined( 'NS_FILE' ) ) {
		define( 'NS_FILE', 6 );
	}
	if ( !defined( 'NS_MEDIA' ) ) {
		define( 'NS_MEDIA', -2 );
	}

} // end namespace {}

// ---------------------------------------------------------------------------
// Global namespace — MediaWiki class stubs
// ---------------------------------------------------------------------------

namespace {

	if ( !class_exists( 'SpecialPage' ) ) {
		/**
		 * Minimal stub for MediaWiki's SpecialPage base class.
		 * Only the members referenced by SpecialPandocExport need to exist here.
		 */
		class SpecialPage {
			public function __construct( string $name = '', string $restriction = '' ) {}
			public function setHeaders(): void {}
			public function checkPermissions(): void {}
			public function getRequest(): object {
				return new class {
					public function getVal( string $key, mixed $default = null ): mixed { return $default; }
					public function getArray( string $key ): ?array { return null; }
				};
			}
			public function getOutput(): object {
				return new class {
					public function disable(): void {}
					public function addModules( string $m ): void {}
					public function addJsConfigVars( array $v ): void {}
					public function addHTML( string $h ): void {}
					public function addWikiTextAsInterface( string $t ): void {}
				};
			}
			public function getPageTitle(): object {
				return new class {
					public function getLocalURL(): string { return ''; }
				};
			}
		}
	}

} // end namespace {}

// ---------------------------------------------------------------------------
// MediaWiki\Shell namespace — Shell class stub
// ---------------------------------------------------------------------------

namespace MediaWiki\Shell {

	if ( !class_exists( Shell::class ) ) {
		/**
		 * Stub for MediaWiki\Shell\Shell.
		 *
		 * Unit tests that actually invoke Pandoc should use the integration test
		 * suite; unit tests that merely load extension classes will never reach
		 * the Shell::command() call paths.
		 */
		class Shell {
			public static function command( array $cmd ): object {
				throw new \RuntimeException(
					'Shell::command() called during a unit test. ' .
					'Integration tests that require Pandoc belong in tests/integration/.'
				);
			}
		}
	}

} // end namespace MediaWiki\Shell
