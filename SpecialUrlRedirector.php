<?php

class SpecialUrlRedirector extends UnlistedSpecialPage {

	public function __construct() {
		parent::__construct( 'UrlRedirector' );
	}

	public function execute( $par ) {
		$out = $this->getOutput();
		if ( $par === null ) {
			// Send them to the form
			$out->redirect( SpecialPage::getTitleFor( 'UrlShortener' )->getFullURL() );
			return;
		}
		$url = UrlShortenerUtils::getURL( $par, PROTO_CURRENT );
		if ( $url !== false ) {
			$out->setSquidMaxage( UrlShortenerUtils::CACHE_TIME );
			$out->redirect( $url, '301' );
		} else {
			// Invalid $par
			$out->showErrorPage( 'urlshortener-not-found-title', 'urlshortener-not-found-message' );
		}
	}
}
