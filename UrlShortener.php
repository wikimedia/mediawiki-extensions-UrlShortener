<?php

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'UrlShortener' );
	// Keep i18n globals so mergeMessageFileList.php doesn't break
	$wgMessagesDirs['UrlShortener'] = __DIR__ . '/i18n';
	$wgExtensionMessagesFiles['UrlShortenerAlias'] = __DIR__ . '/UrlShortener.alias.php';
	$wgExtensionMessagesFiles['UrlShortenerNoTranslateAlias'] = __DIR__ . '/UrlShortener.notranslate-alias.php';
	/* wfWarn(
		'Deprecated PHP entry point used for UrlShortener extension. Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	); */
	return true;
} else {
	die( 'This version of the UrlShortener extension requires MediaWiki 1.25+' );
}
