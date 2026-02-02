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
use MediaWiki\Config\Config;
use MediaWiki\Deferred\CdnCacheUpdate;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use MediaWiki\Utils\UrlUtils;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

class UrlShortenerUtils {
	public function __construct(
		private readonly Config $config,
		private readonly IConnectionProvider $lbFactory,
		private readonly UrlUtils $urlUtils,
	) {
	}

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
	public function maybeCreateShortCode( string $url, User $user ): Status {
		$url = $this->normalizeUrl( $url );

		if ( $user->getBlock() ) {
			return Status::newFatal( 'urlshortener-blocked' );
		}

		if ( $this->config->get( 'UrlShortenerReadOnly' ) ) {
			// All code paths should already have checked for this,
			// but lets be on the safe side.
			return Status::newFatal( 'urlshortener-disabled' );
		}

		$urlSizeLimit = $this->config->get( 'UrlShortenerUrlSizeLimit' );
		if ( mb_strlen( $url ) > $urlSizeLimit ) {
			return Status::newFatal(
				wfMessage( 'urlshortener-url-too-long' )->numParams( $urlSizeLimit )
			);
		}

		if ( $user->pingLimiter( 'urlshortcode' ) ) {
			return Status::newFatal( 'urlshortener-ratelimit' );
		}

		$dbr = $this->getReplicaDB();
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
				'url' => $this->encodeId( $row->usc_id ),
				'alt' => $this->encodeId( $row->usc_id, true )
			] );
		}

		$dbw = $this->getPrimaryDB();
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
		$this->purgeCdnId( $id );

		return Status::newGood( [
			'url' => $this->encodeId( $id ),
			'alt' => $this->encodeId( $id, true )
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
	public function normalizeUrl( string $url ): string {
		// First, force the protocol to HTTP, we'll convert
		// it to a different one when redirecting
		$url = $this->convertToProtocol( $url, PROTO_HTTP );

		$url = trim( $url );

		// TODO: We should ideally decode/encode the URL for normalization,
		// but we don't want to double-encode, nor unencode the URL that
		// is directly provided by users (see test cases)
		// So for now, just replace spaces with %20, as that's safe in all cases
		$url = str_replace( ' ', '%20', $url );

		// If the wiki is using an article path (e.g. /wiki/$1) try
		// and convert plain index.php?title=$1 URLs to the canonical form
		$parsed = $this->urlUtils->parse( $url );
		if ( !isset( $parsed['path'] ) ) {
			// T220718: Ensure each URL has a / after the domain name
			$parsed['path'] = '/';
		}
		$articlePath = $this->config->get( MainConfigNames::ArticlePath );
		if ( $articlePath !== false && isset( $parsed['query'] ) ) {
			$query = wfCgiToArray( $parsed['query'] );
			if ( count( $query ) === 1 && isset( $query['title'] ) &&
				$parsed['path'] === $this->config->get( MainConfigNames::Script )
			) {
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
	public function convertToProtocol( string $url, $proto = PROTO_RELATIVE ): string {
		$parsed = $this->urlUtils->parse( $url ) ?? [];
		unset( $parsed['scheme'] );
		$parsed['delimiter'] = '//';

		return $this->urlUtils->expand( UrlUtils::assemble( $parsed ), $proto ) ?? '';
	}

	/**
	 * Retrieves a URL for the given shortcode, or null if there's none.
	 *
	 * @param string $shortCode
	 * @param string|int|null $proto PROTO_* constant
	 * @return string|null
	 */
	public function getURL( string $shortCode, $proto = PROTO_RELATIVE ): ?string {
		$id = $this->decodeId( $shortCode );
		if ( $id === null ) {
			return null;
		}

		$dbr = $this->getReplicaDB();
		$url = $dbr->newSelectQueryBuilder()
			->select( 'usc_url' )
			->from( 'urlshortcodes' )
			->where( [ 'usc_id' => $id, 'usc_deleted' => 0 ] )
			->caller( __METHOD__ )->fetchField();

		if ( $url === false ) {
			return null;
		}

		return $this->convertToProtocol( $url, $proto );
	}

	/**
	 * Whether a URL is deleted or not
	 *
	 * @param string $shortCode
	 * @return bool
	 */
	public function isURLDeleted( string $shortCode ): bool {
		$id = $this->decodeId( $shortCode );
		if ( $id === null ) {
			return false;
		}

		$dbr = $this->getReplicaDB();
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
	public function deleteURL( string $shortcode ): bool {
		$id = $this->decodeId( $shortcode );
		if ( $id === null ) {
			return false;
		}

		$dbw = $this->getPrimaryDB();
		$dbw->newUpdateQueryBuilder()
			->update( 'urlshortcodes' )
			->set( [ 'usc_deleted' => 1 ] )
			->where( [ 'usc_id' => $id ] )
			->caller( __METHOD__ )->execute();

		$this->purgeCdnId( $id );

		return true;
	}

	/**
	 * Mark a URL as undeleted
	 *
	 * @param string $shortcode
	 * @return bool False if the $shortCode was invalid
	 */
	public function restoreURL( string $shortcode ): bool {
		$id = $this->decodeId( $shortcode );
		if ( $id === null ) {
			return false;
		}

		$dbw = $this->getPrimaryDB();
		$dbw->newUpdateQueryBuilder()
			->update( 'urlshortcodes' )
			->set( [ 'usc_deleted' => 0 ] )
			->where( [ 'usc_id' => $id ] )
			->caller( __METHOD__ )->execute();

		$this->purgeCdnId( $id );

		return true;
	}

	/**
	 * Compute the Cartesian product of a list of sets
	 *
	 * @param array[] $sets List of sets
	 * @return array[]
	 */
	public function cartesianProduct( array $sets ): array {
		if ( !$sets ) {
			return [ [] ];
		}

		$set = array_shift( $sets );
		$productSet = $this->cartesianProduct( $sets );

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
	public function getShortcodeVariants( string $shortcode ): array {
		$idMapping = $this->config->get( 'UrlShortenerIdMapping' );

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
		$productSet = $this->cartesianProduct( $sets );

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
	public function purgeCdnId( int $id ): void {
		if ( $this->config->get( MainConfigNames::UseCdn ) ) {
			$codes = array_merge(
				$this->getShortcodeVariants( $this->encodeId( $id ) ),
				$this->getShortcodeVariants( $this->encodeId( $id, true ) )
			);
			foreach ( $codes as $code ) {
				$this->purgeCdn( $code );
			}
		}
	}

	/**
	 * If configured, purge CDN for the given shortcode
	 *
	 * @param string $shortcode
	 */
	private function purgeCdn( string $shortcode ): void {
		if ( $this->config->get( MainConfigNames::UseCdn ) ) {
			$update = new CdnCacheUpdate( [ $this->makeUrl( $shortcode ) ] );
			DeferredUpdates::addUpdate( $update, DeferredUpdates::PRESEND );
		}
	}

	public function getPrimaryDB(): IDatabase {
		return $this->lbFactory
			->getPrimaryDatabase( 'virtual-urlshortener' );
	}

	public function getReplicaDB(): IReadableDatabase {
		return $this->lbFactory
			->getReplicaDatabase( 'virtual-urlshortener' );
	}

	/**
	 * Create a fully qualified short URL for the given shortcode.
	 *
	 * @param string $shortCode base64 shortcode to generate URL For.
	 * @return string The fully qualified URL
	 */
	public function makeUrl( string $shortCode ): string {
		$server = $this->config->get( 'UrlShortenerServer' );
		if ( $server === false ) {
			$server = $this->config->get( MainConfigNames::Server );
		}

		$template = $this->config->get( 'UrlShortenerTemplate' );
		if ( !is_string( $template ) ) {
			$urlTemplate = SpecialPage::getTitleFor( 'UrlRedirector', '$1' )->getFullUrl();
		} else {
			$urlTemplate = $server . $template;
		}

		$url = str_replace( '$1', $shortCode, $urlTemplate );

		// Make sure the URL is fully qualified
		return $this->urlUtils->expand( $url ) ?? '';
	}

	/**
	 * Coalesce the regex of allowed domains into a single string regex.
	 *
	 * @return string Regex of allowed domains
	 */
	public function getAllowedDomainsRegex(): string {
		$allowedDomains = $this->config->get( 'UrlShortenerAllowedDomains' );
		if ( $allowedDomains === false ) {
			// Allowed Domains not configured, default to wgServer
			$serverParts = $this->urlUtils->parse( $this->config->get( MainConfigNames::Server ) ) ?? [];
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
	 * NOTE: Keep in sync with ext.urlShortener.special.js
	 *
	 * @param string $url Url to Validate
	 * @return bool|Message true if it is valid, or error Message object if invalid
	 */
	public function validateUrl( string $url ) {
		$urlParts = $this->urlUtils->parse( $url );
		if ( $urlParts === null ) {
			return wfMessage( 'urlshortener-error-malformed-url' );
		} else {
			$allowArbitraryPorts = $this->config
				->get( 'UrlShortenerAllowArbitraryPorts' );
			if ( isset( $urlParts['port'] ) && !$allowArbitraryPorts ) {
				$wikiServerParts = $this->urlUtils->parse( $this->urlUtils->getCanonicalServer() );
				if ( $urlParts['port'] === 80 || $urlParts['port'] === 443 ) {
					unset( $urlParts['port'] );
				} elseif ( !(
					// Always allow shortening of $wgCanonicalServer,
					// especially in local development where it tends to contain a port
					isset( $wikiServerParts['port'] )
						&& $urlParts['host'] === $wikiServerParts['host']
						&& $urlParts['port'] === $wikiServerParts['port']
				) ) {
					return wfMessage( 'urlshortener-error-badports' );
				}
			}

			if ( isset( $urlParts['user'] ) || isset( $urlParts['pass'] ) ) {
				return wfMessage( 'urlshortener-error-nouserpass' );
			}

			$domain = $urlParts['host'];

			if ( preg_match( '/' . $this->getAllowedDomainsRegex() . '/', $domain ) === 1 ) {
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
	public function encodeId( int $x, bool $alt = false ): string {
		$idSet = $this->config->get( 'UrlShortenerIdSet' );
		$s = '';
		$n = strlen( $idSet );
		while ( $x ) {
			$remainder = $x % $n;
			$x = ( $x - $remainder ) / $n;
			$s = $idSet[$alt ? $n - 1 - $remainder : $remainder] . $s;
		}
		return $alt ? $this->config->get( 'UrlShortenerAltPrefix' ) . $s : $s;
	}

	/**
	 * Decode a compact string to produce an integer, or null if the input is invalid.
	 *
	 * @param string $s
	 * @return int|null
	 */
	public function decodeId( string $s ): ?int {
		// Very basic overflow protection against non-sensical input
		if ( $s === '' || strlen( $s ) > 20 ) {
			return null;
		}

		$altPrefix = $this->config->get( 'UrlShortenerAltPrefix' );
		$alt = false;
		if ( $s[0] === $altPrefix ) {
			$s = substr( $s, 1 );
			$alt = true;
		}

		$idSet = $this->config->get( 'UrlShortenerIdSet' );
		$n = strlen( $idSet );
		if ( self::$decodeMap === null ) {
			self::$decodeMap = [];
			for ( $i = 0; $i < $n; $i++ ) {
				self::$decodeMap[$idSet[$i]] = $i;
			}
			foreach ( $this->config->get( 'UrlShortenerIdMapping' ) as $k => $v ) {
				self::$decodeMap[$k] = self::$decodeMap[$v];
			}
		}

		$x = 0;
		for ( $i = 0, $len = strlen( $s ); $i < $len; $i++ ) {
			$x *= $n;
			// Overflow protection when the calculation exceeded PHP's int range
			if ( !isset( self::$decodeMap[$s[$i]] ) || !is_int( $x ) ) {
				return null;
			}
			$val = self::$decodeMap[$s[$i]];
			$x += $alt ?
				$n - 1 - $val :
				$val;
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
	public function shouldShortenUrl( bool $qrCode, string $url, int $limit ): bool {
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
	public function getQrCode( string $url, int $limit, User $user, bool $dataUri = false ): Status {
		$shortUrlCode = null;
		$shortUrlCodeAlt = null;
		if ( $this->shouldShortenUrl( true, $url, $limit ) ) {
			$status = $this->maybeCreateShortCode( $url, $user );
			if ( !$status->isOK() ) {
				return $status;
			}
			$shortUrlCode = $status->getValue()['url'];
			$shortUrlCodeAlt = $status->getValue()['alt'];
			$url = $this->makeUrl( $shortUrlCode );
		} else {
			$url = $this->normalizeUrl( $url );
		}
		$qrCode = $this->getQrCodeInternal( $url );
		$res = [
			'qrcode' => $dataUri ? $qrCode->getDataUri() : $qrCode->getString(),
		];
		if ( $shortUrlCode ) {
			$res['url'] = $shortUrlCode;
			$res['alt'] = $shortUrlCodeAlt;
		}
		return Status::newGood( $res );
	}

	private function getQrCodeInternal( string $url ): ResultInterface {
		return ( new Builder(
			writer: new SvgWriter(),
			data: $url,
			encoding: new Encoding( 'UTF-8' ),
			size: 300,
			margin: 10,
		) )->build();
	}
}
