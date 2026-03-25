<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit;

use MediaWiki\Extension\PandocUltimateConverter\PandocWrapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PandocWrapper utility methods that have no MediaWiki dependencies.
 *
 * @covers \MediaWiki\Extension\PandocUltimateConverter\PandocWrapper::deleteDirectory
 */
class PandocWrapperTest extends TestCase {

	/** @var string Temporary directory used by all tests in this class. */
	private string $tmpBase;

	protected function setUp(): void {
		$this->tmpBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pandoc_wrapper_test_' . uniqid();
		mkdir( $this->tmpBase, 0755, true );
	}

	protected function tearDown(): void {
		// Best-effort cleanup in case a test left something behind.
		if ( is_dir( $this->tmpBase ) ) {
			PandocWrapper::deleteDirectory( $this->tmpBase );
		}
	}

	// ------------------------------------------------------------------
	// deleteDirectory
	// ------------------------------------------------------------------

	public function testDeleteDirectoryReturnsTrueForNonExistentPath(): void {
		$path = $this->tmpBase . DIRECTORY_SEPARATOR . 'does_not_exist';
		$this->assertTrue( PandocWrapper::deleteDirectory( $path ) );
	}

	public function testDeleteDirectoryDeletesASingleFile(): void {
		$file = $this->tmpBase . DIRECTORY_SEPARATOR . 'file.txt';
		file_put_contents( $file, 'hello' );

		$this->assertTrue( PandocWrapper::deleteDirectory( $file ) );
		$this->assertFileDoesNotExist( $file );
	}

	public function testDeleteDirectoryDeletesAnEmptyDirectory(): void {
		$dir = $this->tmpBase . DIRECTORY_SEPARATOR . 'emptydir';
		mkdir( $dir );

		$this->assertTrue( PandocWrapper::deleteDirectory( $dir ) );
		$this->assertDirectoryDoesNotExist( $dir );
	}

	public function testDeleteDirectoryDeletesNestedDirectoryTree(): void {
		// Create: base/a/b/c.txt  and  base/a/d.txt
		$subA = $this->tmpBase . DIRECTORY_SEPARATOR . 'a';
		$subB = $subA . DIRECTORY_SEPARATOR . 'b';
		mkdir( $subB, 0755, true );
		file_put_contents( $subB . DIRECTORY_SEPARATOR . 'c.txt', 'nested' );
		file_put_contents( $subA . DIRECTORY_SEPARATOR . 'd.txt', 'sibling' );

		$this->assertTrue( PandocWrapper::deleteDirectory( $this->tmpBase ) );
		$this->assertDirectoryDoesNotExist( $this->tmpBase );
	}

	public function testDeleteDirectoryHandlesMultipleFilesInDirectory(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			file_put_contents( $this->tmpBase . DIRECTORY_SEPARATOR . "file$i.txt", "content $i" );
		}

		$this->assertTrue( PandocWrapper::deleteDirectory( $this->tmpBase ) );
		$this->assertDirectoryDoesNotExist( $this->tmpBase );
	}

	public function testDeleteDirectoryReturnsTrueAfterSuccessfulDeletion(): void {
		$dir = $this->tmpBase . DIRECTORY_SEPARATOR . 'target';
		mkdir( $dir );
		file_put_contents( $dir . DIRECTORY_SEPARATOR . 'readme.md', '# doc' );

		$result = PandocWrapper::deleteDirectory( $dir );

		$this->assertTrue( $result );
		$this->assertDirectoryDoesNotExist( $dir );
		// Parent should still exist
		$this->assertDirectoryExists( $this->tmpBase );
	}
}
