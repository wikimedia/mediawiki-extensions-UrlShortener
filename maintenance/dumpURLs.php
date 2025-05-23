<?php

use MediaWiki\Maintenance\Maintenance;
use Wikimedia\Rdbms\SelectQueryBuilder;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Creates a pipe-separated text file of generated short codes
 * to target URLs.
 */
class DumpURLs extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Create a pipe-separated dump of all the short URL codes and ' .
			'their targets' );
		$this->addArg( 'file', 'Location to save the dump', true );
		$this->setBatchSize( 1000 );
		$this->requireExtension( 'UrlShortener' );
	}

	public function execute() {
		$utils = $this->getServiceContainer()->get( 'UrlShortener.Utils' );
		$dbr = $utils->getReplicaDB();
		$file = $this->getArg( 0 );
		$this->output( "Writing to $file...\n" );
		$handle = fopen( $file, 'w' );
		if ( $handle === false ) {
			$this->fatalError( "Error opening $file. Check permissions?" );
		}
		$id = 0;
		do {
			$text = '';
			$rows = $dbr->newSelectQueryBuilder()
				->select( [ 'usc_url', 'usc_id' ] )
				->from( 'urlshortcodes' )
				->where( [ $dbr->expr( 'usc_id', '>', $id ), 'usc_deleted' => 0 ] )
				->orderBy( 'usc_id', SelectQueryBuilder::SORT_ASC )
				->limit( $this->mBatchSize )
				->caller( __METHOD__ )->fetchResultSet();
			foreach ( $rows as $row ) {
				$shortCode = $utils->encodeId( $row->usc_id );
				$url = $utils->convertToProtocol( $row->usc_url, PROTO_CANONICAL );
				$text .= "{$shortCode}|{$url}\n";
				$id = $row->usc_id;
			}
			$count = $rows->numRows();
			if ( $count ) {
				$this->output( "Writing $count entries...\n" );
				fwrite( $handle, $text );
			}
		} while ( $rows->numRows() == $this->mBatchSize );

		$this->output( "Done!\n" );
		fclose( $handle );
	}

}

$maintClass = DumpURLs::class;
require_once RUN_MAINTENANCE_IF_MAIN;
