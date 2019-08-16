<?php

/**
 * @group Database
 */
class UrlShortenerUtilsTest extends MediaWikiTestCase {

	protected function setUp() : void {
		parent::setUp();
		$this->tablesUsed[] = 'urlshortcodes';
	}

	/**
	 * @dataProvider provideConvertToProtocol
	 * @covers UrlShortenerUtils::convertToProtocol
	 */
	public function testConvertToProtocol( $input, $proto, $expected ) {
		$this->setMwGlobals( [ 'wgScript' => '/w' ] );
		$this->assertEquals( $expected, UrlShortenerUtils::convertToProtocol( $input, $proto ) );
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
	 * @covers URLShortenerUtils::normalizeUrl
	 */
	public function testNormalizeUrl( $url, $expected ) {
		$this->setMwGlobals( [
			'wgArticlePath' => '/wiki/$1',
			'wgScript' => '/w/index.php',
		] );
		$this->assertEquals( $expected, UrlShortenerUtils::normalizeUrl( $url ) );
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
	 * @covers UrlShortenerUtils::encodeId
	 * @covers UrlShortenerUtils::decodeId
	 */
	public function testEncodeAndDecodeIds() {
		// Set default
		UrlShortenerUtils::$decodeMap = null;
		$this->setMwGlobals(
			'wgUrlShortenerIdSet',
			'23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz$'
		);
		for ( $i = 0; $i < 1000; $i++ ) {
			$int = rand();
			$encoded = UrlShortenerUtils::encodeId( $int );
			$decoded = UrlShortenerUtils::decodeId( $encoded );
			$this->assertEquals( $int, $decoded );
		}
	}

	/**
	 * Test that decode performs ID mapping
	 *
	 * @covers UrlShortenerUtils::encodeId
	 * @covers UrlShortenerUtils::decodeId
	 */
	public function testDecodeIdMapping() {
		// Set default
		UrlShortenerUtils::$decodeMap = null;
		$this->setMwGlobals(
			'wgUrlShortenerIdSet',
			'23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz-'
		);
		$this->setMwGlobals(
			'wgUrlShortenerIdMapping',
			[
				'$' => '-',
				'0' => 'o'
			]
		);
		$int = 198463;
		$encoded = UrlShortenerUtils::encodeId( $int );
		$this->assertEquals( '32-o', $encoded );
		$decoded = UrlShortenerUtils::decodeId( '32$0' );
		$this->assertEquals( $int, $decoded );
		$decoded = UrlShortenerUtils::decodeId( '32-o' );
		$this->assertEquals( $int, $decoded );
	}

	/**
	 * @covers UrlShortenerUtils::getURL
	 */
	public function testGetURL() {
		$url = 'http://example.org/1';
		$status = UrlShortenerUtils::maybeCreateShortCode( $url, new User );
		$this->assertTrue( $status->isGood() );
		$id = $status->getValue();
		$storedUrl = UrlShortenerUtils::getURL( $id, PROTO_HTTP );

		$this->assertEquals( $url, $storedUrl );
	}

	/**
	 * @covers UrlShortenerUtils::maybeCreateShortCode
	 */
	public function testTooLongURL() {
		$url = 'http://example.org/1';
		$this->setMwGlobals( 'wgUrlShortenerUrlSizeLimit', 5 );

		$status = UrlShortenerUtils::maybeCreateShortCode( $url, new User );

		$this->assertFalse( $status->isGood() );
		$this->assertEquals( 'urlshortener-url-too-long', $status->getErrors()[0]['message']->getKey() );
		$this->assertEquals( 'error', $status->getErrors()[0]['type'] );
	}

	/**
	 * @covers UrlShortenerUtils::deleteURL
	 * @covers UrlShortenerUtils::isURLDeleted
	 */
	public function testDeleteURL() {
		$url = 'http://example.org/1';
		$status = UrlShortenerUtils::maybeCreateShortCode( $url, new User );
		$id = $status->getValue();

		UrlShortenerUtils::deleteURL( $id );

		$this->assertFalse( UrlShortenerUtils::getURL( $id, PROTO_HTTP ) );
		$this->assertTrue( UrlShortenerUtils::isURLDeleted( $id ) );
	}

	/**
	 * @covers UrlShortenerUtils::restoreURL
	 */
	public function testRestoreURL() {
		$url = 'http://example.org/1';
		$status = UrlShortenerUtils::maybeCreateShortCode( $url, new User );
		$id = $status->getValue();
		UrlShortenerUtils::deleteURL( $id );

		UrlShortenerUtils::restoreURL( $id );

		$storedUrl = UrlShortenerUtils::getURL( $id, PROTO_HTTP );
		$this->assertEquals( $url, $storedUrl );
	}

	/**
	 * @covers UrlShortenerUtils::maybeCreateShortCode
	 */
	public function testGetURLBlocked() {
		$url = 'http://example.org/75';
		$user = $this->getMockBuilder( User::class )
			->disableOriginalConstructor()
			->getMock();

		$user->expects( $this->atLeastOnce() )
			->method( 'isBlocked' )
			->willReturn( true );

		$status = UrlShortenerUtils::maybeCreateShortCode( $url, $user );

		$this->assertFalse( $status->isGood() );
		$this->assertEquals( 'urlshortener-blocked', $status->getErrors()[0]['message'] );
		$this->assertEquals( 'error', $status->getErrors()[0]['type'] );
	}

}
