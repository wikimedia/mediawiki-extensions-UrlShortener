<?php
/* * Hooks for setting up UrlShortener
 *
 * @file
 * @ingroup Extensions
 * @author Yuvi Panda, http://yuvi.in
 * @copyright © 2014 Yuvaraj Pandian (yuvipanda@gmail.com)
 * @licence Apache 2.0
 */

class UrlShortenerHooks {
	/**
	 * @param $router PathRouter
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
	 * @param BaseTemplate $template
	 * @param array $toolbox
	 */
	public static function onBaseTemplateToolbox( BaseTemplate $template, array &$toolbox ) {
		global $wgUrlShortenerReadOnly;

		if ( $wgUrlShortenerReadOnly ) {
			return;
		}

		$skin = $template->getSkin();
		if ( $skin->getTitle()->isSpecial( 'UrlShortener' ) ) {
			return;
		}
		$query = $skin->getRequest()->getQueryValues();
		if ( isset( $query['title'] ) ) {
			// We already know the title
			unset( $query['title'] );
		}
		$linkToShorten = $skin->getTitle()->getFullURL( $query, false, PROTO_CANONICAL );
		$link = SpecialPage::getTitleFor( 'UrlShortener' )
			->getLocalURL( [ 'url' => $linkToShorten ] );
		$toolbox['urlshortener'] = [
			'id' => 't-urlshortener',
			'href' => $link,
			'msg' => 'urlshortener-toolbox'
		];
	}

	/**
	 * @param $du DatabaseUpdater
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $du ) {
		$base = __DIR__ . '/schemas';
		$du->addExtensionTable( 'urlshortcodes', "$base/urlshortcodes.sql" );
		return true;
	}
}
