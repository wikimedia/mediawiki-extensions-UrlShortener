const { UrlShortener } = require( 'ext.urlShortener.special' );

QUnit.module( 'ext.urlShortener.special', ( hooks ) => {
	hooks.beforeEach( () => {
		sinon.replace( mw.config, 'values', {
			// See UrlShortenerUtils::getAllowedDomainsRegex
			wgUrlShortenerAllowedDomains: 'example.org',
			wgUrlShortenerAllowArbitraryPorts: false,
			wgUrlShortenerEnableQrCode: false,
			wgCanonicalSpecialPageName: false
		} );
	} );

	QUnit.test.each( 'UrlShortener.validateInput [default]', eachEntry( {
		'http://example.org/test': [ true ],
		'https://example.org/test': [ true ],
		'https://en.wikipedia.org/test': [ false, '(urlshortener-error-disallowed-url)' ],
		'http://localhost/test': [ false, '(urlshortener-error-disallowed-url)' ],
		'http://localhost:4000/test': [ false, '(urlshortener-error-disallowed-url)' ],
		'http://example.org.imposter.invalid/test': [ true ], // FIXME
		'http://imposter-example.org/test': [ true ], // FIXME
		'http://admin.example.org/test': [ true ], // FIXME
		'http://example.org:8080/test': [ false, '(urlshortener-error-badports)' ]
	} ), ( assert, [ url, [ expectRet, expectError = null ] ] ) => {
		const shortener = new UrlShortener();
		shortener.fieldLayout = {
			setErrors( errors ) {
				this.errors = errors;
			}
		};
		assert.strictEqual( shortener.validateInput( url ), expectRet, 'return' );

		const actualErr = shortener.fieldLayout.errors?.[ 0 ] || null;
		assert.strictEqual( actualErr, expectError, 'error' );
	} );

	QUnit.test.each( 'UrlShortener.validateInput [wikipedia]', eachEntry( {
		'http://en.wikipedia.org/test': [ true ],
		'https://en.wikipedia.org/test': [ true ],
		'http://en.wikipedia.org:80/test': [ true ],
		'https://en.wikipedia.org:443/test': [ true ],
		'http://en.wikipedia.org:8080/test': [ false, '(urlshortener-error-badports)' ],
		'https://en.wikipedia.org:4000/test': [ false, '(urlshortener-error-badports)' ],
		'http://example.org/test': [ false, '(urlshortener-error-disallowed-url)' ],
		'https://example.org/test': [ false, '(urlshortener-error-disallowed-url)' ],
		'http://localhost/test': [ false, '(urlshortener-error-disallowed-url)' ],
		'http://localhost:4000/test': [ false, '(urlshortener-error-disallowed-url)' ],
		'http://en.wikipedia.org.imposter.invalid/test': [ false, '(urlshortener-error-disallowed-url)' ]
	} ), ( assert, [ url, [ expectRet, expectError = null ] ] ) => {
		mw.config.set( {
			wgUrlShortenerAllowedDomains: '^(.*\\.)?wikipedia\\.org$|^(.*\\.)?wikimedia\\.org$'
		} );

		const shortener = new UrlShortener();
		shortener.fieldLayout = {
			setErrors( errors ) {
				this.errors = errors;
			}
		};
		assert.strictEqual( shortener.validateInput( url ), expectRet, 'return' );

		const actualErr = shortener.fieldLayout.errors?.[ 0 ] || null;
		assert.strictEqual( actualErr, expectError, 'error' );
	} );

	// https://github.com/qunitjs/qunit/issues/1764
	function eachEntry( dataset ) {
		return Object.entries( dataset ).reduce( ( obj, [ key, val ] ) => {
			obj[ key ] = [ key, val ];
			return obj;
		}, {} );
	}

} );
