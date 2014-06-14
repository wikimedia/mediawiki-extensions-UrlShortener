<?php

class SpecialUrlRedirector extends UnlistedSpecialPage {

	public function __construct() {
		parent::__construct( 'UrlRedirector' );
	}

	public function execute( $par ) {
		if ( $par === null ) {
			// Send them to the form
			$this->getOutput()->redirect( SpecialPage::getTitleFor( 'UrlShortener' )->getFullURL() );
			return;
		}
		$url = UrlShortenerUtils::getURL( $par );
		if ( $url !== false ) {
			$this->getOutput()->redirect( $url, '301' );
		} else {
			// Invalid $par
			$this->getOutput()->showErrorPage( 'urlshortener-not-found-title', 'urlshortener-not-found-message' );
		}
	}
}
