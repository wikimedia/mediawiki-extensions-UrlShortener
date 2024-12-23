<?php

use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use MediaWiki\User\User;

/**
 * @covers DumpURLs
 * @group Database
 */
class DumpURLsTest extends MaintenanceBaseTestCase {

	protected function getMaintenanceClass() {
		return DumpURLs::class;
	}

	public function testExecute() {
		$utils = $this->getServiceContainer()->get( 'UrlShortener.Utils' );
		// Populate the database
		for ( $i = 0; $i < 10; $i++ ) {
			$url = 'http://example.org/' . $i;
			$status = $utils->maybeCreateShortCode( $url, new User );
			$this->assertTrue( $status->isGood() );
		}

		$tmpFile = $this->getNewTempFile();
		$this->maintenance->loadWithArgv( [ $tmpFile ] );
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
			$expected,
			file_get_contents( $tmpFile )
		);
	}
}
