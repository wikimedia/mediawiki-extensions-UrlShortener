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
		$( '#mwe-urlshortener-url-shorturl-display' ).click( function () {
			selectElement( this );
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
				selectElement( $( "#mwe-urlshortener-url-shorturl-display" ).text( shorturl )[0] );
			} ).fail( function ( err ) {
				$( '#mwe-urlshortener-form-footer' ).hide();
				$( '#mwe-urlshortener-form-error' ).text( err.info ).show();
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
		var d = new $.Deferred();
		this.api.get( {
			action: 'shortenurl',
			url: url
		} ).done( function ( data ) {
			d.resolve( data.shortenurl.shorturl );
		} ).fail( function ( errCode, data ) {
			d.reject( data.error );
		} );
		return d.promise();
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
