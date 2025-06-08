<?php

use MediaWiki\Tests\Api\ApiTestCase;

/**
 * @group API
 * @group Database
 * @covers MediaWiki\Extension\UrlShortener\ApiShortenUrl
 */
class ApiShortenUrlTest extends ApiTestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->overrideConfigValues( [
			'UrlShortenerEnableQrCode' => true,
			'UrlShortenerAllowedDomains' => [ '(.*\.)?example\.org' ],
			'UrlShortenerApprovedDomains' => [ '*.example.org' ],
			'UrlShortenerTemplate' => false
		] );
	}

	public function testCreateShortUrl(): void {
		$apiResult = $this->doApiRequestWithToken( [
			'action' => 'shortenurl',
			'url' => 'https://example.org',
		] )[0]['shortenurl'];

		$this->assertStringContainsString( 'Special:UrlRedirector/3', $apiResult['shorturl'] );
		$this->assertStringContainsString( 'Special:UrlRedirector/_z', $apiResult['shorturlalt'] );
		$this->assertArrayNotHasKey( 'qrcode', $apiResult );
	}

	public function testCreateQrCode(): void {
		$apiResult = $this->doApiRequestWithToken( [
			'action' => 'shortenurl',
			'url' => 'https://example.org',
			'qrcode' => 1,
		] )[0]['shortenurl'];

		$this->assertStringContainsString( '<?xml version="1.0"?>', $apiResult['qrcode'] );
		$this->assertSame( 5266, strlen( $apiResult['qrcode'] ) );
		$this->assertArrayNotHasKey( 'shorturl', $apiResult );
	}
}
