<?php
/**
 * Functions used for decoding/encoding URLs
 *
 * @file
 * @ingroup Extensions
 * @author Yuvi Panda, http://yuvi.in
 * @copyright © 2014 Yuvaraj Pandian (yuvipanda@gmail.com)
 * @licence WTFPL
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	exit( 1 );
}

class UrlShortenerUtils {
	static $decodeMap;

	/**
	 * Gets the short code for the given URL.
	 *
	 * If it already exists in cache or the database, just returns that.
	 * Otherwise, a new shortcode entry is created and returned.
	 *
	 * @param $url URL to encode
	 * @return string base36 encoded shortcode that refers to the $url
	 */
	public static function getShortCode( $url ) {
		global $wgMemc;

		$memcKey = wfMemcKey( 'urlshortcode', 'title', md5( $url ) );
		$id = $wgMemc->get( $memcKey );
		if ( !$id ) {
			$dbr = self::getDB( DB_SLAVE );
			$entry = $dbr->selectRow(
				'urlshortcodes',
				array( 'usc_id' ),
				array(
					'usc_url_hash' => md5( $url ),
				),
				__METHOD__
			);
			if ( $entry !== false ) {
				$id = $entry->usc_id;
			} else {
				$dbw = self::getDB( DB_MASTER );
				// FIXME: Potential race condition since we're checking a slave first for existance
				// but writing to Master, and there's a unique constraint on the url column.
				// Should be rare, but we should handle it properly anyway.
				$rowData = array(
					'usc_url' => $url,
					'usc_url_hash' => md5( $url )
				);
				$dbw->insert( 'urlshortcodes', $rowData, __METHOD__ );
				$id = $dbw->insertId();
			}
			$wgMemc->set( $memcKey, $id );
		}
		return self::encodeId( $id );
	}

	/**
	 * Retreives a URL for the given shortcode, or false if there's none.
	 *
	 * @param $shortCode String
	 * @return String
	 */
	public static function getURL( $shortCode ) {
		global $wgMemc;

		$id = self::decodeId( $shortCode );
		if ( $id === false ) {
			return false;
		}
		$memcKey = wfMemcKey( 'urlshortcode', 'id', $id );
		$url = $wgMemc->get( $memcKey );

		// check if this is cached to not exist
		if ( $url === '!!NOEXIST!!' ) {
			return false;
		}

		if ( !$url ) {

			$dbr = self::getDB( DB_SLAVE );
			$entry = $dbr->selectRow(
				'urlshortcodes',
				array( 'usc_url' ),
				array( 'usc_id' => $id ),
				__METHOD__
			);

			if ( $entry === false ) {
				// No such shortcode exists; briefly cache negatives
				$wgMemc->set( $memcKey, '!!NOEXIST!!', 300 );
				return false;
			}
			$url = $entry->usc_url;
			$wgMemc->set( $memcKey, $url );
		}
		return $url;
	}

	/**
	 * @param int $type DB_SLAVE or DB_MASTER
	 * @return DatabaseBase
	 */
	public static function getDB( $type ) {
		global $wgUrlShortenerDBName;
		if ( $wgUrlShortenerDBName !== false ) {
			return wfGetDB( $type, array(), $wgUrlShortenerDBName );
		} else {
			return wfGetDB( $type );
		}
	}

	/**
	 * Create a fully qualified short URL for the given shortcode.
	 *
	 * @param $shortCode String base64 shortcode to generate URL For.
	 * @return String The fully qualified URL
	 */
	public static function makeUrl( $shortCode ) {
		global $wgUrlShortenerTemplate, $wgUrlShortenerServer, $wgServer;

		if ( $wgUrlShortenerServer === false ) {
			$wgUrlShortenerServer = $wgServer;
		}

		if ( !is_string( $wgUrlShortenerTemplate ) ) {
			$urlTemplate = SpecialPage::getTitleFor( 'UrlRedirector', '$1' )->getFullUrl();
		} else {
			$urlTemplate = $wgUrlShortenerServer . $wgUrlShortenerTemplate;
		}
		$url = str_replace( '$1', $shortCode, $urlTemplate );

		// Make sure the URL is fully qualified
		$url = wfExpandUrl( $url );

		return $url;
	}

	public static function getWhitelistRegex() {
		global $wgUrlShortenerDomainsWhitelist, $wgServer;
		if ( $wgUrlShortenerDomainsWhitelist === false ) {
			// Domain Whitelist not configured, default to wgServer
			$serverParts = wfParseUrl( $wgServer );
			$domainsWhitelist = preg_quote( $serverParts['host'], '/' );
		} else {
			// Collapse the whitelist into a single string, so we have to run regex check only once
			$domainsWhitelist = implode( '|', array_map(
				function( $item ) { return '^' . $item . '$'; },
				$wgUrlShortenerDomainsWhitelist
			) );
		}

		return $domainsWhitelist;
	}

	/**
	 * Validates a given URL to see if it is allowed to be used to create a short URL
	 *
	 * @param $url String Url to Validate
	 * @return bool|Message true if it is valid, or error Message object if invalid
	 */
	public static function validateUrl( $url ) {
		$urlParts = wfParseUrl( $url );
		if ( $urlParts === false ) {
			return wfMessage( 'urlshortener-error-malformed-url' );
		} else {
			$domain = $urlParts['host'];

			if ( preg_match( '/' . self::getWhitelistRegex() . '/', $domain ) === 1 ) {
				return true;
			}

			return wfMessage( 'urlshortener-error-disallowed-url' )->params( htmlentities( $domain ) );
		}
	}

	/**
	 * Encode an integer into a compact string representation. This is basically
	 * a generalisation of base_convert().
	 *
	 * @param $x integer
	 * @return string
	 */
	public static function encodeId( $x ) {
		global $wgUrlShortenerIdSet;
		$s = '';
		$x = intval( $x );
		$n = strlen( $wgUrlShortenerIdSet );
		while ( $x ) {
			$remainder = $x % $n;
			$x = ( $x - $remainder ) / $n;
			$s = $wgUrlShortenerIdSet[$remainder] . $s;
		}
		return $s;
	}

	/**
	 * Decode a compact string to produce an integer, or false if the input is invalid.
	 *
	 * @param $s string
	 * @return integer|false
	 */
	public static function decodeId( $s ) {
		global $wgUrlShortenerIdSet;

		$n = strlen( $wgUrlShortenerIdSet );
		if ( self::$decodeMap === null ) {
			self::$decodeMap = array();
			for ( $i = 0; $i < $n; $i++ ) {
				self::$decodeMap[$wgUrlShortenerIdSet[$i]] = $i;
			}
		}
		$x = 0;
		for ( $i = 0; $i < strlen( $s ); $i++ ) {
			$x *= $n;
			if ( isset( self::$decodeMap[$s[$i]] ) ) {
				$x += self::$decodeMap[$s[$i]];
			} else {
				return false;
			}
		}
		return $x;
	}
}
