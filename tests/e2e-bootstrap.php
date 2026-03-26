<?php

/**
 * PHPUnit bootstrap for PandocUltimateConverter e2e tests.
 *
 * Unlike the unit-test bootstrap, this file provides a REAL Shell::command()
 * implementation (via proc_open) so that e2e tests can invoke external tools
 * (Pandoc, pdftohtml, tesseract, libreoffice) through the extension's normal
 * code paths.
 *
 * The real Shell is loaded BEFORE MediaWikiStubs.php so that the stub's
 * class_exists() guard prevents it from being overridden by the throw-only stub.
 */

declare( strict_types=1 );

require_once __DIR__ . '/../vendor/autoload.php';

// 1. Provide the real Shell::command() implementation.
//    Must be before MediaWikiStubs.php (see comment in RealShell.php).
require_once __DIR__ . '/stubs/RealShell.php';

// 2. Load the remaining MediaWiki stubs (wfDebugLog, wfBaseName, SpecialPage …).
//    The Shell stub inside MediaWikiStubs.php is skipped because Shell::class
//    is already defined by the step above.
require_once __DIR__ . '/stubs/MediaWikiStubs.php';
