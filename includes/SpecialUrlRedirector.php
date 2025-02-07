<?php

namespace MediaWiki\Extension\UrlShortener;

use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\SpecialPage\UnlistedSpecialPage;

class SpecialUrlRedirector extends UnlistedSpecialPage {

	private UrlShortenerUtils $utils;

	public function __construct(
		UrlShortenerUtils $utils
	) {
		parent::__construct( 'UrlRedirector' );
		$this->utils = $utils;
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		$out = $this->getOutput();
		if ( $par === null ) {
			// Send them to the form
			$out->redirect( SpecialPage::getTitleFor( 'UrlShortener' )->getFullURL() );
			return;
		}

		// Redirect destinations are public information
		// Allow redirects to be resolved across domains (T358049).
		$this->getRequest()->response()->header( 'Access-Control-Allow-Origin: *' );

		$url = $this->utils->getURL( $par, PROTO_CURRENT );
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
