<?php

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
		$this->mDescription = 'Create a pipe-separated dump of all the short URL codes and their targets';
		$this->addArg( 'file', 'Location to save the dump', true );
		$this->setBatchSize( 1000 );
		$this->requireExtension( 'UrlShortener' );
	}

	public function execute() {
		$dbr = UrlShortenerUtils::getDB( DB_SLAVE );
		$file = $this->getArg( 0 );
		$this->output( "Writing to $file...\n" );
		$handle = fopen( $file, 'w' );
		if ( $handle === false ) {
			$this->error( "Error opening $file. Check permissions?", 1 );
		}
		$id = 0;
		do {
			$text = '';
			$rows = $dbr->select(
				'urlshortcodes',
				[ 'usc_url', 'usc_id' ],
				[ 'usc_id > ' . $dbr->addQuotes( $id ) ],
				__METHOD__,
				[ 'LIMIT' => $this->mBatchSize, 'ORDER BY' => 'usc_id ASC' ]
			);
			foreach ( $rows as $row ) {
				$shortCode = UrlShortenerUtils::encodeId( $row->usc_id );
				$url = UrlShortenerUtils::convertToProtocol( $row->usc_url, PROTO_CANONICAL );
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

$maintClass = "DumpURLs";
require_once RUN_MAINTENANCE_IF_MAIN;
