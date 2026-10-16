<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter;

use ApiMain;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Request\DerivativeRequest;

/** Save imports through the normal edit pipeline, including extension hooks and permissions. */
class PageImporter {

	public static function save( $user, string $title, string $text, string $summary,
		bool $overwrite = true, ?int $baseRevision = null
	): array {
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setUser( $user );
		$params = [
			'action' => 'edit', 'title' => $title, 'text' => $text,
			'summary' => $summary, 'token' => $user->getEditToken(),
		];
		if ( !$overwrite ) {
			$params['createonly'] = true;
		}
		if ( $baseRevision !== null ) {
			$params['baserevid'] = $baseRevision;
		}
		$context->setRequest( new DerivativeRequest( $context->getRequest(), $params, true ) );
		$api = new ApiMain( $context, true );
		try {
			$api->execute();
		} catch ( \MediaWiki\Api\ApiUsageException $e ) {
			if ( $e->getStatusValue()->hasMessage( 'moderation-edit-queued' ) ) {
				return [ 'result' => 'queued', 'pagename' => $title ];
			}
			throw $e;
		}
		$edit = $api->getResult()->getResultData( [ 'edit' ] );
		if ( ( $edit['result'] ?? null ) !== 'Success' ) {
			throw new \RuntimeException( 'The imported page was not saved.' );
		}
		return [ 'result' => 'success', 'pagename' => $edit['title'] ?? $title ];
	}
}
