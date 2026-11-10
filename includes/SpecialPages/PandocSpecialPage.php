<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\SpecialPages;

/**
 * Preserve configurable special-page permissions across MediaWiki versions.
 */
abstract class PandocSpecialPage extends \SpecialPage {

	private string $restriction;

	public function __construct( string $name, string $restriction ) {
		$this->restriction = $restriction;
		// Treat prereleases such as 1.46.0-wmf.1 as part of the 1.46 API.
		$mwVersion = defined( 'MW_VERSION' ) ? explode( '-', MW_VERSION, 2 )[0] : '0';
		if ( version_compare( $mwVersion, '1.46', '<' ) ) {
			// Before 1.46, core permission checks read mRestriction directly.
			parent::__construct( $name, $restriction );
		} else {
			// Since 1.46, core uses getRestriction() and deprecates extra arguments.
			parent::__construct( $name );
		}
	}

	/** @inheritDoc */
	public function getRestriction(): string {
		return $this->restriction;
	}
}
