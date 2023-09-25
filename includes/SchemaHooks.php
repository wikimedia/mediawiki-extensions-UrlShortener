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

		if ( $dbType === 'mysql' ) {
			$updater->addExtensionTable(
				'urlshortcodes',
				$dir . '/schemas/tables-generated.sql'
			);
		} elseif ( $dbType === 'sqlite' ) {
			$updater->addExtensionTable(
				'urlshortcodes',
				$dir . '/schemas/sqlite/tables-generated.sql'
			);
		} elseif ( $dbType === 'postgres' ) {
			$updater->addExtensionTable(
				'urlshortcodes',
				$dir . '/schemas/postgres/tables-generated.sql'
			);
		}

		$updater->addExtensionField(
			'urlshortcodes',
			'usc_deleted',
			$dir . "/schemas/patch-usc_deleted.sql"
		);
	}
}
