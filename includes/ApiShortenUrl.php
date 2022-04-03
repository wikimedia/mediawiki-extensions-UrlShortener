<?php

/**
 * Class ApiShortenUrl
 *
 * Implements action=shortenurl to provide url shortening services via the API.
 *
 * Even though this is a write action sometimes, we still use GET so we can be
 * cached at varnish levels very easily.
 */

namespace MediaWiki\Extension\UrlShortener;

use ApiBase;
use ApiUsageException;
use MediaWiki\MediaWikiServices;
use Status;
use Wikimedia\ParamValidator\ParamValidator;

class ApiShortenUrl extends ApiBase {

	public function execute() {
		global $wgUrlShortenerReadOnly;

		$this->checkUserRights();

		if ( $wgUrlShortenerReadOnly ) {
			$this->dieWithError( 'apierror-urlshortener-disabled' );
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
		$shortUrls = $status->getValue();

		// You get the cached response, YOU get the cached response, EVERYONE gets the cached response.
		$this->getMain()->setCacheMode( "public" );
		$this->getMain()->setCacheMaxAge( UrlShortenerUtils::CACHE_TTL_VALID );

		$this->getResult()->addValue( null, $this->getModuleName(),
			[
				'shorturl' => UrlShortenerUtils::makeUrl( $shortUrls[ 'url' ] ),
				'shorturlalt' => UrlShortenerUtils::makeUrl( $shortUrls[ 'alt' ] )
			]
		);
	}

	/**
	 * Check that the user can create a short url
	 * @throws ApiUsageException if the user lacks the rights
	 */
	public function checkUserRights() {
		$permManager = MediaWikiServices::getInstance()->getPermissionManager();

		if ( !$permManager->userHasRight( $this->getUser(), 'urlshortener-create-url' ) ) {
			$this->dieWithError( [ 'apierror-permissiondenied',
				$this->msg( "apierror-urlshortener-permissiondenied" ) ]
			);
		}
	}

	public function mustBePosted() {
		return true;
	}

	protected function getAllowedParams() {
		return [
			'url' => [
				ParamValidator::PARAM_REQUIRED => true,
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
