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
use ApiMain;
use ApiUsageException;
use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Status\Status;
use Wikimedia\ParamValidator\ParamValidator;

class ApiShortenUrl extends ApiBase {

	private bool $qrCodeEnabled;
	private int $qrCodeShortenLimit;
	private PermissionManager $permissionManager;
	private StatsdDataFactoryInterface $statsdDataFactory;

	/**
	 * @inheritDoc
	 */
	public function __construct(
		ApiMain $mainModule,
		$moduleName,
		PermissionManager $permissionManager,
		StatsdDataFactoryInterface $statsdDataFactory
	) {
		parent::__construct( $mainModule, $moduleName );

		$this->qrCodeEnabled = (bool)$this->getConfig()->get( 'UrlShortenerEnableQrCode' );
		$this->qrCodeShortenLimit = (int)$this->getConfig()->get( 'UrlShortenerQrCodeShortenLimit' );
		$this->permissionManager = $permissionManager;
		$this->statsdDataFactory = $statsdDataFactory;
	}

	public function execute() {
		$this->checkUserRights();

		if ( $this->getConfig()->get( 'UrlShortenerReadOnly' ) ) {
			$this->dieWithError( 'apierror-urlshortener-disabled' );
		}

		$params = $this->extractRequestParams();

		$url = $params['url'];
		$qrCode = $this->qrCodeEnabled && $params['qrcode'];

		$validityCheck = UrlShortenerUtils::validateUrl( $url );
		if ( $validityCheck !== true ) {
			$this->dieStatus( Status::newFatal( $validityCheck ) );
		}

		if ( $qrCode ) {
			$status = UrlShortenerUtils::getQrCode( $url, $this->qrCodeShortenLimit, $this->getUser() );
		} else {
			$status = UrlShortenerUtils::maybeCreateShortCode( $url, $this->getUser() );
		}

		if ( !$status->isOK() ) {
			$this->dieStatus( $status );
		}

		$shortUrlsOrQrCode = $status->getValue();
		$urlShortened = isset( $shortUrlsOrQrCode[ 'url' ] );

		$ret = [];

		// QR codes may not have short URLs, in which case we don't want them in the response.
		if ( $urlShortened ) {
			$ret['shorturl'] = UrlShortenerUtils::makeUrl( $shortUrlsOrQrCode[ 'url' ] );
			$ret['shorturlalt'] = UrlShortenerUtils::makeUrl( $shortUrlsOrQrCode[ 'alt' ] );
		}

		if ( $qrCode ) {
			$ret['qrcode'] = $shortUrlsOrQrCode['qrcode'];
		}

		$this->recordInStatsD( $urlShortened, $qrCode );

		// You get the cached response, YOU get the cached response, EVERYONE gets the cached response.
		$this->getMain()->setCacheMode( "public" );
		$this->getMain()->setCacheMaxAge( UrlShortenerUtils::CACHE_TTL_VALID );

		$this->getResult()->addValue( null, $this->getModuleName(), $ret );
	}

	/**
	 * Record simple usage counts in statsd.
	 *
	 * @param bool $urlShortened
	 * @param bool $qrCode
	 * @return void
	 */
	private function recordInStatsD( bool $urlShortened, bool $qrCode ): void {
		if ( $qrCode && $urlShortened ) {
			$this->statsdDataFactory->increment( 'extension.UrlShortener.api.shorturl_and_qrcode' );
		} elseif ( $qrCode ) {
			$this->statsdDataFactory->increment( 'extension.UrlShortener.api.qrcode' );
		} else {
			$this->statsdDataFactory->increment( 'extension.UrlShortener.api.shorturl' );
		}
	}

	/**
	 * Check that the user can create a short url
	 * @throws ApiUsageException if the user lacks the rights
	 */
	public function checkUserRights() {
		if ( !$this->permissionManager->userHasRight( $this->getUser(), 'urlshortener-create-url' ) ) {
			$this->dieWithError( [ 'apierror-permissiondenied',
				$this->msg( "apierror-urlshortener-permissiondenied" ) ]
			);
		}
	}

	public function mustBePosted() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function getAllowedParams() {
		$params = [
			'url' => [
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
		if ( $this->qrCodeEnabled ) {
			$params['qrcode'] = [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
			];
		}
		return $params;
	}

	/**
	 * @inheritDoc
	 */
	public function getExamplesMessages() {
		return [
			'action=shortenurl&url=https%3A%2F%2Fen.wikipedia.org%2Fwiki%2FArctica'
				=> 'apihelp-shortenurl-example-1',
			'action=shortenurl&url=https%3A%2F%2Fen.wikipedia.org%2Fwiki%2FArctica&qrcode=1'
				=> 'apihelp-shortenurl-example-2',
		];
	}
}
