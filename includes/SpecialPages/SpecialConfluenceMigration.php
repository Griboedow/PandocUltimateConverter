<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\PandocUltimateConverter\SpecialPages;

use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\PandocUltimateConverter\Jobs\ConfluenceMigrationJob;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;

/**
 * Special page that lets administrators migrate an entire Confluence space into
 * this MediaWiki installation.
 *
 * Access via: Special:ConfluenceMigration
 *
 * The page renders a form where the user supplies:
 *  - The Confluence base URL (cloud or server)
 *  - The space key (e.g. "DOCS")
 *  - Authentication credentials (email/username + API token/password)
 *  - An optional target page prefix
 *  - A flag controlling whether existing pages may be overwritten
 *
 * On submission the page enqueues a {@see ConfluenceMigrationJob} and shows a
 * confirmation message.  The actual migration runs asynchronously via the
 * MediaWiki job queue; the user receives an Echo notification when it completes
 * (requires the Echo extension).
 *
 * The feature can be disabled entirely by setting
 * <code>$wgPandocUltimateConverter_EnableConfluenceMigration = false;</code>
 * in LocalSettings.php.
 */
class SpecialConfluenceMigration extends \SpecialPage {

	private Config $config;

	public function __construct() {
		$mwConfig  = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'PandocUltimateConverter' );
		$userRight = $mwConfig->get( 'PandocUltimateConverter_PandocCustomUserRight' ) ?? '';

		parent::__construct( 'ConfluenceMigration', $userRight );

		$this->config = $mwConfig;
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'media';
	}

	/** @inheritDoc */
	public function execute( $par ): void {
		$this->setHeaders();
		$this->checkPermissions();

		$output = $this->getOutput();

		// Feature-flag check — administrators can disable this page entirely.
		if ( !$this->config->get( 'PandocUltimateConverter_EnableConfluenceMigration' ) ) {
			$output->addWikiTextAsInterface(
				wfMessage( 'confluencemigration-disabled' )->text()
			);
			return;
		}

		$request = RequestContext::getMain()->getRequest();

		if ( $request->wasPosted() && $request->getVal( 'wpConfluenceMigrationSubmit' ) !== null ) {
			$this->processForm( $request );
		} else {
			$this->showForm();
		}
	}

	// -----------------------------------------------------------------------
	// Form rendering
	// -----------------------------------------------------------------------

	/**
	 * Render the migration input form.
	 */
	private function showForm( array $errors = [], array $defaults = [] ): void {
		$output = $this->getOutput();
		$output->addWikiTextAsInterface( wfMessage( 'confluencemigration-desc' )->text() );

		// Display validation errors above the form, if any.
		if ( $errors !== [] ) {
			$errorHtml = Html::openElement( 'ul', [ 'class' => 'error' ] );
			foreach ( $errors as $error ) {
				$errorHtml .= Html::element( 'li', [], $error );
			}
			$errorHtml .= Html::closeElement( 'ul' );
			$output->addHTML( $errorHtml );
		}

		$formAction = $this->getPageTitle()->getLocalURL();

		$html  = Html::openElement( 'form', [
			'method'  => 'post',
			'action'  => $formAction,
			'id'      => 'mw-confluence-migration-form',
		] );

		$html .= $this->renderField(
			'wpConfluenceUrl',
			wfMessage( 'confluencemigration-url-label' )->text(),
			[
				'type'        => 'url',
				'placeholder' => 'https://example.atlassian.net',
				'required'    => 'required',
				'value'       => htmlspecialchars( $defaults['wpConfluenceUrl'] ?? '' ),
				'class'       => 'mw-input',
				'size'        => '60',
			],
			wfMessage( 'confluencemigration-url-help' )->text()
		);

		$html .= $this->renderField(
			'wpSpaceKey',
			wfMessage( 'confluencemigration-spacekey-label' )->text(),
			[
				'type'        => 'text',
				'placeholder' => 'DOCS',
				'required'    => 'required',
				'value'       => htmlspecialchars( $defaults['wpSpaceKey'] ?? '' ),
				'class'       => 'mw-input',
				'size'        => '20',
			]
		);

		$html .= $this->renderField(
			'wpApiUser',
			wfMessage( 'confluencemigration-user-label' )->text(),
			[
				'type'         => 'text',
				'autocomplete' => 'username',
				'required'     => 'required',
				'value'        => htmlspecialchars( $defaults['wpApiUser'] ?? '' ),
				'class'        => 'mw-input',
				'size'         => '40',
			]
		);

		$html .= $this->renderField(
			'wpApiToken',
			wfMessage( 'confluencemigration-token-label' )->text(),
			[
				'type'         => 'password',
				'autocomplete' => 'current-password',
				'required'     => 'required',
				'class'        => 'mw-input',
				'size'         => '40',
			]
		);

		$html .= $this->renderField(
			'wpTargetPrefix',
			wfMessage( 'confluencemigration-prefix-label' )->text(),
			[
				'type'  => 'text',
				'value' => htmlspecialchars( $defaults['wpTargetPrefix'] ?? '' ),
				'class' => 'mw-input',
				'size'  => '40',
			]
		);

		// Overwrite checkbox
		$html .= Html::openElement( 'div', [ 'class' => 'mw-input-with-label' ] );
		$html .= Html::check( 'wpOverwrite', false, [ 'id' => 'wpOverwrite' ] );
		$html .= ' ';
		$html .= Html::label(
			wfMessage( 'confluencemigration-overwrite-label' )->text(),
			'wpOverwrite'
		);
		$html .= Html::closeElement( 'div' );

		// CSRF token
		$html .= Html::hidden( 'wpEditToken', RequestContext::getMain()->getUser()->getEditToken() );

		// Submit button
		$html .= Html::openElement( 'div', [ 'class' => 'mw-submit' ] );
		$html .= Html::submitButton(
			wfMessage( 'confluencemigration-submit' )->text(),
			[ 'name' => 'wpConfluenceMigrationSubmit', 'id' => 'wpConfluenceMigrationSubmit' ]
		);
		$html .= Html::closeElement( 'div' );

		$html .= Html::closeElement( 'form' );

		$output->addHTML( $html );
	}

	/**
	 * Render a labelled input field row.
	 *
	 * @param string               $name        Input name / id.
	 * @param string               $label       Human-readable label text.
	 * @param array<string,string> $inputAttrs  Attributes for the <input> element.
	 * @param string               $helpText    Optional hint shown below the field.
	 */
	private function renderField( string $name, string $label, array $inputAttrs, string $helpText = '' ): string {
		$id    = $inputAttrs['id'] ?? $name;
		$attrs = array_merge( [ 'name' => $name, 'id' => $id ], $inputAttrs );

		$html  = Html::openElement( 'div', [ 'class' => 'mw-htmlform-field-HTMLTextField' ] );
		$html .= Html::label( $label, $id, [ 'class' => 'mw-label' ] );
		$html .= Html::openElement( 'div', [ 'class' => 'mw-input' ] );
		$html .= Html::element( 'input', $attrs );
		if ( $helpText !== '' ) {
			$html .= Html::element( 'p', [ 'class' => 'mw-input-help' ], $helpText );
		}
		$html .= Html::closeElement( 'div' );
		$html .= Html::closeElement( 'div' );

		return $html;
	}

	// -----------------------------------------------------------------------
	// Form processing
	// -----------------------------------------------------------------------

	/**
	 * Validate the submitted form and, if valid, enqueue the migration job.
	 */
	private function processForm( mixed $request ): void {
		$user = RequestContext::getMain()->getUser();

		// CSRF check
		if ( !$user->matchEditToken( $request->getVal( 'wpEditToken', '' ) ) ) {
			$this->showForm( [ wfMessage( 'sessionfailure' )->text() ] );
			return;
		}

		$confluenceUrl = trim( $request->getVal( 'wpConfluenceUrl', '' ) );
		$spaceKey      = trim( $request->getVal( 'wpSpaceKey', '' ) );
		$apiUser       = trim( $request->getVal( 'wpApiUser', '' ) );
		$apiToken      = $request->getVal( 'wpApiToken', '' );
		$targetPrefix  = trim( $request->getVal( 'wpTargetPrefix', '' ) );
		$overwrite     = $request->getBool( 'wpOverwrite', false );

		$defaults = [
			'wpConfluenceUrl' => $confluenceUrl,
			'wpSpaceKey'      => $spaceKey,
			'wpApiUser'       => $apiUser,
			'wpTargetPrefix'  => $targetPrefix,
		];

		// --- Validation ---
		$errors = [];

		if ( $confluenceUrl === '' || !$this->isValidConfluenceUrl( $confluenceUrl ) ) {
			$errors[] = wfMessage( 'confluencemigration-error-invalid-url' )->text();
		}

		if ( $spaceKey === '' ) {
			$errors[] = wfMessage( 'confluencemigration-error-empty-spacekey' )->text();
		}

		if ( $apiUser === '' || $apiToken === '' ) {
			$errors[] = wfMessage( 'confluencemigration-error-empty-credentials' )->text();
		}

		if ( $errors !== [] ) {
			$this->showForm( $errors, $defaults );
			return;
		}

		// --- Enqueue job ---
		$jobTitle = \Title::newMainPage();

		$job = new ConfluenceMigrationJob( $jobTitle, [
			'confluenceUrl' => $confluenceUrl,
			'spaceKey'      => $spaceKey,
			'apiUser'       => $apiUser,
			'apiToken'      => $apiToken,
			'targetPrefix'  => $targetPrefix,
			'overwrite'     => $overwrite,
			'userId'        => $user->getId(),
		] );

		MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );

		// --- Show confirmation ---
		$output = $this->getOutput();
		$output->addHTML(
			Html::successBox(
				wfMessage( 'confluencemigration-queued', $spaceKey )->escaped()
			)
		);
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Return true when $url is a valid https:// URL suitable for a Confluence instance.
	 */
	private function isValidConfluenceUrl( string $url ): bool {
		$scheme = strtolower( (string)parse_url( $url, PHP_URL_SCHEME ) );
		$host   = (string)parse_url( $url, PHP_URL_HOST );
		return $scheme === 'https' && $host !== '';
	}
}
