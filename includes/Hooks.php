<?php
/**
 * Hooks for setting up UrlShortener
 *
 * @file
 * @ingroup Extensions
 * @author Yuvi Panda, http://yuvi.in
 * @copyright © 2014 Yuvaraj Pandian (yuvipanda@gmail.com)
 * @license Apache-2.0
 */

namespace MediaWiki\Extension\UrlShortener;

use DatabaseUpdater;
use OutputPage;
use PathRouter;
use Skin;
use SpecialPage;

class Hooks {
	/**
	 * @param PathRouter $router
	 * @return bool
	 *
	 * Adds UrlShortener rules to the URL router.
	 */
	public static function onWebRequestPathInfoRouter( PathRouter $router ): bool {
		global $wgUrlShortenerTemplate;
		// If a template is set, and it is not the root, register it
		if ( $wgUrlShortenerTemplate && $wgUrlShortenerTemplate !== '/$1' ) {
			$router->add( $wgUrlShortenerTemplate,
				[ 'title' => SpecialPage::getTitleFor( 'UrlRedirector', '$1' )->getPrefixedText() ]
			);
		}
		return true;
	}

	public static function onRegistration() {
		global $wgUrlShortenerIdSet, $wgUrlShortenerIdMapping, $wgUrlShortenerAltPrefix;

		if ( strpos( $wgUrlShortenerIdSet, $wgUrlShortenerAltPrefix ) !== false ) {
			throw new \ConfigException( 'UrlShortenerAltPrefix cannot be contained in UrlShortenerIdSet' );
		}
		if ( isset( $wgUrlShortenerIdMapping[ $wgUrlShortenerAltPrefix ] ) ) {
			throw new \ConfigException( 'UrlShortenerAltPrefix cannot be contained in UrlShortenerIdMapping' );
		}
	}

	/**
	 * Load toolbar module for the sidebar link
	 *
	 * @param OutputPage $out
	 */
	public static function onBeforePageDisplay( OutputPage $out ) {
		global $wgUrlShortenerReadOnly, $wgUrlShortenerEnableSidebar;

		if ( $wgUrlShortenerReadOnly || !$wgUrlShortenerEnableSidebar ) {
			return;
		}

		$out->addModules( 'ext.urlShortener.toolbar' );
	}

	/**
	 * Adds a link to the toolbox to Special:UrlShortener
	 *
	 * @param Skin $skin
	 * @param array &$sidebar
	 */
	public static function onSidebarBeforeOutput( Skin $skin, array &$sidebar ) {
		global $wgUrlShortenerReadOnly, $wgUrlShortenerEnableSidebar;

		if ( $wgUrlShortenerReadOnly || !$wgUrlShortenerEnableSidebar ) {
			return;
		}

		if ( $skin->getTitle()->isSpecial( 'UrlShortener' ) ) {
			return;
		}

		$query = $skin->getRequest()->getQueryValues();
		if ( isset( $query['title'] ) ) {
			// We already know the title
			unset( $query['title'] );
		}

		$fullURL = $skin->getTitle()->getFullURL( $query, false, PROTO_CANONICAL );
		$localURL = SpecialPage::getTitleFor( 'UrlShortener' )->getLocalURL( [ 'url' => $fullURL ] );
		$message = $skin->msg( 'urlshortener-toolbox' )->text();

		// Append link
		$sidebar['TOOLBOX']['urlshortener'] = [
			'id' => 't-urlshortener',
			'href' => $localURL,
			'text' => $message
		];
	}

	/**
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
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
