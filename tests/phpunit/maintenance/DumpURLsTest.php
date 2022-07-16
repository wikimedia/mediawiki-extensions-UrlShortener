<?php

use MediaWiki\Extension\UrlShortener\UrlShortenerUtils;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;

/**
 * @covers DumpURLs
 * @group Database
 */
class DumpURLsTest extends MaintenanceBaseTestCase {
	protected $tmp;

	protected function getMaintenanceClass() {
		return DumpURLs::class;
	}

	public function setUp(): void {
		parent::setUp();
		$this->tmp = tempnam( wfTempDir(), __CLASS__ );
	}

	public function tearDown(): void {
		unlink( $this->tmp );
		parent::tearDown();
	}

	public function testExecute() {
		// Populate the database
		for ( $i = 0; $i < 10; $i++ ) {
			$url = 'http://example.org/' . $i;
			$status = UrlShortenerUtils::maybeCreateShortCode( $url, new User );
			$this->assertTrue( $status->isGood() );
		}

		$this->maintenance->loadWithArgv( [ $this->tmp ] );
		// Set batchsize smaller than total results
		// so we test batching
		$this->maintenance->setBatchSize( 3 );
		$this->maintenance->execute();
		$expected = <<<EXPECTED
3|http://example.org/0
4|http://example.org/1
5|http://example.org/2
6|http://example.org/3
7|http://example.org/4
8|http://example.org/5
9|http://example.org/6
A|http://example.org/7
B|http://example.org/8
C|http://example.org/9

EXPECTED;
		$this->assertEquals(
			file_get_contents( $this->tmp ),
			$expected
		);
	}
}
