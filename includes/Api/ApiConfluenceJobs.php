<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Api;

use ApiBase;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;

/**
 * Action API module: action=pandocconfluencejobs
 *
 * Returns pending/in-progress Confluence migration jobs from the MediaWiki job
 * queue for the current user.  Once a job finishes it is removed from the queue,
 * so only queued or running jobs are shown.
 */
class ApiConfluenceJobs extends ApiBase {

	/** @inheritDoc */
	public function execute(): void {
		$this->checkUserRightsAny( 'read' );

		$userId = $this->getUser()->getId();
		if ( $userId === 0 ) {
			$this->dieWithError( 'apierror-mustbeloggedin-generic', 'notloggedin' );
		}

		$jobs    = self::fetchPendingJobs( $userId );
		$reports = self::fetchReportPages();

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'jobs'    => $jobs,
			'reports' => $reports,
		] );
	}

	/**
	 * Query the MediaWiki job table for all pending confluenceMigration jobs.
	 *
	 * @return array[]
	 */
	public static function fetchPendingJobs( ?int $userId = null ): array {
		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getConnection( DB_REPLICA );

		$res = $dbr->newSelectQueryBuilder()
			->select( [
				'job_id',
				'job_params',
				'job_timestamp',
				'job_attempts',
				'job_token',
				'job_token_timestamp',
			] )
			->from( 'job' )
			->where( [ 'job_cmd' => 'confluenceMigration' ] )
			->orderBy( 'job_id', 'DESC' )
			->limit( 50 )
			->caller( __METHOD__ )
			->fetchResultSet();

		$jobs = [];
		foreach ( $res as $row ) {
			$params = [];
			if ( (string)$row->job_params !== '' ) {
				$decoded = unserialize( $row->job_params, [ 'allowed_classes' => false ] );
				if ( is_array( $decoded ) ) {
					$params = $decoded;
				}
			}
			if ( !self::belongsToUser( $params, $userId ) ) {
				continue;
			}

			// A non-empty job_token means the job runner has claimed it (running).
			$isRunning = ( (string)$row->job_token !== '' );

			$jobs[] = [
				'id'           => (int)$row->job_id,
				'spaceKey'     => $params['spaceKey'] ?? '',
				'confluenceUrl' => $params['confluenceUrl'] ?? '',
				'targetPrefix' => $params['targetPrefix'] ?? '',
				'overwrite'    => !empty( $params['overwrite'] ),
				'status'       => $isRunning ? 'running' : 'queued',
				'attempts'     => (int)$row->job_attempts,
				'queuedAt'     => $row->job_timestamp
					? wfTimestamp( TS_ISO_8601, $row->job_timestamp )
					: null,
				'claimedAt'    => $row->job_token_timestamp
					? wfTimestamp( TS_ISO_8601, $row->job_token_timestamp )
					: null,
			];
		}

		return $jobs;
	}

	/**
	 * Whether a queued job should be visible for the requested user.
	 *
	 * @param array $params Decoded job params.
	 * @param int|null $userId Current user id filter; null means no filtering.
	 * @return bool
	 */
	private static function belongsToUser( array $params, ?int $userId ): bool {
		if ( $userId === null ) {
			return true;
		}
		if ( !isset( $params['userId'] ) ) {
			// Legacy rows without initiator metadata are hidden from user-scoped lists.
			return false;
		}
		return (int)$params['userId'] === $userId;
	}

	/**
	 * Fetch wiki pages whose title starts with "Migration from Confluence - ".
	 *
	 * @return array[] Each element has 'title', 'pageId', and 'timestamp'.
	 */
	public static function fetchReportPages(): array {
		$prefix = 'Migration from Confluence - ';
		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getConnection( DB_REPLICA );

		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'page_id', 'page_title', 'page_touched' ] )
			->from( 'page' )
			->where( [
				'page_namespace' => NS_MAIN,
				$dbr->expr( 'page_title', IExpression::LIKE,
					new LikeValue( str_replace( ' ', '_', $prefix ), $dbr->anyString() )
				),
			] )
			->orderBy( 'page_id', 'DESC' )
			->limit( 50 )
			->caller( __METHOD__ )
			->fetchResultSet();

		$reports = [];
		foreach ( $res as $row ) {
			$title = str_replace( '_', ' ', $row->page_title );
			// Extract the datetime portion from the title.
			$datetime = substr( $title, strlen( $prefix ) );
			$reports[] = [
				'pageId'    => (int)$row->page_id,
				'title'     => $title,
				'datetime'  => $datetime,
				'url'       => \Title::newFromText( $title )->getLocalURL(),
				'touched'   => $row->page_touched
					? wfTimestamp( TS_ISO_8601, $row->page_touched )
					: null,
			];
		}

		return $reports;
	}

	/** @inheritDoc */
	public function isReadMode(): bool {
		return true;
	}
}
