<?php

declare(strict_types=1);

// Kept for backwards compatibility. The canonical class lives in the Processors sub-namespace.
class_alias(
    \MediaWiki\Extension\PandocUltimateConverter\Processors\PandocTextPostprocessor::class,
    \MediaWiki\Extension\PandocUltimateConverter\PandocTextPostprocessor::class
);