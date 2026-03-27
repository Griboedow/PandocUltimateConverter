<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit;

use MediaWiki\Extension\PandocUltimateConverter\HookHandler;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for HookHandler.
 *
 * The hook handler integrates with MediaWiki's skin navigation system to add
 * an "Export" action to the page toolbar.  These tests verify:
 *
 *   1. The class implements the correct MediaWiki hook interface.
 *   2. The hook method exists and has the correct signature.
 *   3. The extension.json registration matches (structural checks).
 *
 * End-to-end verification that the link actually appears in a rendered wiki
 * page is done by the MediaWiki smoke test in .github/workflows/tests.yml.
 *
 * @covers \MediaWiki\Extension\PandocUltimateConverter\HookHandler
 */
class HookHandlerTest extends TestCase {

	// ------------------------------------------------------------------
	// Interface contract
	// ------------------------------------------------------------------

	public function testHookHandlerImplementsSkinNavigationHookInterface(): void {
		$this->assertInstanceOf(
			SkinTemplateNavigation__UniversalHook::class,
			new HookHandler(),
			'HookHandler must implement SkinTemplateNavigation__UniversalHook'
		);
	}

	public function testOnSkinTemplateNavigationUniversalMethodExists(): void {
		$rc = new ReflectionClass( HookHandler::class );
		$this->assertTrue(
			$rc->hasMethod( 'onSkinTemplateNavigation__Universal' ),
			'HookHandler must have onSkinTemplateNavigation__Universal() method'
		);
	}

	public function testOnSkinTemplateNavigationUniversalIsPublic(): void {
		$rc     = new ReflectionClass( HookHandler::class );
		$method = $rc->getMethod( 'onSkinTemplateNavigation__Universal' );
		$this->assertTrue(
			$method->isPublic(),
			'onSkinTemplateNavigation__Universal() must be public'
		);
	}

	public function testOnSkinTemplateNavigationUniversalHasTwoParameters(): void {
		$rc     = new ReflectionClass( HookHandler::class );
		$method = $rc->getMethod( 'onSkinTemplateNavigation__Universal' );
		$this->assertCount(
			2,
			$method->getParameters(),
			'onSkinTemplateNavigation__Universal() must have exactly two parameters'
		);
	}

	// ------------------------------------------------------------------
	// extension.json structural checks
	// ------------------------------------------------------------------

	public function testExtensionJsonRegistersHookHandler(): void {
		$extensionJsonPath = __DIR__ . '/../../extension.json';
		$this->assertFileExists( $extensionJsonPath, 'extension.json must exist' );

		$data = json_decode( (string) file_get_contents( $extensionJsonPath ), true );
		$this->assertIsArray( $data, 'extension.json must be valid JSON' );

		// Verify the HookHandlers key references HookHandler
		$this->assertArrayHasKey( 'HookHandlers', $data,
			'extension.json must have a HookHandlers section' );
		$hookHandlerNames = array_keys( $data['HookHandlers'] );
		$this->assertNotEmpty( $hookHandlerNames, 'At least one HookHandler must be registered' );

		// Verify the SkinTemplateNavigation::Universal hook is present
		$this->assertArrayHasKey( 'Hooks', $data, 'extension.json must have a Hooks section' );
		$this->assertArrayHasKey(
			'SkinTemplateNavigation::Universal',
			$data['Hooks'],
			'extension.json must register the SkinTemplateNavigation::Universal hook'
		);
	}

	public function testExtensionJsonExportInPageToolsConfigExists(): void {
		$data = json_decode(
			(string) file_get_contents( __DIR__ . '/../../extension.json' ),
			true
		);

		$this->assertArrayHasKey( 'config', $data, 'extension.json must have a config section' );
		$this->assertArrayHasKey(
			'PandocUltimateConverter_ShowExportInPageTools',
			$data['config'],
			'extension.json must define the PandocUltimateConverter_ShowExportInPageTools config key'
		);
	}

	// ------------------------------------------------------------------
	// Structural check: PandocExport special page is registered
	// ------------------------------------------------------------------

	public function testExtensionJsonRegistersPandocExportSpecialPage(): void {
		$data = json_decode(
			(string) file_get_contents( __DIR__ . '/../../extension.json' ),
			true
		);

		$this->assertArrayHasKey( 'SpecialPages', $data,
			'extension.json must have a SpecialPages section' );
		$this->assertArrayHasKey( 'PandocExport', $data['SpecialPages'],
			'PandocExport special page must be registered in extension.json' );
	}
}
