<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter;

use EchoEventPresentationModel;
use SpecialPage;

/**
 * Echo notification presentation model for the 'confluence-migration-done' event.
 *
 * Displays a notification to the user who triggered a Confluence space migration
 * once the background job completes (successfully or with a fatal error).
 */
class ConfluenceMigrationPresentationModel extends EchoEventPresentationModel {

	/** @inheritDoc */
	public function getIconType(): string {
		return 'placeholder';
	}

	/** @inheritDoc */
	public function getHeaderMessage(): \Message {
		$spaceKey   = (string)$this->event->getExtraParam( 'spaceKey', '' );
		$count      = (int)$this->event->getExtraParam( 'migratedCount', 0 );
		$fatalError = (string)$this->event->getExtraParam( 'fatalError', '' );

		if ( $fatalError !== '' ) {
			return $this->msg( 'confluencemigration-notify-error', $spaceKey );
		}

		return $this->msg( 'confluencemigration-notify-done', $spaceKey, $count );
	}

	/** @inheritDoc */
	public function getPrimaryLink(): array {
		return [
			'url'   => SpecialPage::getTitleFor( 'ConfluenceMigration' )->getFullURL(),
			'label' => $this->msg( 'confluencemigration' )->text(),
		];
	}
}
