<?php

namespace MediaWiki\Extension\UrlShortener;

use MediaWiki\MediaWikiServices;

return [
	'UrlShortener.Utils' => static function ( MediaWikiServices $services ): UrlShortenerUtils {
		return new UrlShortenerUtils(
			$services->getMainConfig(),
			$services->getDBLoadBalancerFactory(),
			$services->getUrlUtils()
		);
	},
];
