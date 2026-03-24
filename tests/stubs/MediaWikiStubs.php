<?php

/**
 * Stubs for MediaWiki global functions and classes.
 *
 * This file mixes global-namespace code with namespace-scoped class definitions,
 * so it must use the bracketed namespace syntax for all blocks.
 */

// ---------------------------------------------------------------------------
// Global namespace — function stubs
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
