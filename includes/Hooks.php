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

use ExtensionRegistry;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\SidebarBeforeOutputHook;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Hook\WebRequestPathInfoRouterHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Request\PathRouter;
use OutputPage;
use Skin;
use SkinTemplate;
use SpecialPage;

class Hooks implements
	WebRequestPathInfoRouterHook,
	BeforePageDisplayHook,
	SidebarBeforeOutputHook,
	SkinTemplateNavigation__UniversalHook
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
		global $wgUrlShortenerReadOnly, $wgUrlShortenerEnableSidebar, $wgUrlShortenerEnableQrCode;

		if ( $wgUrlShortenerReadOnly ) {
			return;
		}

		if ( !$wgUrlShortenerEnableSidebar && !$wgUrlShortenerEnableQrCode ) {
			return;
		}

		$fullURL = self::getFullUrl( $skin );
		if ( $wgUrlShortenerEnableSidebar && !$skin->getTitle()->isSpecial( 'UrlShortener' ) ) {
			// Append link to generate short URL
			$sidebar['TOOLBOX']['urlshortener'] = [
				'id' => 't-urlshortener',
				'href' => SpecialPage::getTitleFor( 'UrlShortener' )->getLocalURL( [ 'url' => $fullURL ] ),
				'text' => $skin->msg( 'urlshortener-toolbox' )->text(),
			];
		}
		if ( $wgUrlShortenerEnableQrCode ) {
			// Append link to download QR code
			$sidebar['TOOLBOX']['urlshortener-qrcode'] = [
				'id' => 't-urlshortener-qrcode',
				'href' => SpecialPage::getTitleFor( 'QrCode' )->getLocalURL( [ 'url' => $fullURL ] ),
				'text' => $skin->msg( 'urlshortener-toolbox-qrcode' )->text(),
			];
		}
	}

	/**
	 * Display the "Download QR code" link in the Minerva overflow menu.
	 *
	 * @param SkinTemplate $sktemplate
	 * @param array &$links
	 * @phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		global $wgUrlShortenerEnableQrCode;
		if ( $wgUrlShortenerEnableQrCode && ExtensionRegistry::getInstance()->isLoaded( 'MobileFrontend' ) ) {
			$mobileContext = MediaWikiServices::getInstance()->getService( 'MobileFrontend.Context' );
			$isMobileView = $mobileContext->shouldDisplayMobileView();
			if ( $isMobileView ) {
				$fullURL = self::getFullUrl( $sktemplate );
				$links['actions']['qrcode'] = [
					'icon' => 'qrCode',
					// Needs its own selector to avoid styling clashes.
					'class' => 'ext-urlshortener-qrcode-download-minerva',
					'href' => SpecialPage::getTitleFor( 'QrCode' )->getLocalURL( [ 'url' => $fullURL ] ),
					'text' => $sktemplate->msg( 'urlshortener-toolbox-qrcode' )->plain(),
				];
			}
		}
	}

	/**
	 * @param Skin $skin
	 * @return string
	 */
	private static function getFullUrl( Skin $skin ): string {
		$query = $skin->getRequest()->getQueryValues();
		if ( isset( $query['title'] ) ) {
			// We already know the title
			unset( $query['title'] );
		}

		return $skin->getTitle()->getFullURL( $query, false, PROTO_CANONICAL );
	}
}
