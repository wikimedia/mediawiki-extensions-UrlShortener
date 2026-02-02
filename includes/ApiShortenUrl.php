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

	private bool $shortUrlEnabled;
	private bool $qrCodeEnabled;
	private int $qrCodeShortenLimit;
	private readonly StatsFactory $statsFactory;

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		private readonly PermissionManager $permissionManager,
		StatsFactory $statsFactory,
		private readonly UrlShortenerUtils $utils,
	) {
		parent::__construct( $mainModule, $moduleName );

		$this->shortUrlEnabled = !(bool)$this->getConfig()->get( 'UrlShortenerReadOnly' );
		$this->qrCodeEnabled = (bool)$this->getConfig()->get( 'UrlShortenerEnableQrCode' );
		$this->qrCodeShortenLimit = (int)$this->getConfig()->get( 'UrlShortenerQrCodeShortenLimit' );
		$this->statsFactory = $statsFactory->withComponent( 'UrlShortener' );
	}

	public function execute() {
		$this->checkUserRights();

		$params = $this->extractRequestParams();

		$url = $params['url'];

		$validityCheck = $this->utils->validateUrl( $url );
		if ( $validityCheck !== true ) {
			$this->dieStatus( Status::newFatal( $validityCheck ) );
		}

		$ret = [];
		$qrCodeGenerated = false;
		$urlShortened = false;

		if ( !$this->shortUrlEnabled ) {
			$this->addWarning( 'apierror-urlshortener-disabled' );
		} else {
			$urlStatus = $this->utils->maybeCreateShortCode( $url, $this->getUser() );
			if ( !$urlStatus->isOK() ) {
				$this->dieStatus( $urlStatus );
			}
			$shortUrls = $urlStatus->getValue();
			$urlShortened = isset( $shortUrls[ 'url' ] );
			if ( $urlShortened ) {
				$ret['shorturl'] = $this->utils->makeUrl( $shortUrls[ 'url' ] );
				$ret['shorturlalt'] = $this->utils->makeUrl( $shortUrls[ 'alt' ] );
			}
		}

		if ( $this->qrCodeEnabled && $params['qrcode'] ) {
			$qrCodeStatus = $this->utils->getQrCode( $url, $this->qrCodeShortenLimit, $this->getUser() );
			if ( !$qrCodeStatus->isOK() ) {
				$this->dieStatus( $qrCodeStatus );
			}
			$qrCodeValue = $qrCodeStatus->getValue();
			$qrCodeGenerated = isset( $qrCodeValue['qrcode'] );
			if ( $qrCodeGenerated ) {
				$ret['qrcode'] = $qrCodeValue['qrcode'];
			}
		}

		$this->recordInStats( $urlShortened, $qrCodeGenerated );

		// You get the cached response, YOU get the cached response, EVERYONE gets the cached response.
		$this->getMain()->setCacheMode( "public" );
		$this->getMain()->setCacheMaxAge( UrlShortenerUtils::CACHE_TTL_VALID );

		$this->getResult()->addValue( null, $this->getModuleName(), $ret );
	}

	/**
	 * Record simple usage metrics
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

	/** @inheritDoc */
	public function mustBePosted() {
		return true;
	}

	/**
	 * Does writes when inserting to the urlshortcodes table.
	 *
	 * @inheritDoc
	 * @codeCoverageIgnore Merely declarative
	 */
	public function isWriteMode() {
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
