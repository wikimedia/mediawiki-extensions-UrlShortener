<?php
/**
 * Based on the ShortUrl extension from
 * https://gerrit.wikimedia.org/g/mediawiki/extensions/ShortUrl/+/e2a183f9107e56e4a6ba32655ca313dd2c7204a3/includes/
 *
 * -------
 *
 * Copyright (c) 2011 Yuvaraj Pandian (yuvipanda@yuvi.in)
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice, this
 *    list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 *
 * 3. Neither the name of the copyright holder nor the names of its
 *    contributors may be used to endorse or promote products derived from
 *    this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @file
 */

namespace MediaWiki\Extension\UrlShortener;

use MediaWiki\Exception\HttpError;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\UnlistedSpecialPage;
use MediaWiki\Title\Title;

class SpecialLegacyShortUrl extends UnlistedSpecialPage {

	public function __construct() {
		parent::__construct( 'ShortUrl' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		$out = $this->getOutput();

		$url = self::decodeURL( $par );
		if ( $url !== null ) {
			$out->redirect( $url, 301 );
		} else {
			throw new HttpError( 404, 'ShortURL Not Found' );
		}
	}

	/**
	 * These were originally encoded in the ShortUrl extension using
	 * `base_convert( $su_id, 10, 36 )` to make URLs shorter than a
	 * mere decimal number would be, as well as easier to transcribe
	 * and type on most keyboards.
	 *
	 * So su_id=4242 was encoded as `base_convert( 4242, 10, 36 )`
	 * which is "39u", which produced the URL `/s/39u`.
	 *
	 * When requested, we decode this fragment back to su_id=4242
	 * and redirect to the destination stored there.
	 *
	 * @param string|null $urlFragment
	 * @return string|null
	 */
	public static function decodeURL( $urlFragment ) {
		if ( $urlFragment === null
			|| !preg_match( '/^[0-9a-z]+$/i', $urlFragment )
		) {
			return null;
		}

		$id = intval( base_convert( $urlFragment, 36, 10 ) );

		$fname = __METHOD__;
		$services = MediaWikiServices::getInstance();
		$cache = $services->getMainWANObjectCache();
		$connectionProvider = $services->getConnectionProvider();
		$row = $cache->getWithSetCallback(
			$cache->makeKey( 'shorturls-id', $id ),
			$cache::TTL_MONTH,
			static function () use ( $id, $fname, $connectionProvider ) {
				$dbr = $connectionProvider->getReplicaDatabase();
				$row = $dbr->newSelectQueryBuilder()
					->select( [ 'su_namespace', 'su_title' ] )
					->from( 'shorturls' )
					->where( [ 'su_id' => $id ] )
					->caller( $fname )
					->fetchRow();

				return $row ? (array)$row : false;
			}
		);

		return $row ? Title::makeTitle( $row['su_namespace'], $row['su_title'] )->getFullURL() : null;
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName() {
		return 'pagetools';
	}
}
