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
			$this->method = new ReflectionMethod( ApiConfluenceJobs::class, 'shouldIncludeJobForUser' );
			$this->method->setAccessible( true );
		}

		private function includeForUser( array $params, ?int $userId ): bool {
			return $this->method->invoke( null, $params, $userId );
		}

		public function testNoUserFilterIncludesAllJobs(): void {
			$this->assertTrue( $this->includeForUser( [ 'userId' => 123 ], null ) );
			$this->assertTrue( $this->includeForUser( [], null ) );
		}

		public function testMatchingUserIdIsIncluded(): void {
			$this->assertTrue( $this->includeForUser( [ 'userId' => 42 ], 42 ) );
		}

		public function testDifferentUserIdIsExcluded(): void {
			$this->assertFalse( $this->includeForUser( [ 'userId' => 7 ], 42 ) );
		}

		public function testLegacyJobsWithoutUserIdAreExcludedForScopedList(): void {
			$this->assertFalse( $this->includeForUser( [], 42 ) );
		}
	}
}
