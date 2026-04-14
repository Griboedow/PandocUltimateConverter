<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Processors;

use MediaWiki\Shell\Shell;

/**
 * Extracts representative frames and audio from a video file using ffmpeg.
 *
 * The extracted JPEG frames are written to a work directory and uploaded to
 * the wiki; the extracted audio is transcribed by an LLM to enrich the
 * generated article with narration content.
 *
 * Pipeline:
 *   Video file → ffmpeg frame extraction → JPEG frames in $outputDir
 *             → ffmpeg audio extraction → audio.mp3 in separate temp dir
 *               → VideoToWikitextService (LLM vision + transcript) → MediaWiki wikitext
 *
 * Prerequisites: ffmpeg must be installed and accessible.
 */
class VideoPreprocessor {

	private string $ffmpegPath;
	private int $maxFrames;
	private int $frameIntervalSeconds;

	/** Hard cap on the number of frames that may be extracted in a single run. */
	private const HARD_MAX_FRAMES = 30;

	/**
	 * @param string $ffmpegPath           Path to the ffmpeg executable (or bare name if in PATH).
	 * @param int    $maxFrames            Maximum frames to extract (capped at HARD_MAX_FRAMES).
	 * @param int    $frameIntervalSeconds Fixed interval in seconds between frames.
	 *                                     0 = evenly space $maxFrames across the full duration.
	 */
	public function __construct(
		string $ffmpegPath = 'ffmpeg',
		int $maxFrames = 10,
		int $frameIntervalSeconds = 0
	) {
		$this->ffmpegPath           = $ffmpegPath;
		$this->maxFrames            = max( 1, min( self::HARD_MAX_FRAMES, $maxFrames ) );
		$this->frameIntervalSeconds = max( 0, $frameIntervalSeconds );
	}

	/**
	 * Extract evenly-spaced (or fixed-interval) frames from a video file.
	 *
	 * Frames are saved as frame-001.jpg, frame-002.jpg, … in $outputDir.
	 * Only files that were actually produced by ffmpeg are included in the
	 * returned list; if a frame could not be extracted it is silently skipped.
	 *
	 * @param string $videoPath  Absolute path to the source video file.
	 * @param string $outputDir  Absolute path to an existing writable directory.
	 * @return string[]          Absolute paths to the extracted JPEG frame files, in order.
	 * @throws \RuntimeException If ffmpeg is unavailable or no frames could be extracted.
	 */
	public function extractFrames( string $videoPath, string $outputDir ): array {
		$duration   = $this->getVideoDuration( $videoPath );
		$timestamps = $this->calculateTimestamps( $duration );

		$framePaths = [];
		foreach ( $timestamps as $idx => $ts ) {
			$framePath = $outputDir . DIRECTORY_SEPARATOR . sprintf( 'frame-%03d.jpg', $idx + 1 );

			$cmd = [
				$this->ffmpegPath,
				'-ss', number_format( $ts, 3, '.', '' ),
				'-i', $videoPath,
				'-vframes', '1',
				'-q:v', '3',   // JPEG quality (1 = best quality, 31 = worst; 3 is a good balance)
				'-y',          // Overwrite the output file if it already exists
				$framePath,
			];

			wfDebugLog(
				'PandocUltimateConverter',
				'VideoPreprocessor::extractFrames: running ' . implode( ' ', $cmd )
			);

			$result = Shell::command( $cmd )
				->includeStderr()
				->execute();

			if ( file_exists( $framePath ) ) {
				$framePaths[] = $framePath;
			} else {
				wfDebugLog(
					'PandocUltimateConverter',
					'VideoPreprocessor::extractFrames: frame not produced at ts='
					. number_format( $ts, 3, '.', '' )
					. ' (exit=' . $result->getExitCode() . ')'
				);
			}
		}

		if ( $framePaths === [] ) {
			throw new \RuntimeException(
				'ffmpeg could not extract any frames from: ' . $videoPath
				. '. Make sure ffmpeg is installed and the file is a valid video.'
			);
		}

		wfDebugLog(
			'PandocUltimateConverter',
			'VideoPreprocessor::extractFrames: extracted ' . count( $framePaths ) . ' frames from ' . $videoPath
		);

		return $framePaths;
	}

	/**
	 * Extract the audio channel of a video file as a mono MP3 optimised for speech recognition.
	 *
	 * The output file is written to $outputDir/audio.mp3.  Returns the absolute
	 * path on success, or null if the video has no audio track or ffmpeg fails.
	 * The caller is responsible for deleting the returned file when it is no
	 * longer needed (it must NOT be left in the same folder as the extracted
	 * frames, otherwise processImages() would attempt to upload the audio file).
	 *
	 * @param string $videoPath  Absolute path to the source video file.
	 * @param string $outputDir  Absolute path to a writable directory (separate from the frame folder).
	 * @return string|null       Absolute path to audio.mp3, or null on failure.
	 */
	public function extractAudio( string $videoPath, string $outputDir ): ?string {
		$audioPath = $outputDir . DIRECTORY_SEPARATOR . 'audio.mp3';

		$cmd = [
			$this->ffmpegPath,
			'-i',    $videoPath,
			'-vn',                 // Drop video stream
			'-ar',   '16000',      // 16 kHz sample rate — optimal for Whisper
			'-ac',   '1',          // Mono
			'-q:a',  '4',          // VBR quality (lower = better; 4 ≈ 128 kbps)
			'-y',                  // Overwrite output if it already exists
			$audioPath,
		];

		wfDebugLog(
			'PandocUltimateConverter',
			'VideoPreprocessor::extractAudio: running ' . implode( ' ', $cmd )
		);

		$result = Shell::command( $cmd )
			->includeStderr()
			->execute();

		if ( !file_exists( $audioPath ) || filesize( $audioPath ) === 0 ) {
			wfDebugLog(
				'PandocUltimateConverter',
				'VideoPreprocessor::extractAudio: no audio produced'
				. ' (exit=' . $result->getExitCode() . '): '
				. substr( $result->getStdout(), 0, 300 )
			);
			return null;
		}

		wfDebugLog(
			'PandocUltimateConverter',
			'VideoPreprocessor::extractAudio: audio at ' . $audioPath
			. ' (' . filesize( $audioPath ) . ' bytes)'
		);

		return $audioPath;
	}

	/**
	 * Return the duration of a video in seconds by parsing ffmpeg's info output.
	 *
	 * ffmpeg writes container metadata (including "Duration: HH:MM:SS.ss") to
	 * stderr even when given an unsupported operation, so we capture stderr and
	 * parse it without executing any actual transcoding.
	 *
	 * @param string $videoPath Absolute path to the video file.
	 * @return float Duration in seconds (always >= 1.0).
	 * @throws \RuntimeException If the duration cannot be determined.
	 */
	public function getVideoDuration( string $videoPath ): float {
		// `ffmpeg -i <file>` exits with code 1 but writes container info to stderr.
		// Shell::includeStderr() merges stderr into stdout so we can read it.
		$result = Shell::command( [ $this->ffmpegPath, '-i', $videoPath ] )
			->includeStderr()
			->execute();

		$output = $result->getStdout();

		// Duration: HH:MM:SS.ss
		if ( !preg_match( '/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/', $output, $m ) ) {
			throw new \RuntimeException(
				'Could not determine video duration for: ' . $videoPath
				. '. ffmpeg output: ' . substr( $output, 0, 500 )
			);
		}

		$hours   = (float)$m[1];
		$minutes = (float)$m[2];
		$seconds = (float)$m[3];
		$total   = $hours * 3600.0 + $minutes * 60.0 + $seconds;

		wfDebugLog(
			'PandocUltimateConverter',
			'VideoPreprocessor::getVideoDuration: ' . $videoPath . ' → ' . $total . 's'
		);

		return max( 1.0, $total );
	}

	/**
	 * Calculate the timestamps (in seconds) at which to capture frames.
	 *
	 * In fixed-interval mode ($frameIntervalSeconds > 0) frames are captured
	 * at 0, interval, 2×interval, … up to $maxFrames or the end of the video.
	 *
	 * In evenly-spaced mode ($frameIntervalSeconds = 0) $maxFrames timestamps
	 * are distributed uniformly across the full duration.
	 *
	 * @param float $duration Total video duration in seconds.
	 * @return float[]        Sorted list of timestamps (seconds).
	 */
	public function calculateTimestamps( float $duration ): array {
		$timestamps = [];

		if ( $this->frameIntervalSeconds > 0 ) {
			for (
				$t = 0.0;
				$t < $duration && count( $timestamps ) < $this->maxFrames;
				$t += (float)$this->frameIntervalSeconds
			) {
				$timestamps[] = $t;
			}
		} else {
			if ( $this->maxFrames === 1 ) {
				$timestamps[] = $duration / 2.0;
			} else {
				$step = $duration / (float)( $this->maxFrames - 1 );
				for ( $i = 0; $i < $this->maxFrames; $i++ ) {
					// Clamp to slightly before the end to avoid out-of-range seeks
					$timestamps[] = min( $step * (float)$i, $duration - 0.5 );
				}
			}
		}

		return $timestamps;
	}
}
