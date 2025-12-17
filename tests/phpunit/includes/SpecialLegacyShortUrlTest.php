<?php

use MediaWiki\Extension\UrlShortener\SpecialLegacyShortUrl;
use MediaWiki\MainConfigNames;

/**
 * @group Database
 * @covers \MediaWiki\Extension\UrlShortener\SpecialLegacyShortUrl
 */
class SpecialLegacyShortUrlTest extends MediaWikiIntegrationTestCase {

	/**
	 * @see MediaWikiIntegrationTestCase::addDBDataOnce()
	 */
	public function addDBDataOnce() {
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'shorturls' )
			->rows( [
				[
					'su_id' => 1,
					'su_namespace' => 0,
					'su_title' => 'Sandbox',
				],
				[
					'su_id' => 21,
					'su_namespace' => 6,
					'su_title' => 'Example.png',
				],
				[
					'su_id' => 349,
					'su_namespace' => 0,
					'su_title' => 'Main_Page',
				],
				[
					'su_id' => 4242,
					'su_namespace' => 1,
					'su_title' => 'Main_Page',
				]
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @dataProvider provideDecodeURL
	 */
	public function testDecodeURL( $id, $expected ) {
		$this->overrideConfigValues( [
			MainConfigNames::Server => 'https://example.org',
			MainConfigNames::ArticlePath => '/wiki/$1',
			MainConfigNames::Script => '/w/index.php',
		] );
		$this->assertSame( $expected, SpecialLegacyShortUrl::decodeURL( $id ) );
	}

	public static function provideDecodeURL() {
		return [
			[ '1', 'https://example.org/wiki/Sandbox' ],
			[ 'k', null ],
			[ 'l', 'https://example.org/wiki/File:Example.png' ],
			[ '9p', 'https://example.org/wiki/Main_Page' ],
			[ '39u', 'https://example.org/wiki/Talk:Main_Page' ],
		];
	}
}
