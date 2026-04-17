<?php

declare( strict_types=1 );

namespace {
	if ( !class_exists( 'ApiBase' ) ) {
		class ApiBase {}
	}
}

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit {

	use MediaWiki\Extension\PandocUltimateConverter\Api\ApiConfluenceJobs;
	use PHPUnit\Framework\TestCase;
	use ReflectionMethod;

	/**
	 * @covers \MediaWiki\Extension\PandocUltimateConverter\Api\ApiConfluenceJobs
	 */
	class ApiConfluenceJobsFilterTest extends TestCase {

		private ReflectionMethod $method;

		protected function setUp(): void {
			parent::setUp();
			$this->method = new ReflectionMethod( ApiConfluenceJobs::class, 'belongsToUser' );
			$this->method->setAccessible( true );
		}

		private function invokeBelongsToUser( array $params, ?int $userId ): bool {
			return $this->method->invoke( null, $params, $userId );
		}

		public function testNoUserFilterIncludesAllJobs(): void {
			$this->assertTrue( $this->invokeBelongsToUser( [ 'userId' => 123 ], null ) );
			$this->assertTrue( $this->invokeBelongsToUser( [], null ) );
		}

		public function testMatchingUserIdIsIncluded(): void {
			$this->assertTrue( $this->invokeBelongsToUser( [ 'userId' => 42 ], 42 ) );
		}

		public function testDifferentUserIdIsExcluded(): void {
			$this->assertFalse( $this->invokeBelongsToUser( [ 'userId' => 7 ], 42 ) );
		}

		public function testLegacyJobsWithoutUserIdAreExcludedForScopedList(): void {
			$this->assertFalse( $this->invokeBelongsToUser( [], 42 ) );
		}
	}
}
