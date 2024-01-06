<?php
/**
 * Schema hooks for setting up UrlShortener
 *
 * @file
 * @ingroup Extensions
 * @license Apache-2.0
 */

namespace MediaWiki\Extension\UrlShortener;

use DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaHooks implements LoadExtensionSchemaUpdatesHook {
	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( __DIR__ );
		$dbType = $updater->getDB()->getType();

		$updater->addExtensionUpdateOnVirtualDomain(
			[ 'virtual-urlshortener', 'addTable', 'urlshortcodes', "$dir/schemas/$dbType/tables-generated.sql", true ]
		);

		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-urlshortener',
			'addField',
			'urlshortcodes',
			'usc_deleted',
			$dir . '/schemas/patch-usc_deleted.sql',
			true ] );
	}
}
