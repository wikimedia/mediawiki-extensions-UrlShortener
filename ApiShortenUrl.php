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
		$this->getMain()->setCacheMaxAge( 30 * 24 * 60 * 60 );

		$this->getResult()->addValue( null, $this->getModuleName(),
			array( 'shorturl' => $shortUrl )
		);

	}

	public function mustBePosted() {
		return true;
	}

	protected function getAllowedParams() {
		return array(
			'url' => array(
				ApiBase::PARAM_REQUIRED => true,
			)
		);
	}

	public function getParamDescription() {
		return array(
			'url' => 'URL to be shortened'
		);
	}

	protected function getDescription() {
		return 'Shorten a long URL into a shorter one';
	}

	public function getExamples() {
		return array(
			'api.php?action=shortenurl&url=https%3A%2F%2Fen.wikipedia.org%2Fwiki%2FArctica',
		);
	}
}

