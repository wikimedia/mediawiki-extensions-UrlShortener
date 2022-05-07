<?php
/**
 * A special page that creates redirects to arbitrary URLs
 *
 * @file
 * @ingroup Extensions
 * @author Yuvi Panda, http://yuvi.in
 * @copyright © 2014 Yuvaraj Pandian (yuvipanda@gmail.com)
 * @license Apache-2.0
 */

namespace MediaWiki\Extension\UrlShortener;

use FormSpecialPage;
use HTMLForm;
use OOUI\FieldLayout;
use OOUI\HtmlSnippet;
use OOUI\Tag;
use OOUI\TextInputWidget;
use PermissionsError;
use Status;

class SpecialUrlShortener extends FormSpecialPage {

	protected $resultField;

	public function __construct() {
		parent::__construct( 'UrlShortener', 'urlshortener-create-url' );
	}

	public function execute( $par ) {
		global $wgUrlShortenerReadOnly;
		$this->addHelpLink( 'Help:UrlShortener' );

		$this->checkPermissions();

		if ( $wgUrlShortenerReadOnly ) {
			$this->setHeaders();
			$this->getOutput()->addWikiMsg( 'urlshortener-disabled' );
		} else {
			parent::execute( $par );

			if ( $this->resultField ) {
				// Always show form, as in JS mode.
				// Also show the results below the form.
				$form = $this->getForm();
				$form->showAlways();
				$this->getOutput()->addHTML( $this->resultField );
			}
		}
	}

	/**
	 * @inheritDoc
	 */
	public function displayRestrictionError() {
		throw new PermissionsError( 'urlshortener-create-url', [ 'urlshortener-badaccessgroups' ] );
	}

	protected function getDisplayFormat() {
		return 'ooui';
	}

	private function getApprovedDomainsMessage() {
		global $wgUrlShortenerApprovedDomains, $wgServer;
		if ( $wgUrlShortenerApprovedDomains ) {
			$domains = $wgUrlShortenerApprovedDomains;
		} else {
			$parsed = wfParseUrl( $wgServer );
			$domains = [ $parsed['host'] ];
		}

		$lang = $this->getLanguage();
		return $this->msg( 'urlshortener-approved-domains' )
			->numParams( count( $domains ) )
			->params( $lang->listToText( array_map( static function ( $i ) {
				return "<code>$i</code>";
			}, $domains ) ) );
	}

	/**
	 * @param HTMLForm $form
	 */
	protected function alterForm( HTMLForm $form ) {
		global $wgUrlShortenerAllowArbitraryPorts;
		$form->suppressDefaultSubmit();
		$this->getOutput()->addModules( 'ext.urlShortener.special' );
		$this->getOutput()->addJsConfigVars( [
			'wgUrlShortenerAllowedDomains' => UrlShortenerUtils::getAllowedDomainsRegex(),
			'wgUrlShortenerAllowArbitraryPorts' => $wgUrlShortenerAllowArbitraryPorts,
		] );
	}

	/**
	 * Validate the URL to ensure that we are allowed to create a shorturl for this.
	 *
	 * @param string|null $url The URL to validate
	 * @param array $allData All the form fields
	 * @return bool|string true if url is valid, error message otherwise
	 */
	public function validateURL( ?string $url, array $allData ) {
		// $url is null when the form hasn't been posted
		if ( $url === null ) {
			// No input
			return true;
		}
		$validity_check = UrlShortenerUtils::validateUrl( $url );
		if ( $validity_check === true ) {
			return true;
		}
		return $validity_check->text();
	}

	/**
	 * Generate the form used to input the URL to shorten.
	 * @return array A form definition that can be used by HTMLForm
	 */
	protected function getFormFields() {
		return [
			'url' => [
				'validation-callback' => [ $this, 'validateURL' ],
				'required' => true,
				'type' => 'textwithbutton',
				'inputtype' => 'url',
				'buttontype' => 'submit',
				'buttondefault' => $this->msg( 'urlshortener-url-input-submit' )->text(),
				'buttonflags' => [ 'primary', 'progressive' ],
				'buttonid' => 'mw-urlshortener-submit',
				'name' => 'url',
				'label-message' => 'urlshortener-form-header',
				'autofocus' => true,
				'id' => 'mw-urlshortener-url-input',
				'help' => $this->getApprovedDomainsMessage()->parse(),
				'placeholder' => $this->msg( 'urlshortener-url-input-label' )->text()
			],
		];
	}

	/**
	 * Process the form on POST submission.
	 *
	 * Creates the short URL and displays it back to the user.
	 *
	 * @param array $data
	 * @return bool|Status True for success, false for didn't-try, Status (with errors) on failure
	 */
	public function onSubmit( array $data ) {
		$out = $this->getOutput();
		$out->enableOOUI();
		if ( $data['url'] === null ) {
			return false;
		}
		$status = UrlShortenerUtils::maybeCreateShortCode( $data['url'], $this->getUser() );
		if ( !$status->isOK() ) {
			return $status;
		}
		$result = $status->getValue();
		$altUrl = UrlShortenerUtils::makeUrl( $result[ 'alt' ] );
		$altLink = new Tag( 'a' );
		$altLink->setAttributes( [ 'href' => $altUrl ] );
		$altLink->appendContent( $altUrl );
		$this->resultField = new FieldLayout(
			new TextInputWidget( [
				'value' => UrlShortenerUtils::makeUrl( $result[ 'url' ] ),
				'readOnly' => true,
			] ),
			[
				'align' => 'top',
				'classes' => [ 'ext-urlshortener-result' ],
				'label' => $this->msg( 'urlshortener-shortened-url-label' )->text(),
				'help' => new HtmlSnippet(
					$this->msg( 'urlshortener-shortened-url-alt' )->escaped() . ' ' . $altLink
				),
				'helpInline' => true,
			]
		);
		return true;
	}

	protected function getGroupName() {
		return 'pagetools';
	}

	public function doesWrites() {
		return true;
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
}
