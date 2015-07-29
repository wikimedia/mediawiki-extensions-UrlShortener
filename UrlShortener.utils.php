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
	 * Gets the short code for the given URL, creating if it doesn't
	 * have one already.
	 *
	 * If it already exists in cache or the database, just returns that.
	 * Otherwise, a new shortcode entry is created and returned.
	 *
	 * @param string $url URL to encode
	 * @param User $user User requesting the url, for rate limiting
	 * @return Status with value of base36 encoded shortcode that refers to the $url
	 */
	public static function maybeCreateShortCode( $url, User $user ) {
		// First, cannonicalize the URL
		// store everything in the db as HTTP, we'll convert it before
		// redirecting users
		$url = self::convertToProtocol( $url, PROTO_HTTP );

		$dbw = self::getDB( DB_MASTER );
		$id = $dbw->selectField(
			'urlshortcodes',
			'usc_id',
			array(
				'usc_url_hash' => md5( $url ),
			),
			__METHOD__
		);
		if ( $id === false ) {
			if ( $user->pingLimiter( 'urlshortcode' ) ) {
				return Status::newFatal( 'urlshortener-ratelimit' );
			}
			$rowData = array(
				'usc_url' => $url,
				'usc_url_hash' => md5( $url )
			);
			$dbw->insert( 'urlshortcodes', $rowData, __METHOD__ );
			$id = $dbw->insertId();
		}

		return Status::newGood( self::encodeId( $id ) );
	}

	/**
	 * Converts a possibly protocol'd url to the one specified
	 *
	 * @param string $url
	 * @param string|int $proto PROTO_* constant
	 * @return string
	 */
	public static function convertToProtocol( $url, $proto = PROTO_RELATIVE ) {
		$parsed = wfParseUrl( $url );
		unset( $parsed['scheme'] );
		$parsed['delimiter'] = '//';

		return wfExpandUrl( wfAssembleUrl( $parsed ), $proto );
	}

	/**
	 * Retreives a URL for the given shortcode, or false if there's none.
	 *
	 * @param string $shortCode
	 * @param string|int $proto PROTO_* constant
	 * @return String
	 */
	public static function getURL( $shortCode, $proto = PROTO_RELATIVE ) {
		$id = self::decodeId( $shortCode );
		if ( $id === false ) {
			return false;
		}

		$dbr = self::getDB( DB_SLAVE );
		$url = $dbr->selectField(
			'urlshortcodes',
			'usc_url',
			array( 'usc_id' => $id ),
			__METHOD__
		);

		if ( $url === false ) {
			return false;
		}

		return self::convertToProtocol( $url, $proto );
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
