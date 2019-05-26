<?php
/**
 * Functions used for decoding/encoding URLs
 *
 * @file
 * @ingroup Extensions
 * @author Yuvi Panda, http://yuvi.in
 * @copyright © 2014 Yuvaraj Pandian (yuvipanda@gmail.com)
 * @license Apache-2.0
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	exit( 1 );
}

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;

class UrlShortenerUtils {

	/**
	 * How long to cache valid redirects in CDN (one month)
	 *
	 * @var int
	 */
	const CACHE_TTL_VALID = 2592000;

	/**
	 * How long to cache invalid redirects in CDN (fifteen minutes)
	 *
	 * @var int
	 */
	const CACHE_TTL_INVALID = 900;

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
		global $wgUrlShortenerUrlSizeLimit;
		$url = self::normalizeUrl( $url );

		if ( $user->isBlocked() || $user->isBlockedGlobally() ) {
			return Status::newFatal( 'urlshortener-blocked' );
		}

		global $wgUrlShortenerReadOnly;
		if ( $wgUrlShortenerReadOnly ) {
			// All code paths should already have checked for this,
			// but lets be on the safe side.
			return Status::newFatal( 'urlshortener-disabled' );
		}

		if ( mb_strlen( $url ) > $wgUrlShortenerUrlSizeLimit ) {
			return Status::newFatal(
				wfMessage( 'urlshortener-url-too-long' )->numParams( $wgUrlShortenerUrlSizeLimit )
			);
		}

		if ( $user->pingLimiter( 'urlshortcode' ) ) {
			return Status::newFatal( 'urlshortener-ratelimit' );
		}

		$dbr = self::getDB( DB_REPLICA );
		$row = $dbr->selectRow(
			'urlshortcodes',
			[ 'usc_id', 'usc_deleted' ],
			[ 'usc_url_hash' => md5( $url ) ],
			__METHOD__
		);
		if ( $row !== false ) {
			if ( $row->usc_deleted ) {
				return Status::newFatal( 'urlshortener-deleted' );
			}
			return Status::newGood( self::encodeId( $row->usc_id ) );
		}

		$rowData = [
			'usc_url' => $url,
			'usc_url_hash' => md5( $url )
		];
		$dbw = self::getDB( DB_MASTER );
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

		$shortcode = self::encodeId( $id );
		// In case our CDN cached an earlier 404/error, purge it
		self::purgeCdn( $shortcode );

		return Status::newGood( $shortcode );
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

		$url = trim( $url );

		// TODO: We should ideally decode/encode the URL for normalization,
		// but we don't want to double-encode, nor unencode the URL that
		// is directly provided by users (see test cases)
		// So for now, just replace spaces with %20, as that's safe in all cases
		$url = str_replace( ' ', '%20', $url );

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
	 * Retrieves a URL for the given shortcode, or false if there's none.
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

		$dbr = self::getDB( DB_REPLICA );
		$url = $dbr->selectField(
			'urlshortcodes',
			'usc_url',
			[ 'usc_id' => $id, 'usc_deleted' => 0 ],
			__METHOD__
		);

		if ( $url === false ) {
			return false;
		}

		return self::convertToProtocol( $url, $proto );
	}

	/**
	 * Whether a URL is deleted or not
	 *
	 * @param string $shortCode
	 * @return String
	 */
	public static function isURLDeleted( $shortCode ) {
		$id = self::decodeId( $shortCode );
		if ( $id === false ) {
			return false;
		}

		$dbr = self::getDB( DB_REPLICA );
		$url = $dbr->selectField(
			'urlshortcodes',
			'usc_url',
			[ 'usc_id' => $id, 'usc_deleted' => 1 ],
			__METHOD__
		);

		if ( $url === false ) {
			return false;
		}

		return true;
	}

	/**
	 * Mark a URL as deleted
	 *
	 * @param string $shortcode
	 *
	 * @return bool False if the $shortCode was invalid
	 */
	public static function deleteURL( $shortcode ) {
		$id = self::decodeId( $shortcode );
		if ( $id === false ) {
			return false;
		}

		$dbw = self::getDB( DB_MASTER );
		$dbw->update(
			'urlshortcodes',
			[ 'usc_deleted' => 1 ],
			[ 'usc_id' => $id ],
			__METHOD__
		);

		self::purgeCdn( $shortcode );

		return true;
	}

	/**
	 * Mark a URL as undeleted
	 *
	 * @param string $shortcode
	 *
	 * @return bool False if the $shortCode was invalid
	 */
	public static function restoreURL( $shortcode ) {
		$id = self::decodeId( $shortcode );
		if ( $id === false ) {
			return false;
		}

		$dbw = self::getDB( DB_MASTER );
		$dbw->update(
			'urlshortcodes',
			[ 'usc_deleted' => 0 ],
			[ 'usc_id' => $id ],
			__METHOD__
		);

		self::purgeCdn( $shortcode );

		return true;
	}

	/**
	 * If configured, purge CDN for the given shortcode
	 * @param string $shortcode
	 */
	private static function purgeCdn( $shortcode ) {
		global $wgUseCdn;
		if ( $wgUseCdn ) {
			$update = new CdnCacheUpdate( [ self::makeUrl( $shortcode ) ] );
			DeferredUpdates::addUpdate( $update, DeferredUpdates::PRESEND );
		}
	}

	/**
	 * @param int $type DB_REPLICA or DB_MASTER
	 * @return IDatabase
	 */
	public static function getDB( $type ) {
		global $wgUrlShortenerDBName, $wgUrlShortenerDBCluster;
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$lb = $wgUrlShortenerDBCluster
			? $lbFactory->getExternalLB( $wgUrlShortenerDBCluster )
			: $lbFactory->getMainLB( $wgUrlShortenerDBName );

		return $lb->getConnectionRef( $type, [], $wgUrlShortenerDBName );
	}

	/**
	 * Create a fully qualified short URL for the given shortcode.
	 *
	 * @param string $shortCode base64 shortcode to generate URL For.
	 * @return string The fully qualified URL
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
	 * @param string $url Url to Validate
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
	 * @param int $x
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
	 * @param string $s
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
