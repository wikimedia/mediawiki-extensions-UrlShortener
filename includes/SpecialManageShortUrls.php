<?php
/**
 * A special page that manages short urls, delete and restore is possible
 *
 * @file
 * @ingroup Extensions
 * @author Ladsgroup
 * @copyright © 2019 Amir Sarabadani
 * @license Apache-2.0
 */

namespace MediaWiki\Extension\UrlShortener;

use FormSpecialPage;
use ManualLogEntry;
use User;

class SpecialManageShortUrls extends FormSpecialPage {

	public function __construct() {
		parent::__construct( 'ManageShortUrls', 'urlshortener-manage-url' );
	}

	public function execute( $par ) {
		global $wgUrlShortenerReadOnly;
		$this->addHelpLink( 'Help:UrlShortener' );

		if ( $wgUrlShortenerReadOnly ) {
			$this->setHeaders();
			$this->getOutput()->addWikiMsg( 'urlshortener-disabled' );
		} else {
			$this->getOutput()->addWikiMsg( 'urlshortener-manage-text' );
			parent::execute( $par );
		}
	}

	protected function getDisplayFormat() {
		return 'ooui';
	}

	/**
	 * @return array[]
	 */
	protected function getFormFields() {
		return [
			'shortCodeDelete' => [
				'type' => 'text',
				'default' => '',
				'label-message' => 'urlshortener-enter-short-code-delete',
				'name' => 'shortCodeDelete'
			],
			'shortCodeRestore' => [
				'type' => 'text',
				'default' => '',
				'label-message' => 'urlshortener-enter-short-code-restore',
				'name' => 'shortCodeRestore'
			],
			'reason' => [
				'type' => 'text',
				'default' => '',
				'label-message' => 'urlshortener-manage-reason',
				'name' => 'reason'
			],
		];
	}

	/**
	 * Don't list this page if in read only mode
	 *
	 * @return bool
	 */
	public function isListed() {
		global $wgUrlShortenerReadOnly;

		return !$wgUrlShortenerReadOnly;
	}

	/**
	 * @param array $formData
	 *
	 * @return array|bool
	 */
	public function onSubmit( array $formData ) {
		$errors = [];
		$success = false;
		$delete = $formData['shortCodeDelete'];
		$restore = $formData['shortCodeRestore'];
		$reason = $formData['reason'];

		if ( !$delete && !$restore ) {
			return [ 'urlshortener-manage-not-enough-data' ];
		}

		if ( $delete ) {
			$url = UrlShortenerUtils::getURL( $delete );
			if ( $url === false ) {
				$errors[] = [ 'urlshortener-short-code-not-found' ];
			}
			$success = UrlShortenerUtils::deleteURL( $delete );
			if ( !$success ) {
				$errors[] = [ 'urlshortener-manage-delete-failed' ];
			}
		}

		if ( $restore ) {
			$deleted = UrlShortenerUtils::isURLDeleted( $restore );
			if ( !$deleted ) {
				$errors[] = [ 'urlshortener-short-code-is-not-deleted' ];
			}
			$success = UrlShortenerUtils::restoreURL( $restore );
			if ( !$success ) {
				$errors[] = [ 'urlshortener-manage-restore-failed' ];
			}
		}

		if ( $errors === [] ) {
			$subtype = $delete ? 'delete' : 'restore';
			$target = $delete ?: $restore;

			// Log the action
			$logEntry = new ManualLogEntry( 'urlshortener', $subtype );
			$logEntry->setPerformer( $this->getUser() );
			// Set some dummy title. It is required to be set, otherwise we're not using it.
			$logEntry->setTarget( $this->getTitleFor( 'UrlShortener' ) );
			$logEntry->setComment( $reason );
			$logEntry->setParameters(
				[ '4::realtarget' => $target ]
			);
			$logId = $logEntry->insert();
			$logEntry->publish( $logId );

			return $success;
		}

		return $errors;
	}

	/**
	 * Require users to be logged in and have the rights
	 *
	 * @param User $user
	 */
	protected function checkExecutePermissions( User $user ) {
		parent::checkExecutePermissions( $user );
		$this->requireLogin();
	}

	public function doesWrites() {
		return true;
	}

	protected function getGroupName() {
		return 'pagetools';
	}

	public function onSuccess() {
		$this->getOutput()->addWikiMsg( 'urlshortener-action-done' );
		$this->getOutput()->returnToMain();
	}
}
