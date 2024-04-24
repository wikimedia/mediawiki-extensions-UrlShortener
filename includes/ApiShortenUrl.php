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

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiUsageException;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Status\Status;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\Stats\StatsFactory;

class ApiShortenUrl extends ApiBase {

	private bool $qrCodeEnabled;
	private int $qrCodeShortenLimit;
	private PermissionManager $permissionManager;
	private StatsFactory $statsFactory;
	private UrlShortenerUtils $utils;

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		PermissionManager $permissionManager,
		StatsFactory $statsFactory,
		UrlShortenerUtils $utils
	) {
		parent::__construct( $mainModule, $moduleName );

		$this->qrCodeEnabled = (bool)$this->getConfig()->get( 'UrlShortenerEnableQrCode' );
		$this->qrCodeShortenLimit = (int)$this->getConfig()->get( 'UrlShortenerQrCodeShortenLimit' );
		$this->permissionManager = $permissionManager;
		$this->statsFactory = $statsFactory->withComponent( 'UrlShortener' );
		$this->utils = $utils;
	}

	public function execute() {
		$this->checkUserRights();

		if ( $this->getConfig()->get( 'UrlShortenerReadOnly' ) ) {
			$this->dieWithError( 'apierror-urlshortener-disabled' );
		}

		$params = $this->extractRequestParams();

		$url = $params['url'];
		$qrCode = $this->qrCodeEnabled && $params['qrcode'];

		$validityCheck = $this->utils->validateUrl( $url );
		if ( $validityCheck !== true ) {
			$this->dieStatus( Status::newFatal( $validityCheck ) );
		}

		if ( $qrCode ) {
			$status = $this->utils->getQrCode( $url, $this->qrCodeShortenLimit, $this->getUser() );
		} else {
			$status = $this->utils->maybeCreateShortCode( $url, $this->getUser() );
		}

		if ( !$status->isOK() ) {
			$this->dieStatus( $status );
		}

		$shortUrlsOrQrCode = $status->getValue();
		$urlShortened = isset( $shortUrlsOrQrCode[ 'url' ] );

		$ret = [];

		// QR codes may not have short URLs, in which case we don't want them in the response.
		if ( $urlShortened ) {
			$ret['shorturl'] = $this->utils->makeUrl( $shortUrlsOrQrCode[ 'url' ] );
			$ret['shorturlalt'] = $this->utils->makeUrl( $shortUrlsOrQrCode[ 'alt' ] );
		}

		if ( $qrCode ) {
			$ret['qrcode'] = $shortUrlsOrQrCode['qrcode'];
		}

		$this->recordInStats( $urlShortened, $qrCode );

		// You get the cached response, YOU get the cached response, EVERYONE gets the cached response.
		$this->getMain()->setCacheMode( "public" );
		$this->getMain()->setCacheMaxAge( UrlShortenerUtils::CACHE_TTL_VALID );

		$this->getResult()->addValue( null, $this->getModuleName(), $ret );
	}

	/**
	 * Record simple usage metrics
	 *
	 * @param bool $urlShortened
	 * @param bool $qrCode
	 * @return void
	 */
	private function recordInStats( bool $urlShortened, bool $qrCode ): void {
		if ( $qrCode && $urlShortened ) {
			$type = 'shorturl_and_qrcode';
		} elseif ( $qrCode ) {
			$type = 'qrcode';
		} else {
			$type = 'shorturl';
		}

		$statsdKey = 'extension.UrlShortener.api.';
		$counter = $this->statsFactory->getCounter( 'api_hits_total' );
		$counter->setLabel( 'type', $type )->copyToStatsdAt( $statsdKey . $type );
		$counter->increment();
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
