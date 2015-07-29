<?php

class UrlShortenerUtilsTest extends MediaWikiTestCase {

	/**
	 * @dataProvider provideConvertToProtocol
	 * @covers UrlShortenerUtils::convertToProtocol
	 */
	public function testConvertToProtocol( $input, $proto, $expected ) {
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
