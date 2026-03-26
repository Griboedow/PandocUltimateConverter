<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\Api;

use ApiBase;
use MediaWiki\MediaWikiServices;

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

		$jobs = self::fetchPendingJobs();

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'jobs' => $jobs,
		] );
	}

	/**
	 * Query the MediaWiki job table for all pending confluenceMigration jobs.
	 *
	 * @return array[]
	 */
	public static function fetchPendingJobs(): array {
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
			$params = ( (string)$row->job_params !== '' )
				? unserialize( $row->job_params )
				: [];

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

	/** @inheritDoc */
	public function isReadMode(): bool {
		return true;
	}
}
