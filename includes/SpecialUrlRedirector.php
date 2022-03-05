<?php

namespace MediaWiki\Extension\UrlShortener;

use SpecialPage;
use UnlistedSpecialPage;

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
			$out->setCdnMaxage( UrlShortenerUtils::CACHE_TTL_VALID );
			$out->redirect( $url, '301' );
		} else {
			// Invalid $par
			// This page is being served from the short domain, so we can't use
			// any of the MediaWiki interface because all relative URLs will be wrong.
			// And force English because we likely don't know the proper interface lang :(
			$title = $this->msg( 'urlshortener-not-found-title' )->inLanguage( 'en' )->text();
			$text = $this->msg( 'urlshortener-not-found-message' )->inLanguage( 'en' )->text();
			$out->setCdnMaxage( UrlShortenerUtils::CACHE_TTL_INVALID );
			// wfHttpError does escaping
			wfHttpError( 404, $title, $text );
		}
	}
}
