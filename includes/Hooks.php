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

use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\SidebarBeforeOutputHook;
use MediaWiki\Hook\WebRequestPathInfoRouterHook;
use MediaWiki\Request\PathRouter;
use OutputPage;
use Skin;
use SpecialPage;

class Hooks implements
	WebRequestPathInfoRouterHook,
	BeforePageDisplayHook,
	SidebarBeforeOutputHook
{
	/**
	 * @param PathRouter $router
	 *
	 * Adds UrlShortener rules to the URL router.
	 */
	public function onWebRequestPathInfoRouter( $router ) {
		global $wgUrlShortenerTemplate;
		// If a template is set, and it is not the root, register it
		if ( $wgUrlShortenerTemplate && $wgUrlShortenerTemplate !== '/$1' ) {
			$router->add( $wgUrlShortenerTemplate,
				[ 'title' => SpecialPage::getTitleFor( 'UrlRedirector', '$1' )->getPrefixedText() ]
			);
		}
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
	 * @param Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
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
	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
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
}
