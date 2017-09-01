<?php
/**
 * Functions used for decoding/encoding URLs
 *
 * @file
 * @ingroup Extensions
 * @author Yuvi Panda, http://yuvi.in
 * @copyright © 2014 Yuvaraj Pandian (yuvipanda@gmail.com)
 * @licence Apache 2.0
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	exit( 1 );
}

class UrlShortenerUtils {

	/**
	 * How long to cache things in Squid (one month)
	 *
	 * @var int
	 */
	const CACHE_TIME = 2592000;

	public static $decodeMap;

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
		$url = self::normalizeUrl( $url );

		$dbw = self::getDB( DB_MASTER );
		$id = $dbw->selectField(
			'urlshortcodes',
			'usc_id',
			[ 'usc_url_hash' => md5( $url ) ],
			__METHOD__
		);
		if ( $id === false ) {
			if ( $user->pingLimiter( 'urlshortcode' ) ) {
				return Status::newFatal( 'urlshortener-ratelimit' );
			}

			global $wgUrlShortenerReadOnly;
			if ( $wgUrlShortenerReadOnly ) {
				// All code paths should already have checked for this,
				// but lets be on the safe side.
				return Status::newFatal( 'urlshortener-disabled' );
			}

			$rowData = [
				'usc_url' => $url,
				'usc_url_hash' => md5( $url )
			];
			$dbw->insert( 'urlshortcodes', $rowData, __METHOD__, [ 'IGNORE' ] );

			if ( $dbw->affectedRows() ) {
				$id = $dbw->insertId();
			} else {
				// Raced out; get the winning ID
				$id = $dbw->selectField(
					'urlshortcodes',
					'usc_id',
					[ 'usc_url_hash' => md5( $url ) ],
					__METHOD__,
					[ 'LOCK IN SHARE MODE' ] // ignore snapshot
				);
			}
		}

		return Status::newGood( self::encodeId( $id ) );
	}

	/**
	 * Normalizes URL into a somewhat canonical form, including:
	 * * protocol to HTTP
	 * * from its `/w/index.php?title=$1` form to `/wiki/$1`.
	 *
	 * @param string $url might be encoded or decoded (raw user input)
	 * @return string URL that is saved in DB and used in Location header
	 */
	public static function normalizeUrl( $url ) {
		global $wgArticlePath;
		// First, force the protocol to HTTP, we'll convert
		// it to a different one when redirecting
		$url = self::convertToProtocol( $url, PROTO_HTTP );

		// TODO: We should ideally decode/encode the URL for normalization,
		// but we don't want to double-encode, nor unencode the URL that
		// is directly provided by users (see test cases)

		// If the wiki is using an article path (e.g. /wiki/$1) try
		// and convert plain index.php?title=$1 URLs to the canonical form
		if ( $wgArticlePath !== false && strpos( $url, '?' ) !== false ) {
			$parsed = wfParseUrl( $url );
			$query = wfCgiToArray( $parsed['query'] );
			if ( count( $query ) === 1 && isset( $query['title'] ) && $parsed['path'] === wfScript() ) {
				$parsed['path'] = str_replace( '$1', $query['title'], $wgArticlePath );
				unset( $parsed['query'] );
			}
			$url = wfAssembleUrl( $parsed );
		}

		return $url;
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
			[ 'usc_id' => $id ],
			__METHOD__
		);

		if ( $url === false ) {
			return false;
		}

		return self::convertToProtocol( $url, $proto );
	}

	/**
	 * @param int $type DB_SLAVE or DB_MASTER
	 * @return IDatabase
	 */
	public static function getDB( $type ) {
		global $wgUrlShortenerDBName, $wgUrlShortenerDBCluster;
		$lb = $wgUrlShortenerDBCluster
			? wfGetLBFactory()->getExternalLB( $wgUrlShortenerDBCluster )
			: wfGetLB( $wgUrlShortenerDBName );

		return $lb->getConnectionRef( $type, [], $wgUrlShortenerDBName );
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
				function ( $item ) {
					return '^' . $item . '$';
				},
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
		global $wgUrlShortenerAllowArbitraryPorts;

		$urlParts = wfParseUrl( $url );
		if ( $urlParts === false ) {
			return wfMessage( 'urlshortener-error-malformed-url' );
		} else {
			if ( isset( $urlParts['port'] ) && !$wgUrlShortenerAllowArbitraryPorts ) {
				if ( $urlParts['port'] === 80 || $urlParts['port'] === 443 ) {
					unset( $urlParts['port'] );
				} else {
					return wfMessage( 'urlshortener-error-badports' );
				}
			}

			if ( isset( $urlParts['user'] ) || isset( $urlParts['pass'] ) ) {
				return wfMessage( 'urlshortener-error-nouserpass' );
			}

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
	 * @return int|false
	 */
	public static function decodeId( $s ) {
		global $wgUrlShortenerIdSet;

		$n = strlen( $wgUrlShortenerIdSet );
		if ( self::$decodeMap === null ) {
			self::$decodeMap = [];
			for ( $i = 0; $i < $n; $i++ ) {
				self::$decodeMap[$wgUrlShortenerIdSet[$i]] = $i;
			}
		}
		$x = 0;
		for ( $i = 0, $len = strlen( $s ); $i < $len; $i++ ) {
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
