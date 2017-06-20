<?php

/**
 * Class ApiShortenUrl
 *
 * Implements action=shortenurl to provide url shortening services via the API.
 *
 * Even though this is a write action sometimes, we still use GET so we can be
 * cached at varnish levels very easily.
 */
class ApiShortenUrl extends ApiBase {

	public function execute() {
		global $wgUrlShortenerReadOnly;

		if ( $wgUrlShortenerReadOnly ) {
			if ( is_callable( [ $this, 'dieWithError' ] ) ) {
				$this->dieWithError( 'apierror-urlshortener-disabled' );
			} else {
				$this->dieUsage( 'No new short urls may be created', 'urlshortener-disabled' );
			}
		}

		$params = $this->extractRequestParams();

		$url = $params['url'];

		$validity_check = UrlShortenerUtils::validateUrl( $url );
		if ( $validity_check !== true ) {
			$this->dieStatus( Status::newFatal( $validity_check ) );
		}
		$status = UrlShortenerUtils::maybeCreateShortCode( $url, $this->getUser() );
		if ( !$status->isOK() ) {
			$this->dieStatus( $status );
		}
		$shortUrl = UrlShortenerUtils::makeUrl( $status->getValue() );

		// You get the cached response, YOU get the cached response, EVERYONE gets the cached response.
		$this->getMain()->setCacheMode( "public" );
		$this->getMain()->setCacheMaxAge( UrlShortenerUtils::CACHE_TIME );

		$this->getResult()->addValue( null, $this->getModuleName(),
			[ 'shorturl' => $shortUrl ]
		);
	}

	public function mustBePosted() {
		return true;
	}

	protected function getAllowedParams() {
		return [
			'url' => [
				ApiBase::PARAM_REQUIRED => true,
			]
		];
	}

	public function getExamplesMessages() {
		return [
			'action=shortenurl&url=https%3A%2F%2Fen.wikipedia.org%2Fwiki%2FArctica'
				=> 'apihelp-shortenurl-example-1',
		];
	}
}
