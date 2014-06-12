<?php
/**
 * Setup for UrlShortener extension, which is a simple URL Shortener provided as a MW extension
 *
 * @file
 * @ingroup Extensions
 * @author Yuvi Panda, http://yuvi.in
 * @copyright © 2014 Yuvaraj Pandian (yuvipanda@yuvi.in)
 * @licence Modified BSD License
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
$dir = __DIR__ . '/';
$wgMessagesDirs['UrlShortener'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['UrlShortenerAlias'] = $dir . 'UrlShortener.alias.php';

$wgAutoloadClasses['UrlShortenerUtils'] = $dir . 'UrlShortener.utils.php';
$wgAutoloadClasses['UrlShortenerHooks'] = $dir . 'UrlShortener.hooks.php';
$wgAutoloadClasses['SpecialUrlShortener'] = $dir . 'SpecialUrlShortener.php';
$wgSpecialPages['UrlShortener'] = 'SpecialUrlShortener';
$wgSpecialPageGroups['UrlShortener'] = 'pagetools';

$wgHooks['LoadExtensionSchemaUpdates'][] = 'UrlShortenerHooks::setupSchema';
$wgHooks['WebRequestPathInfoRouter'][] = 'UrlShortenerHooks::setupUrlRouting';

$wgResourceModules['ext.urlShortener.special'] = array(
	'styles' => 'less/ext.urlShortener.special.less',
	'localBasePath' => dirname( __FILE__ ),
	'remoteExtPath' => 'UrlShortener',
	'dependencies' => array(
		'mediawiki.ui'
	),
	"position" => "top"
);
