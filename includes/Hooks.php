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

use MediaWiki\Config\ConfigException;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Hook\SidebarBeforeOutputHook;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Hook\WebRequestPathInfoRouterHook;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\PathRouter;
use MediaWiki\Settings\SettingsBuilder;
use MediaWiki\SpecialPage\SpecialPage;
use MobileContext;
use Skin;
use SkinTemplate;

class Hooks implements
	WebRequestPathInfoRouterHook,
	BeforePageDisplayHook,
	SidebarBeforeOutputHook,
	SkinTemplateNavigation__UniversalHook
{
	private bool $enableSidebar;
	private bool $enableQrCode;
	private bool $readOnly;
	/** @var string|false */
	private $urlTemplate;
	/** @phan-suppress-next-line PhanUndeclaredTypeProperty */
	private ?MobileContext $mobileContext;

	public function __construct(
		ConfigFactory $configFactory,
		// @phan-suppress-next-line PhanUndeclaredTypeParameter
		?MobileContext $mobileContext
	) {
		$config = $configFactory->makeConfig( 'urlshortener' );
		$this->enableSidebar = $config->get( 'UrlShortenerEnableSidebar' );
		$this->enableQrCode = $config->get( 'UrlShortenerEnableQrCode' );
		$this->readOnly = $config->get( 'UrlShortenerReadOnly' );
		$this->urlTemplate = $config->get( 'UrlShortenerTemplate' );
		$this->mobileContext = $mobileContext;
	}

	/**
	 * @param PathRouter $router
	 *
	 * Adds UrlShortener rules to the URL router.
	 */
	public function onWebRequestPathInfoRouter( $router ) {
		// If a template is set, and it is not the root, register it
		if ( $this->urlTemplate && $this->urlTemplate !== '/$1' ) {
			$router->add( $this->urlTemplate,
				[ 'title' => SpecialPage::getTitleFor( 'UrlRedirector', '$1' )->getPrefixedText() ]
			);
		}
	}

	public static function onRegistration( array $extInfo, SettingsBuilder $settings ) {
		$config = $settings->getConfig();
		$idSet = $config->get( 'UrlShortenerIdSet' );
		$idMapping = $config->get( 'UrlShortenerIdMapping' );
		$altPrefix = $config->get( 'UrlShortenerAltPrefix' );

		if ( strpos( $idSet, $altPrefix ) !== false ) {
			throw new ConfigException( 'UrlShortenerAltPrefix cannot be contained in UrlShortenerIdSet' );
		}
		if ( isset( $idMapping[ $altPrefix ] ) ) {
			throw new ConfigException( 'UrlShortenerAltPrefix cannot be contained in UrlShortenerIdMapping' );
		}
	}

	/**
	 * Load toolbar module for the sidebar link
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( $this->readOnly || !$this->enableSidebar || $skin->getTitle()->isSpecial( 'UrlShortener' ) ) {
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
		if ( $this->readOnly || ( !$this->enableSidebar && !$this->enableQrCode ) ) {
			return;
		}

		$fullURL = self::getFullUrl( $skin );
		if ( $this->enableSidebar && !$skin->getTitle()->isSpecial( 'UrlShortener' ) ) {
			// Append link to generate short URL
			$sidebar['TOOLBOX']['urlshortener'] = [
				'id' => 't-urlshortener',
				'href' => SpecialPage::getTitleFor( 'UrlShortener' )->getLocalURL( [ 'url' => $fullURL ] ),
				'text' => $skin->msg( 'urlshortener-toolbox' )->text(),
			];
		}

		if ( $this->enableQrCode ) {
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
		// @phan-suppress-next-line PhanUndeclaredClassMethod
		if ( $this->enableQrCode && $this->mobileContext && $this->mobileContext->shouldDisplayMobileView() ) {
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
