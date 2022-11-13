<?php

use MediaWiki\Block\AbstractBlock;
use MediaWiki\Extension\UrlShortener\UrlShortenerUtils;

/**
 * @group Database
 * @coversDefaultClass \MediaWiki\Extension\UrlShortener\UrlShortenerUtils
 */
class UrlShortenerUtilsTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->tablesUsed[] = 'urlshortcodes';
	}

	/**
	 * @dataProvider provideCartesianProduct
	 * @covers ::cartesianProduct
	 */
	public function testCartesianProduct( $input, $expected ) {
		$this->assertEquals(
			$expected,
			UrlShortenerUtils::cartesianProduct( $input )
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
		$this->setMwGlobals( [
			'wgUrlShortenerIdMapping' => [
				'7' => 'l',
				'L' => 'l',
				'3' => 'e',
			]
		] );
		$this->assertEquals(
			$expected,
			UrlShortenerUtils::getShortcodeVariants( $input )
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
	 * @covers ::normalizeUrl
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
	 * @covers ::encodeId
	 * @covers ::decodeId
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
			// Alternative URLs
			$encoded = UrlShortenerUtils::encodeId( $int, true );
			$decoded = UrlShortenerUtils::decodeId( $encoded );
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
		$this->setMwGlobals(
			'wgUrlShortenerIdSet',
			'23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz-'
		);

		// Clear static cache
		UrlShortenerUtils::$decodeMap = null;
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

		// Clear static cache
		UrlShortenerUtils::$decodeMap = null;
		$this->setMwGlobals(
			'wgUrlShortenerIdMapping',
			[
				'0' => 'o',
				'O' => 'o',
				'I' => 'i',
				'l' => 'i',
				'1' => 'i'
			]
		);
		$this->assertEquals(
			UrlShortenerUtils::decodeId( 'o0OiIl1' ),
			UrlShortenerUtils::decodeId( 'oooiiii' )
		);
	}

	/**
	 * @covers ::getURL
	 */
	public function testGetURL() {
		$url = 'http://example.org/1';
		$status = UrlShortenerUtils::maybeCreateShortCode( $url, new User );
		$this->assertTrue( $status->isGood() );
		$id = $status->getValue()['url'];
		$storedUrl = UrlShortenerUtils::getURL( $id, PROTO_HTTP );

		$this->assertEquals( $url, $storedUrl );
	}

	/**
	 * @covers ::maybeCreateShortCode
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
	 * @covers ::deleteURL
	 * @covers ::isURLDeleted
	 */
	public function testDeleteURL() {
		$url = 'http://example.org/1';
		$status = UrlShortenerUtils::maybeCreateShortCode( $url, new User );
		$id = $status->getValue()['url'];

		UrlShortenerUtils::deleteURL( $id );

		$this->assertFalse( UrlShortenerUtils::getURL( $id, PROTO_HTTP ) );
		$this->assertTrue( UrlShortenerUtils::isURLDeleted( $id ) );
	}

	/**
	 * @covers ::restoreURL
	 */
	public function testRestoreURL() {
		$url = 'http://example.org/1';
		$status = UrlShortenerUtils::maybeCreateShortCode( $url, new User );
		$id = $status->getValue()['url'];
		UrlShortenerUtils::deleteURL( $id );

		UrlShortenerUtils::restoreURL( $id );

		$storedUrl = UrlShortenerUtils::getURL( $id, PROTO_HTTP );
		$this->assertEquals( $url, $storedUrl );
	}

	/**
	 * @covers ::maybeCreateShortCode
	 */
	public function testGetURLBlocked() {
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

		$status = UrlShortenerUtils::maybeCreateShortCode( $url, $user );

		$this->assertFalse( $status->isGood() );
		$this->assertEquals( 'urlshortener-blocked', $status->getErrors()[0]['message'] );
		$this->assertEquals( 'error', $status->getErrors()[0]['type'] );
	}

	/**
	 * @covers ::getAllowedDomainsRegex
	 */
	public function testGetAllowedDomainsRegex() {
		$this->setContentLang( 'qqx' );
		$this->setMwGlobals( [ 'wgUrlShortenerAllowedDomains' => $this->getDomains() ] );

		$allowedUrl = UrlShortenerUtils::validateUrl( 'https://en.wikipedia.org/wiki/A' );
		$disallowedUrl = UrlShortenerUtils::validateUrl( 'http://example.org/75' );
		$allowedDomainsRegex = UrlShortenerUtils::getAllowedDomainsRegex();

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
		$this->setMwGlobals( [ 'wgServer' => 'http://example.org' ] );

		$allowedUrl = UrlShortenerUtils::validateUrl( 'http://example.org/test' );
		$disallowedUrl = UrlShortenerUtils::validateUrl( 'https://en.wikipedia.org/wiki/A' );

		$this->assertTrue( $allowedUrl );
		$this->assertSame( 'example\.org', UrlShortenerUtils::getAllowedDomainsRegex() );
		$this->assertStringContainsString( 'urlshortener-error-disallowed-url', $disallowedUrl );
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
