<?php

/**
 * PHPUnit bootstrap for PandocUltimateConverter unit tests.
 *
 * Provides lightweight stubs for the MediaWiki globals and classes that the
 * extension code references, so that the unit-test suite can run without a
 * full MediaWiki installation.
 */

declare( strict_types=1 );

require_once __DIR__ . '/../vendor/autoload.php';

// Load the MediaWiki stub definitions (namespace-scoped, so kept in a
// separate file to satisfy PHP's rule that namespace declarations must
// appear before any other code within a file).
require_once __DIR__ . '/stubs/MediaWikiStubs.php';
