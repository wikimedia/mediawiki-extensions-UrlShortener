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

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\Writer\Result\ResultInterface;
use Endroid\QrCode\Writer\SvgWriter;
use MediaWiki\Deferred\CdnCacheUpdate;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use MediaWiki\Utils\UrlUtils;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

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

	/** @var int[] */
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
		$url = self::normalizeUrl( $url );

		if ( $user->getBlock() ) {
			return Status::newFatal( 'urlshortener-blocked' );
		}

		$config = MediaWikiServices::getInstance()->getMainConfig();
		if ( $config->get( 'UrlShortenerReadOnly' ) ) {
			// All code paths should already have checked for this,
			// but lets be on the safe side.
			return Status::newFatal( 'urlshortener-disabled' );
		}

		$urlSizeLimit = $config->get( 'UrlShortenerUrlSizeLimit' );
		if ( mb_strlen( $url ) > $urlSizeLimit ) {
			return Status::newFatal(
				wfMessage( 'urlshortener-url-too-long' )->numParams( $urlSizeLimit )
			);
		}

		if ( $user->pingLimiter( 'urlshortcode' ) ) {
			return Status::newFatal( 'urlshortener-ratelimit' );
		}

		$dbr = self::getReplicaDB();
		$row = $dbr->newSelectQueryBuilder()
			->select( [ 'usc_id', 'usc_deleted' ] )
			->from( 'urlshortcodes' )
			->where( [ 'usc_url_hash' => md5( $url ) ] )
			->caller( __METHOD__ )->fetchRow();
		if ( $row !== false ) {
			if ( $row->usc_deleted ) {
				return Status::newFatal( 'urlshortener-deleted' );
			}
			return Status::newGood( [
				'url' => self::encodeId( $row->usc_id ),
				'alt' => self::encodeId( $row->usc_id, true )
			] );
		}

		$dbw = self::getPrimaryDB();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'urlshortcodes' )
			->ignore()
			->row( [ 'usc_url' => $url, 'usc_url_hash' => md5( $url ) ] )
			->caller( __METHOD__ )->execute();

		if ( $dbw->affectedRows() ) {
			$id = $dbw->insertId();
		} else {
			// Raced out; get the winning ID
			$id = $dbw->newSelectQueryBuilder()
				->select( 'usc_id' )
				// ignore snapshot
				->lockInShareMode()
				->from( 'urlshortcodes' )
				->where( [ 'usc_url_hash' => md5( $url ) ] )
				->caller( __METHOD__ )->fetchField();
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
		$articlePath = MediaWikiServices::getInstance()->getMainConfig()->get( MainConfigNames::ArticlePath );
		if ( $articlePath !== false && isset( $parsed['query'] ) ) {
			$query = wfCgiToArray( $parsed['query'] );
			if ( count( $query ) === 1 && isset( $query['title'] ) && $parsed['path'] === wfScript() ) {
				$parsed['path'] = str_replace( '$1', $query['title'], $articlePath );
				unset( $parsed['query'] );
			}
		}
		$url = UrlUtils::assemble( $parsed );

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

		$dbr = self::getReplicaDB();
		$url = $dbr->newSelectQueryBuilder()
			->select( 'usc_url' )
			->from( 'urlshortcodes' )
			->where( [ 'usc_id' => $id, 'usc_deleted' => 0 ] )
			->caller( __METHOD__ )->fetchField();

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

		$dbr = self::getReplicaDB();
		$url = $dbr->newSelectQueryBuilder()
			->select( 'usc_url' )
			->from( 'urlshortcodes' )
			->where( [ 'usc_id' => $id, 'usc_deleted' => 1 ] )
			->caller( __METHOD__ )->fetchField();

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

		$dbw = self::getPrimaryDB();
		$dbw->newUpdateQueryBuilder()
			->update( 'urlshortcodes' )
			->set( [ 'usc_deleted' => 1 ] )
			->where( [ 'usc_id' => $id ] )
			->caller( __METHOD__ )->execute();

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

		$dbw = self::getPrimaryDB();
		$dbw->newUpdateQueryBuilder()
			->update( 'urlshortcodes' )
			->set( [ 'usc_deleted' => 0 ] )
			->where( [ 'usc_id' => $id ] )
			->caller( __METHOD__ )->execute();

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
		$idMapping = MediaWikiServices::getInstance()->getMainConfig()->get( 'UrlShortenerIdMapping' );

		// Reverse the character alias mapping
		$targetToVariants = [];
		foreach ( $idMapping as $variant => $target ) {
			$targetToVariants[ $target ] ??= [];
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
		if ( MediaWikiServices::getInstance()->getMainConfig()->get( MainConfigNames::UseCdn ) ) {
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
		if ( MediaWikiServices::getInstance()->getMainConfig()->get( MainConfigNames::UseCdn ) ) {
			$update = new CdnCacheUpdate( [ self::makeUrl( $shortcode ) ] );
			DeferredUpdates::addUpdate( $update, DeferredUpdates::PRESEND );
		}
	}

	public static function getPrimaryDB(): IDatabase {
		return MediaWikiServices::getInstance()
			->getDBLoadBalancerFactory()
			->getPrimaryDatabase( 'virtual-urlshortener' );
	}

	public static function getReplicaDB(): IReadableDatabase {
		return MediaWikiServices::getInstance()
			->getDBLoadBalancerFactory()
			->getReplicaDatabase( 'virtual-urlshortener' );
	}

	/**
	 * Create a fully qualified short URL for the given shortcode.
	 *
	 * @param string $shortCode base64 shortcode to generate URL For.
	 * @return string The fully qualified URL
	 */
	public static function makeUrl( string $shortCode ): string {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$server = $config->get( 'UrlShortenerServer' );
		if ( $server === false ) {
			$server = $config->get( MainConfigNames::Server );
		}

		$template = $config->get( 'UrlShortenerTemplate' );
		if ( !is_string( $template ) ) {
			$urlTemplate = SpecialPage::getTitleFor( 'UrlRedirector', '$1' )->getFullUrl();
		} else {
			$urlTemplate = $server . $template;
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
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$allowedDomains = $config->get( 'UrlShortenerAllowedDomains' );
		if ( $allowedDomains === false ) {
			// Allowed Domains not configured, default to wgServer
			$serverParts = wfParseUrl( $config->get( MainConfigNames::Server ) );
			return preg_quote( $serverParts['host'], '/' );
		}
		// Collapse the allowed domains into a single string, so we have to run regex check only once
		return implode( '|', array_map(
			static function ( $item ) {
				return '^' . $item . '$';
			},
			$allowedDomains
		) );
	}

	/**
	 * Validates a given URL to see if it is allowed to be used to create a short URL
	 *
	 * @param string $url Url to Validate
	 * @return bool|Message true if it is valid, or error Message object if invalid
	 */
	public static function validateUrl( string $url ) {
		$urlParts = wfParseUrl( $url );
		if ( $urlParts === false ) {
			return wfMessage( 'urlshortener-error-malformed-url' );
		} else {
			$allowArbitraryPorts = MediaWikiServices::getInstance()->getMainConfig()
				->get( 'UrlShortenerAllowArbitraryPorts' );
			if ( isset( $urlParts['port'] ) && !$allowArbitraryPorts ) {
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
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$idSet = $config->get( 'UrlShortenerIdSet' );
		$s = '';
		$n = strlen( $idSet );
		while ( $x ) {
			$remainder = $x % $n;
			$x = ( $x - $remainder ) / $n;
			$s = $idSet[$alt ? $n - 1 - $remainder : $remainder] . $s;
		}
		return $alt ? $config->get( 'UrlShortenerAltPrefix' ) . $s : $s;
	}

	/**
	 * Decode a compact string to produce an integer, or false if the input is invalid.
	 *
	 * @param string $s
	 * @return int|false
	 */
	public static function decodeId( string $s ) {
		if ( $s === '' ) {
			return false;
		}

		$config = MediaWikiServices::getInstance()->getMainConfig();
		$altPrefix = $config->get( 'UrlShortenerAltPrefix' );
		$alt = false;
		if ( $s[0] === $altPrefix ) {
			$s = substr( $s, 1 );
			$alt = true;
		}

		$idSet = $config->get( 'UrlShortenerIdSet' );
		$n = strlen( $idSet );
		if ( self::$decodeMap === null ) {
			self::$decodeMap = [];
			for ( $i = 0; $i < $n; $i++ ) {
				self::$decodeMap[$idSet[$i]] = $i;
			}
			foreach ( $config->get( 'UrlShortenerIdMapping' ) as $k => $v ) {
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

	/**
	 * Given the context of whether we want a QR code, should the URL be shortened?
	 *
	 * @param bool $qrCode
	 * @param string $url
	 * @param int $limit The value of $wgUrlShortenerQrCodeShortenLimit
	 * @return bool
	 */
	public static function shouldShortenUrl( bool $qrCode, string $url, int $limit ): bool {
		return !$qrCode || strlen( $url ) > $limit;
	}

	/**
	 * Build a QR code for the given URL. If the URL is longer than $limit in bytes,
	 * it will first be shortened to prevent the QR code density from being too high.
	 *
	 * @param string $url
	 * @param int $limit The value of $wgUrlShortenerQrCodeShortenLimit
	 * @param User $user User requesting the url, for rate limiting
	 * @param bool $dataUri Return 'qrcode' as a data URI instead of XML.
	 * @return Status Status with 'qrcode' (XML of the SVG) and if applicable, the shortened 'url' and 'alt'.
	 */
	public static function getQrCode( string $url, int $limit, User $user, bool $dataUri = false ): Status {
		$shortUrlCode = null;
		$shortUrlCodeAlt = null;
		if ( self::shouldShortenUrl( true, $url, $limit ) ) {
			$status = self::maybeCreateShortCode( $url, $user );
			if ( !$status->isOK() ) {
				return $status;
			}
			$shortUrlCode = $status->getValue()['url'];
			$shortUrlCodeAlt = $status->getValue()['alt'];
			$url = self::makeUrl( $shortUrlCode );
		} else {
			$url = self::normalizeUrl( $url );
		}
		$qrCode = self::getQrCodeInternal( $url );
		$res = [
			'qrcode' => $dataUri ? $qrCode->getDataUri() : $qrCode->getString(),
		];
		if ( $shortUrlCode ) {
			$res['url'] = $shortUrlCode;
			$res['alt'] = $shortUrlCodeAlt;
		}
		return Status::newGood( $res );
	}

	private static function getQrCodeInternal( string $url ): ResultInterface {
		return Builder::create()
			->writer( new SvgWriter() )
			->writerOptions( [] )
			->data( $url )
			->encoding( new Encoding( 'UTF-8' ) )
			->size( 300 )
			->margin( 10 )
			->build();
	}
}
