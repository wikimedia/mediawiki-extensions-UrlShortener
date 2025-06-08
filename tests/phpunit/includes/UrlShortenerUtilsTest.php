<?php

use MediaWiki\Block\AbstractBlock;
use MediaWiki\Extension\UrlShortener\UrlShortenerUtils;
use MediaWiki\MainConfigNames;
use MediaWiki\User\User;

/**
 * @group Database
 * @coversDefaultClass \MediaWiki\Extension\UrlShortener\UrlShortenerUtils
 */
class UrlShortenerUtilsTest extends MediaWikiIntegrationTestCase {

	private function getUtils(): UrlShortenerUtils {
		return $this->getServiceContainer()->get( 'UrlShortener.Utils' );
	}

	/**
	 * @dataProvider provideCartesianProduct
	 * @covers ::cartesianProduct
	 */
	public function testCartesianProduct( $input, $expected ) {
		$utils = $this->getUtils();
		$this->assertEquals(
			$expected,
			$utils->cartesianProduct( $input )
		);
	}

	public static function provideCartesianProduct() {
		return [
			[
				[
					[ 'o', 'O', '0' ],
					[ 'b' ],
					[ 'e', 'E' ],
				],
				[
					[ 'o', 'b', 'e' ],
					[ 'o', 'b', 'E' ],
					[ 'O', 'b', 'e' ],
					[ 'O', 'b', 'E' ],
					[ '0', 'b', 'e' ],
					[ '0', 'b', 'E' ],
				]
			],
		];
	}

	/**
	 * @dataProvider provideShortcodeVariants
	 * @covers ::getShortcodeVariants
	 */
	public function testShortcodeVariants( $input, $expected ) {
		$this->overrideConfigValues( [
			'UrlShortenerIdMapping' => [
				'7' => 'l',
				'L' => 'l',
				'3' => 'e',
			]
		] );
		$utils = $this->getUtils();
		$this->assertEquals(
			$expected,
			$utils->getShortcodeVariants( $input )
		);
	}

	public static function provideShortcodeVariants() {
		return [
			[
				'leet',
				[
					'733t',
					'73et',
					'7e3t',
					'7eet',
					'L33t',
					'L3et',
					'Le3t',
					'Leet',
					'l33t',
					'l3et',
					'le3t',
					'leet',
				]
			],
		];
	}

	/**
	 * @dataProvider provideConvertToProtocol
	 * @covers ::convertToProtocol
	 */
	public function testConvertToProtocol( $input, $proto, $expected ) {
		$this->overrideConfigValue( MainConfigNames::Script, '/w' );
		$utils = $this->getUtils();
		$this->assertEquals( $expected, $utils->convertToProtocol( $input, $proto ) );
	}

	public static function provideConvertToProtocol() {
		return [
			[
				'https://example.org/foo?query=bar',
				PROTO_HTTP,
				'http://example.org/foo?query=bar'
			],
			[
				'http://example.org/foo?query=bar',
				PROTO_HTTP,
				'http://example.org/foo?query=bar'
			],
			[
				'//example.org/foo?query=bar',
				PROTO_HTTP,
				'http://example.org/foo?query=bar'
			],
			[
				'http://example.org/foo?query=bar',
				PROTO_HTTPS,
				'https://example.org/foo?query=bar'
			],
			[
				'http://example.org/foo?query=bar',
				PROTO_RELATIVE,
				'//example.org/foo?query=bar'
			],
			[
				'https://example.org/foo?query=bar',
				PROTO_RELATIVE,
				'//example.org/foo?query=bar'
			],
		];
	}

	/**
	 * @dataProvider provideNormalizeUrl
	 * @covers ::normalizeUrl
	 */
	public function testNormalizeUrl( $url, $expected ) {
		$this->overrideConfigValues( [
			MainConfigNames::ArticlePath => '/wiki/$1',
			MainConfigNames::Script => '/w/index.php',
		] );
		$utils = $this->getUtils();
		$this->assertEquals( $expected, $utils->normalizeUrl( $url ) );
	}

	public static function provideNormalizeUrl() {
		return [
			// HTTPS -> HTTP
			[
				'https://example.org/',
				'http://example.org/'
			],
			// No trailing slash -> trailing slash
			[
				'http://example.org',
				'http://example.org/'
			],
			// ? with no query string is stripped
			[
				'http://example.org/?',
				'http://example.org/'
			],
			// Article normalized
			[
				'http://example.com/w/index.php?title=Main_Page',
				'http://example.com/wiki/Main_Page'
			],
			// Already normalized
			[
				'http://example.com/wiki/Special:Version',
				'http://example.com/wiki/Special:Version'
			],
			// Special page normalized
			[
				'http://example.com/w/index.php?title=Special:Version',
				'http://example.com/wiki/Special:Version'
			],
			// API not normalized
			[
				'http://example.com/w/api.php?action=query',
				'http://example.com/w/api.php?action=query'
			],
			// Random query parameter not normalized
			[
				'http://example.com/w/index.php.php?foo=bar',
				'http://example.com/w/index.php.php?foo=bar'
			],
			// Additional parameter not normalized
			[
				'http://example.com/w/index.php?title=Special:Version&baz=bar',
				'http://example.com/w/index.php?title=Special:Version&baz=bar'
			],
			// urlencoded
			[
				'http://example.org/wiki/Scott_Morrison_%28politician%29',
				'http://example.org/wiki/Scott_Morrison_%28politician%29'
			],
			// urldecoded
			[
				'http://example.org/wiki/Scott_Morrison_(politician)',
				'http://example.org/wiki/Scott_Morrison_(politician)'
			],
			// Ideally spaces should be replaced with underscores for MediaWiki links
			[
				'http://example.org/wiki/Scott Morrison (politician)',
				'http://example.org/wiki/Scott%20Morrison%20(politician)'
			],
			// encoded # in URL that is not an anchor
			[
				'http://bots.wmflabs.org/logs/%23mediawiki/',
				'http://bots.wmflabs.org/logs/%23mediawiki/',
			],
			// encoded + in URL that is not a space
			[
				'http://en.wikipedia.org/wiki/%2B_(disambiguation)',
				'http://en.wikipedia.org/wiki/%2B_(disambiguation)'
			],
			// encoded + but not encoded / in URL
			[
				'http://en.wikipedia.org/wiki/Talk:C%2B%2B/Archive_1',
				'http://en.wikipedia.org/wiki/Talk:C%2B%2B/Archive_1',
			],
			// Bad characters sourced from
			// https://perishablepress.com/stop-using-unsafe-characters-in-urls/
			// These should ideally be escaped after the first ?
			[
				'http://example.org/?;/?:@=&"<>#%{}|\^~[]`',
				'http://example.org/?;/?:@=&"<>#%{}|\^~[]`',
			],
			// a real anchor
			[
				'http://example.org/this/is/#anchor',
				'http://example.org/this/is/#anchor',
			],
		];
	}

	/**
	 * Test that ids round-trip through encode/decode properly
	 *
	 * @covers ::encodeId
	 * @covers ::decodeId
	 */
	public function testEncodeAndDecodeIds() {
		// Set default
		UrlShortenerUtils::$decodeMap = null;
		$this->overrideConfigValue(
			'UrlShortenerIdSet',
			'23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz$'
		);
		$utils = $this->getUtils();
		for ( $i = 0; $i < 1000; $i++ ) {
			$int = rand();
			$encoded = $utils->encodeId( $int );
			$decoded = $utils->decodeId( $encoded );
			$this->assertEquals( $int, $decoded );
			// Alternative URLs
			$encoded = $utils->encodeId( $int, true );
			$decoded = $utils->decodeId( $encoded );
			$this->assertEquals( $int, $decoded );
		}
	}

	/**
	 * Test that decode performs ID mapping
	 *
	 * @covers ::encodeId
	 * @covers ::decodeId
	 */
	public function testDecodeIdMapping() {
		$this->overrideConfigValue(
			'UrlShortenerIdSet',
			'23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz-'
		);

		// Clear static cache
		UrlShortenerUtils::$decodeMap = null;
		$this->overrideConfigValue(
			'UrlShortenerIdMapping',
			[
				'$' => '-',
				'0' => 'o'
			]
		);
		$utils = $this->getUtils();
		$int = 198463;
		$encoded = $utils->encodeId( $int );
		$this->assertEquals( '32-o', $encoded );
		$decoded = $utils->decodeId( '32$0' );
		$this->assertEquals( $int, $decoded );
		$decoded = $utils->decodeId( '32-o' );
		$this->assertEquals( $int, $decoded );

		// Clear static cache
		UrlShortenerUtils::$decodeMap = null;
		$this->overrideConfigValue(
			'UrlShortenerIdMapping',
			[
				'0' => 'o',
				'O' => 'o',
				'I' => 'i',
				'l' => 'i',
				'1' => 'i'
			]
		);
		$this->assertEquals(
			$utils->decodeId( 'o0OiIl1' ),
			$utils->decodeId( 'oooiiii' )
		);
	}

	/**
	 * @covers ::getURL
	 */
	public function testGetURL() {
		$utils = $this->getUtils();
		$url = 'http://example.org/1';
		$status = $utils->maybeCreateShortCode( $url, new User );
		$this->assertTrue( $status->isGood() );
		$id = $status->getValue()['url'];
		$storedUrl = $utils->getURL( $id, PROTO_HTTP );

		$this->assertEquals( $url, $storedUrl );
	}

	/**
	 * @covers ::maybeCreateShortCode
	 */
	public function testTooLongURL() {
		$url = 'http://example.org/1';
		$this->overrideConfigValue( 'UrlShortenerUrlSizeLimit', 5 );

		$utils = $this->getUtils();
		$status = $utils->maybeCreateShortCode( $url, new User );

		$this->assertFalse( $status->isGood() );
		$this->assertEquals( 'urlshortener-url-too-long', $status->getErrors()[0]['message']->getKey() );
		$this->assertEquals( 'error', $status->getErrors()[0]['type'] );
	}

	/**
	 * @covers ::deleteURL
	 * @covers ::isURLDeleted
	 */
	public function testDeleteURL() {
		$utils = $this->getUtils();
		$url = 'http://example.org/1';
		$status = $utils->maybeCreateShortCode( $url, new User );
		$id = $status->getValue()['url'];

		$utils->deleteURL( $id );

		$this->assertFalse( $utils->getURL( $id, PROTO_HTTP ) );
		$this->assertTrue( $utils->isURLDeleted( $id ) );
	}

	/**
	 * @covers ::restoreURL
	 */
	public function testRestoreURL() {
		$utils = $this->getUtils();
		$url = 'http://example.org/1';
		$status = $utils->maybeCreateShortCode( $url, new User );
		$id = $status->getValue()['url'];
		$utils->deleteURL( $id );

		$utils->restoreURL( $id );

		$storedUrl = $utils->getURL( $id, PROTO_HTTP );
		$this->assertEquals( $url, $storedUrl );
	}

	/**
	 * @covers ::maybeCreateShortCode
	 */
	public function testGetURLBlocked() {
		$utils = $this->getUtils();
		$url = 'http://example.org/75';

		$block = $this->getMockBuilder( AbstractBlock::class )
			->disableOriginalConstructor()
			->getMock();

		$user = $this->getMockBuilder( User::class )
			->disableOriginalConstructor()
			->getMock();

		$user->expects( $this->atLeastOnce() )
			->method( 'getBlock' )
			->willReturn( $block );

		$status = $utils->maybeCreateShortCode( $url, $user );

		$this->assertFalse( $status->isGood() );
		$this->assertEquals( 'urlshortener-blocked', $status->getErrors()[0]['message'] );
		$this->assertEquals( 'error', $status->getErrors()[0]['type'] );
	}

	/**
	 * @covers ::getAllowedDomainsRegex
	 */
	public function testGetAllowedDomainsRegex() {
		$this->setContentLang( 'qqx' );
		$this->overrideConfigValue( 'UrlShortenerAllowedDomains', $this->getDomains() );

		$utils = $this->getUtils();
		$allowedUrl = $utils->validateUrl( 'https://en.wikipedia.org/wiki/A' );
		$disallowedUrl = $utils->validateUrl( 'http://example.org/75' );
		$allowedDomainsRegex = $utils->getAllowedDomainsRegex();

		$this->assertTrue( $allowedUrl );
		$this->assertStringEndsWith( '$', $allowedDomainsRegex );
		$this->assertStringContainsString( '(.*\.)?wikivoyage\.org', $allowedDomainsRegex );
		$this->assertStringContainsString( 'urlshortener-error-disallowed-url', $disallowedUrl );
	}

	/**
	 * @covers ::getAllowedDomainsRegex
	 */
	public function testGetAllowedDomainsRegex2() {
		$this->setContentLang( 'qqx' );
		$this->overrideConfigValue( MainConfigNames::Server, 'http://example.org' );

		$utils = $this->getUtils();
		$allowedUrl = $utils->validateUrl( 'http://example.org/test' );
		$disallowedUrl = $utils->validateUrl( 'https://en.wikipedia.org/wiki/A' );

		$this->assertTrue( $allowedUrl );
		$this->assertSame( 'example\.org', $utils->getAllowedDomainsRegex() );
		$this->assertStringContainsString( 'urlshortener-error-disallowed-url', $disallowedUrl );
	}

	/**
	 * @dataProvider provideShouldShortenUrl
	 * @covers ::shouldShortenUrl
	 * @param bool $qrCodeRequested Whether we're shortening within the context of creating QR codes.
	 * @param int $limit If 'https://example.org' is longer than this, it should be shortened.
	 * @param bool $expected
	 */
	public function testShouldShortenUrl( bool $qrCodeRequested, int $limit, bool $expected ): void {
		$utils = $this->getUtils();
		$this->assertEquals(
			$expected,
			$utils->shouldShortenUrl( $qrCodeRequested, 'https://example.org', $limit )
		);
	}

	/**
	 * @return Generator
	 */
	public static function provideShouldShortenUrl(): Generator {
		yield 'Short URL, not asking for QR code, should shorten' => [
			false, 500, true,
		];
		yield 'Long URL, not asking for QR code, should shorten' => [
			false, 5, true,
		];
		yield 'Short URL, are asking for QR code, should not shorten' => [
			true, 500, false,
		];
		yield 'Long URL, are asking for QR code, should shorten' => [
			true, 5, true,
		];
	}

	/**
	 * @dataProvider provideGetQrCode
	 * @covers ::getQrCode
	 * @param int $limit If 'https://example.org' is longer than this, it should be shortened.
	 * @param bool $useDataUri Whether 'qrCode' should be a data URI instead of XML.
	 * @param array $expectedKeys Key names expected to be in the array of the returned StatusValue.
	 * @param string $expectedSha Expected SHA1 hash of the QR code XML.
	 */
	public function testGetQrCode(
		int $limit, bool $useDataUri, array $expectedKeys, string $expectedSha
	): void {
		$this->overrideConfigValues( [
			MainConfigNames::Server => 'https://example.org',
			'UrlShortenerEnableQrCode' => true,
			'UrlShortenerServer' => 'https://example.org',
			'UrlShortenerTemplate' => '/r/$1'
		] );
		$utils = $this->getUtils();
		$qrCode = $utils->getQrCode(
			'https://example.org', $limit, $this->getTestUser()->getUser(), $useDataUri
		)->getValue();
		foreach ( $expectedKeys as $key ) {
			$this->assertArrayHasKey( $key, $qrCode );
		}
		$this->assertSame( $expectedSha, sha1( $qrCode['qrcode'] ) );
	}

	/**
	 * @return Generator
	 */
	public static function provideGetQrCode(): Generator {
		yield 'Should not be shortened' => [
			500, false, [ 'qrcode' ], 'a8b5e89de1245b9bf5d356ee91bf37aeec07cde3'
		];
		yield 'Should be shortened' => [
			5, false, [ 'qrcode', 'url', 'alt' ], 'aff12a867889260cee454bc4524771c795653c23'
		];
		yield 'Should not be shortened, data URI' => [
			500, true, [ 'qrcode' ], 'e71d583d7d2917fe974b79aa1f0b65614a0994a7'
		];
		yield 'Should be shortened, data URI' => [
			5, true, [ 'qrcode' ], 'd5faa876fd57ef0546d73961e58fa1652f8b68f1'
		];
	}

	private function getDomains() {
		return [
			'(.*\.)?wikipedia\.org',
			'(.*\.)?wiktionary\.org',
			'(.*\.)?wikibooks\.org',
			'(.*\.)?wikinews\.org',
			'(.*\.)?wikiquote\.org',
			'(.*\.)?wikisource\.org',
			'(.*\.)?wikiversity\.org',
			'(.*\.)?wikivoyage\.org',
			'(.*\.)?wikimedia\.org',
			'(.*\.)?wikidata\.org',
			'(.*\.)?mediawiki\.org',
		];
	}
}
