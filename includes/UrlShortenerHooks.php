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

class UrlShortenerHooks {
	/**
	 * @param PathRouter $router
	 * @return bool
	 *
	 * Adds UrlShortener rules to the URL router.
	 */
	public static function onWebRequestPathInfoRouter( $router ) {
		global $wgUrlShortenerTemplate;
		// If a template is set, and it is not the root, register it
		if ( $wgUrlShortenerTemplate && $wgUrlShortenerTemplate !== '/$1' ) {
			$router->add( $wgUrlShortenerTemplate,
				[ 'title' => SpecialPage::getTitleFor( 'UrlRedirector', '$1' )->getPrefixedText() ]
			);
		}
		return true;
	}

	public static function onBeforePageDisplay( OutputPage $out ) {
		global $wgUrlShortenerReadOnly;

		if ( !$wgUrlShortenerReadOnly ) {
			$out->addModules( 'ext.urlShortener.toolbar' );
		}
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
	 * @param DatabaseUpdater $du
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $du ) {
		$base = dirname( __DIR__ ) . '/schemas';
		$du->addExtensionTable( 'urlshortcodes', "$base/urlshortcodes.sql" );
		$du->addExtensionField( 'urlshortcodes', 'usc_deleted',
			"$base/patch-usc_deleted.sql" );
		return true;
	}
}
