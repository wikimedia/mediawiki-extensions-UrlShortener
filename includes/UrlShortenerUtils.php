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

namespace MediaWiki\Extension\UrlShortener;

use CdnCacheUpdate;
use DeferredUpdates;
use MediaWiki\MediaWikiServices;
use Message;
use SpecialPage;
use Status;
use User;
use Wikimedia\Rdbms\IDatabase;

class UrlShortenerUtils {

	/**
	 * How long to cache valid redirects in CDN (one month)
	 *
	 * @var int
	 */
	public const CACHE_TTL_VALID = 2592000;

	/**
	 * How long to cache invalid redirects in CDN (fifteen minutes)
	 *
	 * @var int
	 */
	public const CACHE_TTL_INVALID = 900;

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
	 * @return Status Status with value of base36 encoded shortcode that refers to the $url
	 */
	public static function maybeCreateShortCode( string $url, User $user ): Status {
		global $wgUrlShortenerUrlSizeLimit;
		$url = self::normalizeUrl( $url );

		if ( $user->getBlock() ) {
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
			return Status::newGood( [
				'url' => self::encodeId( $row->usc_id ),
				'alt' => self::encodeId( $row->usc_id, true )
			] );
		}

		$rowData = [
			'usc_url' => $url,
			'usc_url_hash' => md5( $url )
		];
		$dbw = self::getDB( DB_PRIMARY );
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

		// In case our CDN cached an earlier 404/error, purge it
		self::purgeCdnId( $id );

		return Status::newGood( [
			'url' => self::encodeId( $id ),
			'alt' => self::encodeId( $id, true )
		] );
	}

	/**
	 * Normalizes URL into a somewhat canonical form, including:
	 * * protocol to HTTP
	 * * from its `/w/index.php?title=$1` form to `/wiki/$1`.
	 *
	 * @param string $url might be encoded or decoded (raw user input)
	 * @return string URL that is saved in DB and used in Location header
	 */
	public static function normalizeUrl( string $url ): string {
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
		$parsed = wfParseUrl( $url );
		if ( !isset( $parsed['path'] ) ) {
			// T220718: Ensure each URL has a / after the domain name
			$parsed['path'] = '/';
		}
		if ( $wgArticlePath !== false && isset( $parsed['query'] ) ) {
			$query = wfCgiToArray( $parsed['query'] );
			if ( count( $query ) === 1 && isset( $query['title'] ) && $parsed['path'] === wfScript() ) {
				$parsed['path'] = str_replace( '$1', $query['title'], $wgArticlePath );
				unset( $parsed['query'] );
			}
		}
		$url = wfAssembleUrl( $parsed );

		return $url;
	}

	/**
	 * Converts a possibly protocol'd url to the one specified
	 *
	 * @param string $url
	 * @param string|int $proto PROTO_* constant
	 * @return string
	 */
	public static function convertToProtocol( string $url, $proto = PROTO_RELATIVE ): string {
		$parsed = wfParseUrl( $url );
		unset( $parsed['scheme'] );
		$parsed['delimiter'] = '//';

		return wfExpandUrl( wfAssembleUrl( $parsed ), $proto );
	}

	/**
	 * Retrieves a URL for the given shortcode, or false if there's none.
	 *
	 * @param string $shortCode
	 * @param string|int|null $proto PROTO_* constant
	 * @return string|false
	 */
	public static function getURL( string $shortCode, $proto = PROTO_RELATIVE ) {
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
	 * @return bool
	 */
	public static function isURLDeleted( string $shortCode ): bool {
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

		return $url !== false;
	}

	/**
	 * Mark a URL as deleted
	 *
	 * @param string $shortcode
	 * @return bool False if the $shortCode was invalid
	 */
	public static function deleteURL( string $shortcode ): bool {
		$id = self::decodeId( $shortcode );
		if ( $id === false ) {
			return false;
		}

		$dbw = self::getDB( DB_PRIMARY );
		$dbw->update(
			'urlshortcodes',
			[ 'usc_deleted' => 1 ],
			[ 'usc_id' => $id ],
			__METHOD__
		);

		self::purgeCdnId( $id );

		return true;
	}

	/**
	 * Mark a URL as undeleted
	 *
	 * @param string $shortcode
	 * @return bool False if the $shortCode was invalid
	 */
	public static function restoreURL( string $shortcode ): bool {
		$id = self::decodeId( $shortcode );
		if ( $id === false ) {
			return false;
		}

		$dbw = self::getDB( DB_PRIMARY );
		$dbw->update(
			'urlshortcodes',
			[ 'usc_deleted' => 0 ],
			[ 'usc_id' => $id ],
			__METHOD__
		);

		self::purgeCdnId( $id );

		return true;
	}

	/**
	 * Compute the Cartesian product of a list of sets
	 *
	 * @param array[] $sets List of sets
	 * @return array[]
	 */
	public static function cartesianProduct( array $sets ): array {
		if ( !$sets ) {
			return [ [] ];
		}

		$set = array_shift( $sets );
		$productSet = self::cartesianProduct( $sets );

		$result = [];
		foreach ( $set as $val ) {
			foreach ( $productSet as $p ) {
				array_unshift( $p, $val );
				$result[] = $p;
			}
		}

		return $result;
	}

	/**
	 * Compute all shortcode variants by expanding wgUrlShortenerIdMapping
	 *
	 * @param string $shortcode
	 * @return string[]
	 */
	public static function getShortcodeVariants( string $shortcode ): array {
		global $wgUrlShortenerIdMapping;

		// Reverse the character alias mapping
		$targetToVariants = [];
		foreach ( $wgUrlShortenerIdMapping as $variant => $target ) {
			$targetToVariants[ $target ] = $targetToVariants[ $target ] ?? [];
			$targetToVariants[ $target ][] = (string)$variant;
		}

		// Build a set for each character of possible variants
		$sets = [];
		$chars = str_split( $shortcode );
		foreach ( $chars as $char ) {
			$set = $targetToVariants[ $char ] ?? [];
			$set[] = $char;
			$sets[] = $set;
		}

		// Cartesian product to get all combinations
		$productSet = self::cartesianProduct( $sets );

		// Flatten to strings
		return array_map( static function ( $set ) {
			return implode( '', $set );
		}, $productSet );
	}

	/**
	 * If configured, purge CDN for the given ID
	 *
	 * @param int $id
	 */
	public static function purgeCdnId( int $id ): void {
		global $wgUseCdn;
		if ( $wgUseCdn ) {
			$codes = array_merge(
				self::getShortcodeVariants( self::encodeId( $id ) ),
				self::getShortcodeVariants( self::encodeId( $id, true ) )
			);
			foreach ( $codes as $code ) {
				self::purgeCdn( $code );
			}
		}
	}

	/**
	 * If configured, purge CDN for the given shortcode
	 *
	 * @param string $shortcode
	 */
	private static function purgeCdn( string $shortcode ): void {
		global $wgUseCdn;
		if ( $wgUseCdn ) {
			$update = new CdnCacheUpdate( [ self::makeUrl( $shortcode ) ] );
			DeferredUpdates::addUpdate( $update, DeferredUpdates::PRESEND );
		}
	}

	/**
	 * @param int $type DB_REPLICA or DB_PRIMARY
	 * @return IDatabase
	 */
	public static function getDB( int $type ): IDatabase {
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
	public static function makeUrl( string $shortCode ): string {
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
		return wfExpandUrl( $url );
	}

	/**
	 * Coalesce the regex of allowed domains into a single string regex.
	 *
	 * @return string Regex of allowed domains
	 */
	public static function getAllowedDomainsRegex(): string {
		global $wgUrlShortenerAllowedDomains, $wgServer;
		if ( $wgUrlShortenerAllowedDomains === false ) {
			// Allowed Domains not configured, default to wgServer
			$serverParts = wfParseUrl( $wgServer );
			$allowedDomains = preg_quote( $serverParts['host'], '/' );
		} else {
			// Collapse the allowed domains into a single string, so we have to run regex check only once
			$allowedDomains = implode( '|', array_map(
				static function ( $item ) {
					return '^' . $item . '$';
				},
				$wgUrlShortenerAllowedDomains
			) );
		}

		return $allowedDomains;
	}

	/**
	 * Validates a given URL to see if it is allowed to be used to create a short URL
	 *
	 * @param string $url Url to Validate
	 * @return bool|Message true if it is valid, or error Message object if invalid
	 */
	public static function validateUrl( string $url ) {
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

			if ( preg_match( '/' . self::getAllowedDomainsRegex() . '/', $domain ) === 1 ) {
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
	 * @param bool $alt Provide an alternate string representation
	 * @return string
	 */
	public static function encodeId( int $x, bool $alt = false ): string {
		global $wgUrlShortenerIdSet, $wgUrlShortenerAltPrefix;
		$s = '';
		$n = strlen( $wgUrlShortenerIdSet );
		while ( $x ) {
			$remainder = $x % $n;
			$x = ( $x - $remainder ) / $n;
			$s = $wgUrlShortenerIdSet[$alt ? $n - 1 - $remainder : $remainder] . $s;
		}
		return $alt ? $wgUrlShortenerAltPrefix . $s : $s;
	}

	/**
	 * Decode a compact string to produce an integer, or false if the input is invalid.
	 *
	 * @param string $s
	 * @return int|false
	 */
	public static function decodeId( string $s ) {
		global $wgUrlShortenerIdSet, $wgUrlShortenerIdMapping, $wgUrlShortenerAltPrefix;

		if ( $s === '' ) {
			return false;
		}

		$alt = false;
		if ( $s[0] === $wgUrlShortenerAltPrefix ) {
			$s = substr( $s, 1 );
			$alt = true;
		}

		$n = strlen( $wgUrlShortenerIdSet );
		if ( self::$decodeMap === null ) {
			self::$decodeMap = [];
			for ( $i = 0; $i < $n; $i++ ) {
				self::$decodeMap[$wgUrlShortenerIdSet[$i]] = $i;
			}
			foreach ( $wgUrlShortenerIdMapping as $k => $v ) {
				self::$decodeMap[$k] = self::$decodeMap[$v];
			}
		}

		$x = 0;
		for ( $i = 0, $len = strlen( $s ); $i < $len; $i++ ) {
			$x *= $n;
			if ( isset( self::$decodeMap[$s[$i]] ) ) {
				$val = self::$decodeMap[$s[$i]];
				$x += $alt ?
					$n - 1 - $val :
					$val;
			} else {
				return false;
			}
		}
		return $x;
	}
}
