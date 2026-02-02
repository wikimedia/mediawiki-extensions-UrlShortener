<?php
/**
 * A special page that creates QR codes from allowed URLs
 *
 * @file
 * @ingroup Extensions
 * @license Apache-2.0
 */

namespace MediaWiki\Extension\UrlShortener;

use MediaWiki\Config\ConfigFactory;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Utils\UrlUtils;

class SpecialQrCode extends SpecialUrlShortener {

	public function __construct(
		UrlShortenerUtils $utils,
		UrlUtils $urlUtils,
		ConfigFactory $configFactory
	) {
		// Special:QrCode is now an alias of Special:UrlShortener.
		parent::__construct( $utils, $urlUtils, 'QrCode' );
	}

	/**
	 * @param string|null $par
	 */
	public function execute( $par ) {
		$url = $this->getRequest()->getText( 'url', $par ?? '' );
		$params = [];
		if ( $url !== '' ) {
			$params['url'] = $url;
		}
		$target = SpecialPage::getTitleFor( 'UrlShortener' )->getLocalURL( $params );
		$this->getOutput()->redirect( $target );
	}

	/** @inheritDoc */
	public function isListed() {
		return false;
	}
}
