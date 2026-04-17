<?php

declare( strict_types=1 );

/**
 * Real MediaWiki\Shell implementation for e2e tests.
 *
 * Provides a working Shell::command() via proc_open() so that e2e tests can
 * invoke Pandoc, pdftohtml, tesseract, libreoffice, etc. through the same
 * code paths used in production.
 *
 * This file must be loaded BEFORE tests/stubs/MediaWikiStubs.php because
 * MediaWikiStubs.php guards its stub with `if (!class_exists(Shell::class))`.
 * Loading the real implementation first causes the stub to be skipped.
 */

namespace MediaWiki\Shell {

	if ( !class_exists( ShellResult::class ) ) {
		class ShellResult {
			public function __construct(
				private int $exitCode,
				private string $stdout,
				private string $stderr = ''
			) {}

			public function getExitCode(): int {
				return $this->exitCode;
			}

			public function getStdout(): string {
				return $this->stdout;
			}

			public function getStderr(): string {
				return $this->stderr;
			}
		}
	}

	if ( !class_exists( ShellCommand::class ) ) {
		class ShellCommand {
			private bool $includeStderr = false;
			/** @var array<string, string>|null */
			private ?array $env = null;

			/** @param string[] $cmd */
			public function __construct( private array $cmd ) {}

			public function includeStderr(): static {
				$this->includeStderr = true;
				return $this;
			}

			/** @param array<string, string> $env */
			public function environment( array $env ): static {
				$this->env = $env;
				return $this;
			}

			public function execute(): ShellResult {
				$spec = [
					0 => [ 'pipe', 'r' ],
					1 => [ 'pipe', 'w' ],
					2 => [ 'pipe', 'w' ],
				];

				// proc_open() accepts an array command on PHP 7.4+ (no shell quoting needed).
				$proc = proc_open( $this->cmd, $spec, $pipes, null, $this->env );

				if ( !is_resource( $proc ) ) {
					return new ShellResult( 127, 'proc_open() failed to start the process' );
				}

				fclose( $pipes[0] );
				$out  = (string) stream_get_contents( $pipes[1] );
				fclose( $pipes[1] );
				$err  = (string) stream_get_contents( $pipes[2] );
				fclose( $pipes[2] );
				$code = proc_close( $proc );

				if ( $this->includeStderr && $err !== '' ) {
					$out .= $err;
				}

				return new ShellResult( $code, $out, $err );
			}
		}
	}

	if ( !class_exists( Shell::class ) ) {
		class Shell {
			/** @param string[] $cmd */
			public static function command( array $cmd ): ShellCommand {
				return new ShellCommand( $cmd );
			}
		}
	}

} // end namespace MediaWiki\Shell
