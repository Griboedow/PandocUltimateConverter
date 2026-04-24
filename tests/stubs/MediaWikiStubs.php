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

	if ( !class_exists( 'Job' ) ) {
		/**
		 * Minimal stub for MediaWiki's Job base class.
		 */
		class Job {
			public $params;
			public $removeDuplicates = false;
			public function __construct( string $type, $title, array $params = [] ) {
				$this->params = $params;
			}
			public function setLastError( string $error ): void {}
		}
	}

} // end namespace {}

// ---------------------------------------------------------------------------
// MediaWiki\Title namespace — Title class stub
// ---------------------------------------------------------------------------

namespace MediaWiki\Title {

	if ( !class_exists( Title::class ) ) {
		/**
		 * Minimal stub for MediaWiki's Title class (canonical namespace).
		 * A global alias \Title is registered below for backward compatibility.
		 */
		class Title {
			private string $text;
			public function __construct( string $text = '' ) { $this->text = $text; }
			public static function newFromText( string $text ): ?self { return new self( $text ); }
			public static function newMainPage(): self { return new self( 'Main Page' ); }
			public static function makeTitleSafe( int $ns, string $title ): ?self { return new self( $title ); }
			public function getText(): string { return $this->text; }
			public function exists(): bool { return false; }
			public function getNamespace(): int { return 0; }
			public function getLocalURL(): string { return ''; }
			public function getFullURL(): string { return ''; }
		}
	}

} // end namespace MediaWiki\Title

namespace {

	// Register the global \Title alias that MW 1.42 provides (removed in 1.45).
	if ( !class_exists( 'Title' ) ) {
		class_alias( \MediaWiki\Title\Title::class, 'Title' );
	}

} // end namespace {}

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

// ---------------------------------------------------------------------------
// MediaWiki\Hook namespace — hook interface stubs
// ---------------------------------------------------------------------------

namespace MediaWiki\Hook {

	if ( !interface_exists( SkinTemplateNavigation__UniversalHook::class ) ) {
		/**
		 * Stub for the MediaWiki hook interface used by HookHandler.
		 * The real interface lives in MediaWiki core and is not available
		 * in the standalone unit-test environment.
		 */
		interface SkinTemplateNavigation__UniversalHook {
			public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void;
		}
	}

} // end namespace MediaWiki\Hook
