<?php
/* * Hooks for setting up UrlShortener
 *
 * @file
 * @ingroup Extensions
 * @author Yuvi Panda, http://yuvi.in
 * @copyright © 2014 Yuvaraj Pandian (yuvipanda@gmail.com)
 * @licence WTFPL
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
				array( 'title' => SpecialPage::getTitleFor( 'UrlRedirector', '$1' )->getPrefixedText() )
			);
		}
		return true;
	}

	/**
	 * Load our unit tests
	 */
	public static function onUnitTestsList( &$files ) {
		// @codeCoverageIgnoreStart
		$directoryIterator = new RecursiveDirectoryIterator( __DIR__ . '/tests/phpunit/' );

		/**
		 * @var SplFileInfo $fileInfo
		 */
		$ourFiles = array();
		foreach ( new RecursiveIteratorIterator( $directoryIterator ) as $fileInfo ) {
			if ( substr( $fileInfo->getFilename(), -8 ) === 'Test.php' ) {
				$ourFiles[] = $fileInfo->getPathname();
			}
		}

		$files = array_merge( $files, $ourFiles );
		return true;
		// @codeCoverageIgnoreEnd
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
