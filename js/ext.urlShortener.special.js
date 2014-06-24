(function ( mw, $ ) {
	function UrlShortener() {
	}

	/**
	 * Initialize the UrlShortener!
	 *
	 * Primarily sets up event handlers
	 */
	UrlShortener.prototype.init = function () {
		$( '#mwe-urlshortener-url-submit' ).click( $.proxy( this.onSubmit, this ) );
		$( '#mwe-urlshortener-shorturl-display' ).click( function () {
			selectElement( this );
		} );
		$( '#mwe-urlshortener-url-input' ).tipsy( {
			gravity: 'n',
			fade: true,
			tigger: 'manual',
			className: 'mwe-urlshortener-tipsy'
		} );
		this.api = new mw.Api();
	};

	/**
	 * Event Handler for the 'shorten' button
	 */
	UrlShortener.prototype.onSubmit = function () {
		$( '#mwe-urlshortener-form-error' ).hide();
		this.shortenUrl(
			this.getLongUrl()
		).done( function ( shorturl ) {
				$( "#mwe-urlshortener-form-footer" ).show();
				// The selectElement() call makes the text selected, so the user can just ctrl-C it
				$( '#mwe-urlshortener-url-input' )
					.attr( 'title', '' )
					.attr( 'original-title', '' );
				selectElement( $( "#mwe-urlshortener-shorturl-display" ).text( shorturl )[0] );
			} ).fail( function ( err ) {
				$( '#mwe-urlshortener-form-footer' ).hide();
				$( '#mwe-urlshortener-url-input' )
					.attr( 'title', err.info )
					.tipsy( 'show' );
			} );
		return false;
	};

	/**
	 * Returns the current user specified long url that we need to shorten
	 *
	 * @returns String The long URL that the user has specified
	 */
	UrlShortener.prototype.getLongUrl = function () {
		return $( '#mwe-urlshortener-url-input' ).val();
	};

	/**
	 * Shorten a given url by making an API request
	 *
	 * @param url Url to shorten
	 * @returns jQuery.Promise A deferred object that resolves
	 *                         with the short url on success or
	 *                         fails with an error object on failure
	 */
	UrlShortener.prototype.shortenUrl = function ( url ) {
		var d = new $.Deferred(),
			validate = this.validateInput( url );
		if ( validate === true ) {
			this.api.get( {
				action: 'shortenurl',
				url: url
			} ).done( function ( data ) {
				d.resolve( data.shortenurl.shorturl );
			} ).fail( function ( errCode, data ) {
				d.reject( data.error );
			} );

		} else {
			d.reject( validate );
		}
		return d.promise();
	};

	/**
	 * Validate the input URL clientside. Note that this is not the
	 * only check - they are checked serverside too.
	 *
	 * Checks for both URL validity and whitelist matching.
	 *
	 * @param input String the URL that is to be shortened
	 * @returns Boolean|Object true if object is validated, an object matching what is
	 *                         returned by the API in case of error.
	 */
	UrlShortener.prototype.validateInput = function( input ) {
		var parsed;
		try {
			parsed = new mw.Uri( input );
		} catch ( e ) {
			return {
				code: 'urlshortener-error-malformed-url',
				info: mw.msg( 'urlshortener-error-malformed-url' )
			};
		}
		if ( parsed.host.match( new RegExp( mw.config.get( 'wgUrlShortenerDomainsWhitelist' ) ) ) ) {
			return true;
		} else {
			return {
				code: 'urlshortener-error-disallowed-url',
				info: mw.msg( 'urlshortener-error-disallowed-url', parsed.host )
			};
		}
	};

	/**
	 * Method to 'select' an element, which is equivalent to the user
	 * clicking and dragging over it.
	 *
	 * Contains code from http://stackoverflow.com/a/987376
	 * @param element Element the element to select
	 */
	function selectElement( element ) {
		var range, selection;
		if ( document.body.createTextRange ) { //ms
			range = document.body.createTextRange();
			range.moveToElementText( element );
			range.select();
		} else if ( window.getSelection ) { //all others
			selection = window.getSelection();
			range = document.createRange();
			range.selectNodeContents( element );
			selection.removeAllRanges();
			selection.addRange( range );
		}
	}

	$( function () {
		var us = new UrlShortener();
		us.init();
	} )
})( mediaWiki, jQuery );
