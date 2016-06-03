<?php

class UrlShortenerUtilsTest extends MediaWikiTestCase {

	/**
	 * @dataProvider provideConvertToProtocol
	 * @covers UrlShortenerUtils::convertToProtocol
	 */
	public function testConvertToProtocol( $input, $proto, $expected ) {
		$this->setMwGlobals( array( 'wgScript' => '/w' ) );
		$this->assertEquals( $expected, UrlShortenerUtils::convertToProtocol( $input, $proto ) );
	}

	public static function provideConvertToProtocol() {
		return array(
			array( 'https://example.org/foo?query=bar', PROTO_HTTP, 'http://example.org/foo?query=bar' ),
			array( 'http://example.org/foo?query=bar', PROTO_HTTP, 'http://example.org/foo?query=bar' ),
			array( '//example.org/foo?query=bar', PROTO_HTTP, 'http://example.org/foo?query=bar' ),
			array( 'http://example.org/foo?query=bar', PROTO_HTTPS, 'https://example.org/foo?query=bar' ),
			array( 'http://example.org/foo?query=bar', PROTO_RELATIVE, '//example.org/foo?query=bar' ),
			array( 'https://example.org/foo?query=bar', PROTO_RELATIVE, '//example.org/foo?query=bar' ),
		);
	}

	/**
	 * @dataProvider provideNormalizeUrl
	 * @covers URLShortenerUtils::normalizeUrl
	 */
	public function testNormalizeUrl( $url, $expected ) {
		$this->setMwGlobals( array(
			'wgArticlePath' => '/wiki/$1',
			'wgScript' => '/w/index.php',
		) );
		$this->assertEquals( $expected, UrlShortenerUtils::normalizeUrl( $url ) );
	}

	public static function provideNormalizeUrl() {
		return array(
			// HTTPS -> HTTP
			array( 'https://example.org', 'http://example.org' ),
			// Article normalized
			array( 'http://example.com/w/index.php?title=Main_Page', 'http://example.com/wiki/Main_Page' ),
			// Already normalized
			array( 'http://example.com/wiki/Special:Version', 'http://example.com/wiki/Special:Version' ),
			// Special page normalized
			array( 'http://example.com/w/index.php?title=Special:Version', 'http://example.com/wiki/Special:Version' ),
			// API not normalized
			array( 'http://example.com/w/api.php?action=query', 'http://example.com/w/api.php?action=query' ),
			// Random query parameter not normalized
			array( 'http://example.com/w/index.php.php?foo=bar', 'http://example.com/w/index.php.php?foo=bar' ),
			// Additional parameter not normalized
			array( 'http://example.com/w/index.php?title=Special:Version&baz=bar', 'http://example.com/w/index.php?title=Special:Version&baz=bar' ),
			// urldecoded
			array( 'http://example.org/wiki/Scott_Morrison_%28politician%29', 'http://example.org/wiki/Scott_Morrison_(politician)' ),
			array( 'http://example.org/wiki/Scott_Morrison_(politician)', 'http://example.org/wiki/Scott_Morrison_(politician)' ),
		);
	}

	/**
	 * Test that ids round-trip through encode/decode properly
	 *
	 * @covers UrlShortenerUtils::encodeId
	 * @covers UrlShortenerUtils::decodeId
	 */
	public function testEncodeAndDecodeIds() {
		// Set default
		$this->setMwGlobals(
			'wgUrlShortenerIdSet',
			'023456789ABCDEFGHJKLMNOPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz$-_.!'
		);
		for ( $i = 0; $i < 1000; $i++ ) {
			$int = rand();
			$encoded = UrlShortenerUtils::encodeId( $int );
			$decoded = UrlShortenerUtils::decodeId( $encoded );
			$this->assertEquals( $int, $decoded );
		}
	}
}
