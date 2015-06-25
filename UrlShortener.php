<?php
/**
 * Setup for UrlShortener extension, which is a simple URL Shortener provided as a MW extension
 *
 * @file
 * @ingroup Extensions
 * @author Yuvi Panda, http://yuvi.in
 * @copyright © 2014 Yuvaraj Pandian (yuvipanda@gmail.com)
 * @licence WTFPL
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	echo( "This file is an extension to the MediaWiki software and cannot be used standalone.\n" );
	die( 1 );
}

/**
 * Configuration variables
 * Template to use for the shortened URL. $1 is replaced with the shortened code.
 * $wgServer is prepended to $wgUrlShortenerTemplate for displaying the URL.
 * mod_rewrite (or equivalent) needs to be setup to produce a shorter URL.
 * See example redirect.htaccess file.
 * Default is false which just uses the (not so short) URL that all Special Pages get
 * Eg: /wiki/Special:UrlShortener/5234
 * An example value for this variable might be:
 * $wgUrlShortenerTemplate = '/r/$1';
 */
$wgUrlShortenerTemplate = false;

/**
 * Configurable whitelist of domains to allow URL Shortening for.
 *
 * Setting to false only allows shortening to $wgServer.
 *
 * Set to an array of regexes (that can be consumed by preg_match) to allow
 * only Domains that match one of the regexes. A '^' and '$' are prepended
 * and appended to the regex provided to ensure that domain substring matches
 * are not allowed. No need to add the '/' delimiters either.
 *
 * Examples:
 * 	// Allow only three domains and all their subdomains
 * 	$wgUrlShortenerDomainsWhitelist = array(
 * 		'(.*\.)?wikimedia\.org',
 * 		'(.*\.)?wikipedia\.org',
 * );
 *
 * // Allow *all* domains
 * $wgUrlShortenerDomainsWhitelist = array( '.*' );
 */
$wgUrlShortenerDomainsWhitelist = false;

/**
 * If you're running a wiki farm, you probably just want to have one
 * central database with all of your short urls.
 * If not set, uses the local wiki's database.
 */
$wgUrlShortenerDBName = false;

/**
 * If you have a custom short domain name, set it here.
 * If not set, uses $wgServer
 */
$wgUrlShortenerServer = false;

/**
 * A string giving the list of characters that is used as a symbol set for 
 * base conversion of the shortcode IDs. If you change this, any existing short
 * URLs will go to the wrong destination.
 */
$wgUrlShortenerIdSet = '023456789ABCDEFGHJKLMNOPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz$-_.!';

// Extension credits that will show up on Special:Version
$wgExtensionCredits['specialpage'][] = array(
	'path' => __FILE__,
	'name' => 'UrlShortener',
	'version' => '1.0.0',
	'author' => 'Yuvi Panda',
	'url' => 'https://www.mediawiki.org/wiki/Extension:UrlShortener',
	'descriptionmsg' => 'urlshortener-desc',
);

// Set up the new special page
$wgMessagesDirs['UrlShortener'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['UrlShortener'] = __DIR__ . '/UrlShortener.i18n.php';
$wgExtensionMessagesFiles['UrlShortenerAlias'] = __DIR__ . '/UrlShortener.alias.php';
$wgExtensionMessagesFiles['UrlShortenerNoTranslateAlias'] = __DIR__ . '/UrlShortener.notranslate-alias.php';


$wgAutoloadClasses['UrlShortenerUtils'] = __DIR__ . '/UrlShortener.utils.php';
$wgAutoloadClasses['UrlShortenerHooks'] = __DIR__ . '/UrlShortener.hooks.php';
$wgAutoloadClasses['SpecialUrlShortener'] = __DIR__ . '/SpecialUrlShortener.php';
$wgAutoloadClasses['SpecialUrlRedirector'] = __DIR__ . '/SpecialUrlRedirector.php';

$wgAutoloadClasses['ApiShortenUrl'] = __DIR__ . '/ApiShortenUrl.php';

$wgSpecialPages['UrlShortener'] = 'SpecialUrlShortener';
$wgSpecialPages['UrlRedirector'] = 'SpecialUrlRedirector';

$wgHooks['LoadExtensionSchemaUpdates'][] = 'UrlShortenerHooks::onLoadExtensionSchemaUpdates';
$wgHooks['WebRequestPathInfoRouter'][] = 'UrlShortenerHooks::onWebRequestPathInfoRouter';

$wgAPIModules['shortenurl'] = 'ApiShortenUrl';

// Served both to JS and non-JS clients
$wgResourceModules['ext.urlShortener.special.styles'] = array(
	'styles' => 'less/ext.urlShortener.special.less',
	'targets' => array ( 'desktop', 'mobile' ),
	'position' => 'top',
	'dependencies' => array(
		'mediawiki.ui',
	),
	'localBasePath' => __DIR__,
	'remoteExtPath' => 'UrlShortener',
);

// Served only to JS clients
$wgResourceModules['ext.urlShortener.special'] = array(
	'scripts' => array(
		'js/ext.urlShortener.special.js',
	),
	'messages' => array(
		'urlshortener-error-malformed-url',
		'urlshortener-error-disallowed-url',
		'urlshortener-url-input-submit',
		'urlshortener-url-input-submitting',
	),
	'localBasePath' => __DIR__,
	'remoteExtPath' => 'UrlShortener',
	'dependencies' => array(
		'mediawiki.api',
		'mediawiki.Uri',
		'jquery.tipsy',
	),
	"position" => "top"
);
