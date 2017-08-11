<?php
/**
 * A special page that creates redirects to arbitrary URLs
 *
 * @file
 * @ingroup Extensions
 * @author Yuvi Panda, http://yuvi.in
 * @copyright © 2014 Yuvaraj Pandian (yuvipanda@gmail.com)
 * @licence Apache 2.0
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	echo 'not a valid entry point.\n';
	die( 1 );
}

class SpecialUrlShortener extends FormSpecialPage {

	public function __construct() {
		parent::__construct( 'UrlShortener' );
	}

	public function execute( $par ) {
		global $wgUrlShortenerReadOnly;

		if ( $wgUrlShortenerReadOnly ) {
			$this->setHeaders();
			$this->getOutput()->addWikiMsg( 'urlshortener-disabled' );
		} else {
			parent::execute( $par );
		}
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
			->params( $lang->listToText( array_map( function ( $i ) {
				return "<code>$i</code>";
			}, $domains ) ) );
	}

	/**
	 * @param HTMLForm $form
	 */
	protected function alterForm( HTMLForm $form ) {
		global $wgUrlShortenerAllowArbitraryPorts;
		$form->setSubmitID( 'mw-urlshortener-submit' );
		$form->setSubmitTextMsg( 'urlshortener-url-input-submit' );
		$form->setFooterText( $this->getApprovedDomainsMessage()->parse() );
		$this->getOutput()->addModules( 'ext.urlShortener.special' );
		$this->getOutput()->addJsConfigVars( [
			'wgUrlShortenerDomainsWhitelist' => UrlShortenerUtils::getWhitelistRegex(),
			'wgUrlShortenerAllowArbitraryPorts' => $wgUrlShortenerAllowArbitraryPorts,
		] );
	}

	/**
	 * Validate the URL to ensure that we are allowed to create a shorturl for this.
	 *
	 * @param $url String The URL to validate
	 * @param $allData Array All the form fields!
	 * @return bool|string true if url is valid, error message otherwise
	 */
	public function validateURL( $url, $allData ) {
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
	 * @return array A form defintion that can be used by HTMLForm
	 */
	protected function getFormFields() {
		return [
			'url' => [
				'validation-callback' => [ $this, 'validateURL' ],
				'required' => true,
				'type' => 'url',
				'name' => 'url',
				'label-message' => 'urlshortener-form-header',
				'autofocus' => true,
				'id' => 'mw-urlshortener-url-input',
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
		$html = new OOUI\TextInputWidget( [
			'value' => UrlShortenerUtils::makeUrl( $status->getValue() ),
			'readOnly' => true,
		] );
		$out->addHTML( $html );
		return true;
	}

	protected function getGroupName() {
		return 'pagetools';
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
