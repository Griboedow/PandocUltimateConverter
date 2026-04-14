<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Tests\Unit;

use MediaWiki\Extension\PandocUltimateConverter\Processors\VideoPreprocessor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for VideoPreprocessor — the timestamp calculation and audio extraction
 * logic that does not require the ffmpeg binary.
 *
 * Frame extraction and getVideoDuration() invoke the real ffmpeg binary and are
 * therefore integration-level tests covered by the e2e suite.
 *
 * @covers \MediaWiki\Extension\PandocUltimateConverter\Processors\VideoPreprocessor
 */
class VideoPreprocessorTest extends TestCase {

	private VideoPreprocessor $preprocessor;
	private ReflectionMethod $calculateTimestamps;

	protected function setUp(): void {
		$this->preprocessor        = new VideoPreprocessor();
		$this->calculateTimestamps = new ReflectionMethod( VideoPreprocessor::class, 'calculateTimestamps' );
	}

	// ------------------------------------------------------------------
	// calculateTimestamps — evenly-spaced mode (frameIntervalSeconds = 0)
	// ------------------------------------------------------------------

	public function testEvenlySpacedSingleFrameIsPlacedAtMidpoint(): void {
		$pp = new VideoPreprocessor( 'ffmpeg', 1, 0 );
		$rm = new ReflectionMethod( VideoPreprocessor::class, 'calculateTimestamps' );
		$ts = $rm->invoke( $pp, 60.0 );

		$this->assertCount( 1, $ts );
		$this->assertEqualsWithDelta( 30.0, $ts[0], 0.001 );
	}

	public function testEvenlySpacedTwoFramesAreAtStartAndEnd(): void {
		$pp = new VideoPreprocessor( 'ffmpeg', 2, 0 );
		$rm = new ReflectionMethod( VideoPreprocessor::class, 'calculateTimestamps' );
		$ts = $rm->invoke( $pp, 100.0 );

		$this->assertCount( 2, $ts );
		$this->assertEqualsWithDelta( 0.0,  $ts[0], 0.001 );
		$this->assertEqualsWithDelta( 99.5, $ts[1], 0.001 ); // clamped: 100 - 0.5
	}

	public function testEvenlySpacedTenFramesAcross60Seconds(): void {
		$pp = new VideoPreprocessor( 'ffmpeg', 10, 0 );
		$rm = new ReflectionMethod( VideoPreprocessor::class, 'calculateTimestamps' );
		$ts = $rm->invoke( $pp, 60.0 );

		$this->assertCount( 10, $ts );
		$this->assertEqualsWithDelta( 0.0, $ts[0], 0.001 );
		// Each step = 60 / (10-1) ≈ 6.667 s
		$this->assertEqualsWithDelta( 60.0 / 9.0, $ts[1], 0.001 );
	}

	public function testEvenlySpacedFramesAreNonDecreasing(): void {
		$pp = new VideoPreprocessor( 'ffmpeg', 5, 0 );
		$rm = new ReflectionMethod( VideoPreprocessor::class, 'calculateTimestamps' );
		$ts = $rm->invoke( $pp, 120.0 );

		for ( $i = 1; $i < count( $ts ); $i++ ) {
			$this->assertGreaterThanOrEqual( $ts[$i - 1], $ts[$i] );
		}
	}

	public function testEvenlySpacedNeverExceedsDurationMinusHalfSecond(): void {
		$pp = new VideoPreprocessor( 'ffmpeg', 15, 0 );
		$rm = new ReflectionMethod( VideoPreprocessor::class, 'calculateTimestamps' );
		$ts = $rm->invoke( $pp, 30.0 );

		foreach ( $ts as $t ) {
			$this->assertLessThanOrEqual( 29.5, $t, 'Timestamp must not exceed duration - 0.5' );
		}
	}

	// ------------------------------------------------------------------
	// calculateTimestamps — fixed-interval mode (frameIntervalSeconds > 0)
	// ------------------------------------------------------------------

	public function testFixedIntervalOf10SecondsFor60sDuration(): void {
		$pp = new VideoPreprocessor( 'ffmpeg', 10, 10 );
		$rm = new ReflectionMethod( VideoPreprocessor::class, 'calculateTimestamps' );
		$ts = $rm->invoke( $pp, 60.0 );

		// t = 0, 10, 20, 30, 40, 50  (stops before 60)
		$this->assertCount( 6, $ts );
		$this->assertEqualsWithDelta( 0.0,  $ts[0], 0.001 );
		$this->assertEqualsWithDelta( 10.0, $ts[1], 0.001 );
		$this->assertEqualsWithDelta( 50.0, $ts[5], 0.001 );
	}

	public function testFixedIntervalIsCapByMaxFrames(): void {
		// Duration is 120 s at 10 s interval → 12 natural frames, but max is 5
		$pp = new VideoPreprocessor( 'ffmpeg', 5, 10 );
		$rm = new ReflectionMethod( VideoPreprocessor::class, 'calculateTimestamps' );
		$ts = $rm->invoke( $pp, 120.0 );

		$this->assertCount( 5, $ts );
	}

	public function testFixedIntervalLargerThanDurationYieldsSingleFrame(): void {
		// Interval of 30 s for a 10 s video → only t=0 is < duration
		$pp = new VideoPreprocessor( 'ffmpeg', 10, 30 );
		$rm = new ReflectionMethod( VideoPreprocessor::class, 'calculateTimestamps' );
		$ts = $rm->invoke( $pp, 10.0 );

		$this->assertCount( 1, $ts );
		$this->assertEqualsWithDelta( 0.0, $ts[0], 0.001 );
	}

	// ------------------------------------------------------------------
	// Constructor — hard cap on maxFrames
	// ------------------------------------------------------------------

	public function testMaxFramesIsCappedAt30(): void {
		$pp = new VideoPreprocessor( 'ffmpeg', 100, 0 );
		$rm = new ReflectionMethod( VideoPreprocessor::class, 'calculateTimestamps' );
		$ts = $rm->invoke( $pp, 3600.0 );

		$this->assertCount( 30, $ts );
	}

	public function testMaxFramesIsAtLeastOne(): void {
		$pp = new VideoPreprocessor( 'ffmpeg', 0, 0 );
		$rm = new ReflectionMethod( VideoPreprocessor::class, 'calculateTimestamps' );
		$ts = $rm->invoke( $pp, 60.0 );

		$this->assertCount( 1, $ts );
	}

	// ------------------------------------------------------------------
	// Constructor — negative frameInterval is clamped to 0
	// ------------------------------------------------------------------

	public function testNegativeFrameIntervalFallsBackToEvenlySpaced(): void {
		$pp = new VideoPreprocessor( 'ffmpeg', 5, -10 );
		$rm = new ReflectionMethod( VideoPreprocessor::class, 'calculateTimestamps' );
		$ts = $rm->invoke( $pp, 60.0 );

		// With interval clamped to 0, should produce exactly maxFrames timestamps
		$this->assertCount( 5, $ts );
	}
}
