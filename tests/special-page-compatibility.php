<?php

/**
 * Check special-page permissions and constructor compatibility on a real wiki.
 *
 * Run with MW_INSTALL_PATH set to a disposable MediaWiki 1.42+ installation
 * that has this extension enabled and an administrator account named Admin:
 * php tests/special-page-compatibility.php [--admin-user Admin]
 */

require_once getenv( 'MW_INSTALL_PATH' ) . '/maintenance/Maintenance.php';

use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;

class PandocSpecialPageCompatibility extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Check Pandoc special-page compatibility and access restrictions.' );
		$this->addOption( 'admin-user', 'Existing administrator username', false, true );
	}

	private function check( bool $condition, string $message ): void {
		if ( !$condition ) {
			throw new RuntimeException( $message );
		}
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$factory = $services->getSpecialPageFactory();
		$anonymous = $services->getUserFactory()->newAnonymous();
		$admin = $services->getUserFactory()->newFromName( $this->getOption( 'admin-user', 'Admin' ) );
		$this->check( $admin !== null && $admin->isRegistered(), 'Administrator account must exist.' );
		$this->check(
			$services->getPermissionManager()->userHasRight( $admin, 'editinterface' ),
			'Administrator must have the editinterface right.'
		);

		$setting = 'wgPandocUltimateConverter_PandocCustomUserRight';
		$originalRight = $GLOBALS[$setting];
		$originalLimit = $GLOBALS['wgDeprecationReleaseLimit'];
		$GLOBALS['wgDeprecationReleaseLimit'] = false;

		// Fail on the reported constructor warning or deprecated property access,
		// without making this focused regression depend on unrelated MW warnings.
		set_error_handler( static function ( $severity, $message, $file, $line ) {
			if ( $severity === E_USER_DEPRECATED && strpos( $message, 'SpecialPage' ) !== false &&
				( strpos( $message, 'constructor parameters' ) !== false ||
					strpos( $message, 'mRestriction' ) !== false )
			) {
				throw new ErrorException( $message, 0, $severity, $file, $line );
			}
			return false;
		} );

		try {
			foreach ( [ null, '', 'editinterface' ] as $configuredRight ) {
				$GLOBALS[$setting] = $configuredRight;
				$expectedRight = $configuredRight ?? '';
				foreach ( [ 'PandocExport', 'PandocUltimateConverter', 'ConfluenceMigration' ] as $name ) {
					// MediaWiki deduplicates deprecations; each case must observe them.
					MWDebug::clearLog();
					$this->check( $factory->exists( $name ), "$name must be registered." );
					$page = $factory->getPage( $name );
					$this->check( $page !== null, "$name must instantiate." );
					$this->check( $page->getRestriction() === $expectedRight, "$name must retain the configured right." );
					$this->check( $page->isRestricted() === ( $expectedRight !== '' ), "$name restricted listing is incorrect." );

					foreach ( [ 'anonymous' => $anonymous, 'admin' => $admin ] as $label => $user ) {
						$allowed = $expectedRight === '' || $label === 'admin';
						$this->check( $page->userCanExecute( $user ) === $allowed, "$name access is incorrect for $label." );
						$context = new RequestContext();
						$context->setUser( $user );
						$page->setContext( $context );
						$denied = false;
						try {
							$page->checkPermissions();
						} catch ( \PermissionsError | \MediaWiki\Exception\PermissionsError $e ) {
							$denied = true;
						}
						$this->check( $denied === !$allowed, "$name permission enforcement is incorrect for $label." );
					}
					$this->output( 'PASS: ' . $name . ' right=' . var_export( $configuredRight, true ) . "\n" );
				}
			}
		} finally {
			restore_error_handler();
			$GLOBALS[$setting] = $originalRight;
			$GLOBALS['wgDeprecationReleaseLimit'] = $originalLimit;
		}
	}
}

$maintClass = PandocSpecialPageCompatibility::class;
require_once RUN_MAINTENANCE_IF_MAIN;
