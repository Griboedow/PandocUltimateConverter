<?php
/** Run only on a disposable wiki with Echo and Moderation installed. */
require_once getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php';

use MediaWiki\Context\RequestContext;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Request\DerivativeRequest;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\Extension\PandocUltimateConverter\Jobs\ConfluenceMigrationJob;

class PandocInteroperability extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'fixture', 'DOCX fixture', true, true );
	}
	private function check( bool $ok, string $message ): void {
		if ( !$ok ) {
			throw new RuntimeException( $message );
		}
		$this->output( "PASS: $message\n" );
	}
	private function api( $user, array $params ): array {
		RequestContext::getMain()->setUser( $user );
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setUser( $user );
		$params['token'] = $user->getEditToken();
		$context->setRequest( new DerivativeRequest( $context->getRequest(), $params, true ) );
		$api = new ApiMain( $context, true );
		$api->execute();
		DeferredUpdates::doUpdates();
		clearstatcache();
		return $api->getResult()->getResultData();
	}
	private function stash( $stash ) {
		$path = sys_get_temp_dir() . '/pandoc-' . bin2hex( random_bytes( 8 ) ) . '.docx';
		copy( $this->getOption( 'fixture' ), $path );
		try {
			return $stash->stashFile( $path, 'file' );
		} finally {
			unlink( $path );
		}
	}
	public function execute() {
		$s = MediaWikiServices::getInstance();
		$db = $s->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		$run = substr( bin2hex( random_bytes( 6 ) ), 0, 12 );
		$admin = $s->getUserFactory()->newFromName( 'Admin' );
		$user = $s->getUserFactory()->newFromName( 'Importer' . $run );
		$user->addToDatabase();
		$user->saveSettings();
		$stash = $s->getRepoGroup()->getLocalRepo()->getUploadStash( $user );
		$this->check( !$s->getPermissionManager()->userHasRight( $user, 'delete' ), 'Importer has no delete right' );
		$decisions = [];
		foreach ( [ 'approve', 'reject' ] as $action ) {
			$title = 'Pandoc ' . $action . ' ' . $run;
			$file = $this->stash( $stash );
			$key = $file->getFileKey();
			$path = $file->getLocalRefPath();
			$result = $this->api( $user, [ 'action' => 'pandocconvert', 'filekey' => $key, 'pagename' => $title ] );
			$this->check( $result['pandocconvert']['result'] === 'queued', 'Reports pending moderation' );
			$this->check( !Title::newFromText( $title )->exists(), 'Not published before approval' );
			$this->check( !file_exists( $path ), 'Private DOCX removed from disk without delete right' );
			$this->check( !$db->selectField( 'uploadstash', 'us_id', [ 'us_key' => $key ], __METHOD__ ), 'Private DOCX removed from database' );
			$row = $db->selectRow( 'moderation', [ 'mod_id', 'mod_text' ], [ 'mod_title' => str_replace( ' ', '_', $title ) ], __METHOD__ );
			$this->check( $row && strpos( $row->mod_text, 'Preserved text' ) !== false && strpos( $row->mod_text, 'Alpha' ) !== false,
				'Queued document contains body and table' );
			$decisions[] = [ $action, $row->mod_id, $title ];
		}
		// Other users cannot consume or remove someone else's private source.
		$file = $this->stash( $stash );
		$key = $file->getFileKey();
		try {
			$this->api( $admin, [ 'action' => 'pandocconvert', 'filekey' => $key, 'pagename' => 'Wrong owner ' . $run ] );
			throw new RuntimeException( 'Cross-user stash access succeeded' );
		} catch ( UploadStashWrongOwnerException | \MediaWiki\Upload\Exception\UploadStashWrongOwnerException $e ) {
			$this->check( file_exists( $file->getLocalRefPath() ), 'Wrong-owner rejection preserves source' );
		}
		// A normal unmoderated import also cleans up, with a real published revision.
		$adminStash = $s->getRepoGroup()->getLocalRepo()->getUploadStash( $admin );
		$adminFile = $this->stash( $adminStash );
		$result = $this->api( $admin, [ 'action' => 'pandocconvert', 'filekey' => $adminFile->getFileKey(), 'pagename' => 'Published ' . $run ] );
		$this->check( $result['pandocconvert']['result'] === 'success' && !file_exists( $adminFile->getLocalRefPath() ), 'Unmoderated conversion publishes and cleans source' );
		// createonly prevents accidental overwrite and leaves the source available for retry.
		try {
			$this->api( $user, [ 'action' => 'pandocconvert', 'filekey' => $key, 'pagename' => 'Published ' . $run ] );
			throw new RuntimeException( 'Overwrite unexpectedly succeeded' );
		} catch ( \MediaWiki\Api\ApiUsageException $e ) {
			$this->check( file_exists( $file->getLocalRefPath() ), 'Failed save preserves private source for retry' );
		}
		$stash->removeFile( $key );
		foreach ( $decisions as [ $action, $id, $title ] ) {
			$this->api( $admin, [ 'action' => 'moderation', 'modaction' => $action, 'modid' => $id ] );
			$exists = (bool)$db->selectField( 'page', 'page_id', [ 'page_namespace' => 0,
				'page_title' => str_replace( ' ', '_', $title ) ], __METHOD__ );
			$this->check( $exists === ( $action === 'approve' ), 'Moderator ' . $action . ' works after source cleanup' );
		}
		// Invoke the job's actual completion paths, then inspect Echo's rendered API output.
		$job = new ConfluenceMigrationJob( Title::newMainPage(), [] );
		foreach ( [ [ 'notifyDone', [ $user, 'SUCCESS-' . $run, 3, [] ] ],
			[ 'notifyDone', [ $user, 'PARTIAL-' . $run, 2, [ 'failure' ] ] ],
			[ 'notifyError', [ $user, 'ERROR-' . $run, 'Synthetic error' ] ] ] as [ $method, $args ] ) {
			$m = new ReflectionMethod( $job, $method );
			$m->setAccessible( true );
			$m->invokeArgs( $job, $args );
		}
		DeferredUpdates::doUpdates();
		$notifications = $this->api( $user, [ 'action' => 'query', 'meta' => 'notifications',
			'notprop' => 'list', 'notformat' => 'model', 'notlimit' => 50 ] );
		$text = json_encode( $notifications );
		foreach ( [ 'SUCCESS-', 'PARTIAL-', 'ERROR-' ] as $prefix ) {
			$this->check( strpos( $text, $prefix . $run ) !== false, 'Echo rendered and delivered ' . $prefix . 'to job initiator' );
		}
		$this->output( "ALL INTEROPERABILITY CHECKS PASSED on " . MW_VERSION . "\n" );
	}
}
$maintClass = PandocInteroperability::class;
require_once RUN_MAINTENANCE_IF_MAIN;
