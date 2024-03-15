class UrlShortener {

	constructor() {
		this.api = new mw.Api();
		// Defined server-side using UrlShortenerUtils::getAllowedDomainsRegex

		this.regex = new RegExp( mw.config.get( 'wgUrlShortenerAllowedDomains' ) );
		this.allowArbitraryPorts = mw.config.get( 'wgUrlShortenerAllowArbitraryPorts' );
		this.enableQrCode = !!mw.config.get( 'wgUrlShortenerEnableQrCode' );
		/** @type {OO.ui.FieldLayout} */
		this.fieldLayout = null;
		/** @type {OO.ui.TextInputWidget} */
		this.input = null;
		/** @type {OO.ui.CopyTextLayout} */
		this.shortened = null;
		/** @type {OO.ui.ButtonInputWidget} */
		this.submit = null;
		/** @type {jQuery} */
		this.$qrCodeImage = null;
		/** @type {OO.ui.ButtonWidget} */
		this.qrCodeDownloadButton = null;
	}

	/**
	 * Validate the input URL clientside.
	 *
	 * NOTE: This an optional enhancement for faster UI feedback.
	 * When in doubt, allow it here and let the server do the stricter check.
	 *
	 * NOTE: Keep in sync with UrlShortenerUtils.php#validateUrl
	 *
	 * Checks for both URL validity and AllowedDomains matching.
	 *
	 * @param {string} input The URL that is to be shortened
	 * @return {boolean|Object} True if object is validated, an object matching what is
	 *  returned by the API in case of error.
	 */
	validateInput( input ) {
		let url;

		try {
			url = new URL( input );
		} catch ( e ) {
			this.fieldLayout.setErrors( [ mw.msg( 'urlshortener-error-malformed-url' ) ] );
			return false;
		}

		if ( !url.hostname.match( this.regex ) ) {
			const origin = mw.html.escape( url.origin );
			this.fieldLayout.setErrors( [ mw.msg( 'urlshortener-error-disallowed-url', origin ) ] );
			return false;
		}

		if (
			url.port &&
			!this.allowArbitraryPorts &&
			!(
				url.port === '80' ||
				url.port === '443' ||
				url.hostname === mw.config.get( 'wgServerName' )
			)
		) {
			this.fieldLayout.setErrors( [ mw.msg( 'urlshortener-error-badports' ) ] );
			return false;
		}

		if ( url.username || url.password ) {
			this.fieldLayout.setErrors( [ mw.msg( 'urlshortener-error-nouserpass' ) ] );
			return false;
		}

		this.fieldLayout.setErrors( [] );
		return true;
	}

	shortUrlUiHandler( result ) {
		if ( !this.shortened ) {
			this.shortened = new mw.widgets.CopyTextLayout( {
				align: 'top',
				label: mw.msg( 'urlshortener-shortened-url-label' ),
				classes: [ 'ext-urlshortener-result' ],
				copyText: result.shorturl,
				help: mw.msg( 'urlshortener-shortened-url-alt' ),
				helpInline: true,
				successMessage: mw.msg( 'urlshortener-copy-success' ),
				failMessage: mw.msg( 'urlshortener-copy-fail' )
			} );
			this.$alt = $( '<a>' );
			this.shortened.$help.append( ' ', this.$alt );
			// Remove PHP result widget if present
			// eslint-disable-next-line no-jquery/no-global-selector
			$( '.ext-urlshortener-result' ).remove();
			// Wrap in a FieldLayout so we get the label
			this.fieldLayout.$element.after( this.shortened.$element );
		} else {
			this.shortened.textInput.setValue( result.shorturl );
		}

		this.$alt.attr( 'href', result.shorturlalt ).text( result.shorturlalt );
		this.$alt.off( 'click' ).on( 'click', ( e ) => {
			this.shortened.textInput.setValue( result.shorturlalt );
			this.shortened.onButtonClick();
			this.shortened.textInput.setValue( result.shorturl );
			this.$alt[ 0 ].focus();
			e.preventDefault();
		} );
		this.shortened.textInput.select();
	}

	/**
	 * Update the QR code in the UI, creating it if necessary.
	 *
	 * @param {string} [qrCodeSvg] SVG string. If not provided the existing QR code will be removed.
	 * @param {string} [url] The URL that was shortened. Used for the download filename.
	 */
	qrCodeUiHandler( qrCodeSvg, url ) {
		if ( !qrCodeSvg ) {
			// eslint-disable-next-line no-jquery/no-global-selector
			$( '.ext-urlshortener-qrcode-container' ).remove();
			this.$qrCodeImage = null;
			this.qrCodeDownloadButton = null;
			return;
		}

		if ( !this.$qrCodeImage ) {
			// Remove PHP result if present
			// eslint-disable-next-line no-jquery/no-global-selector
			$( '.ext-urlshortener-qrcode-container' ).remove();
			this.$qrCodeImage = $( '<img>' );
			this.qrCodeDownloadButton = new OO.ui.ButtonWidget( {
				icon: 'download',
				label: mw.msg( 'urlshortener-toolbox-qrcode' ),
				href: '.'
			} );
			// eslint-disable-next-line no-jquery/no-global-selector
			$( '.ext-urlshortener-container' ).append(
				$( '<div>' ).addClass( 'ext-urlshortener-qrcode-container' ).append(
					$( '<div>' ).addClass( 'ext-urlshortener-qrcode' ).append( this.$qrCodeImage ),
					this.qrCodeDownloadButton.$element
				)
			);
		}

		const filename = this.getFilenameFromUrl( url );

		const qrCodeUri = `data:image/svg+xml;charset=utf-8,${ encodeURIComponent( qrCodeSvg ) }`;

		this.$qrCodeImage.attr( 'src', qrCodeUri );
		this.qrCodeDownloadButton.$button.attr( {
			download: filename,
			// OOUI prefixes './' for security, so set the attribute directly
			href: qrCodeUri
		} );
	}

	/**
	 * Get a filename for the QR code download based on the URL.
	 *
	 * @param {string} url The URL that was shortened.
	 * @return {string} The filename for the QR code download.
	 */
	getFilenameFromUrl( url ) {
		const urlObj = new URL( url );
		const parts = urlObj.pathname.split( '/' ).filter( Boolean );
		if ( parts.length > 0 ) {
			return parts[ parts.length - 1 ] + '.svg';
		} else {
			// Use domain as filename if URL doesn't have a path
			return urlObj.hostname + '.svg';
		}
	}

	/**
	 * Click handler for the submit button
	 */
	onSubmit() {
		this.input.getValidity().then( () => {
			this.input.pushPending().setReadOnly( true );
			this.setSubmit( 'submitting' );
			const url = this.input.getValue();
			this.shortenUrl( url ).then( ( result ) => {
				if ( result.shorturl ) {
					this.shortUrlUiHandler( result );
				} else if ( this.shortened ) {
					// There's no `shorturl` in the response, so remove the shortened field.
					this.shortened.$element.remove();
					this.shortened = null;
				}
				this.qrCodeUiHandler( result.qrcode, url );
			}, ( err ) => {
				this.fieldLayout.setErrors( [ err.info ] );
			} ).always( () => {
				this.setSubmit( 'submit' );
				this.input.popPending().setReadOnly( false );
			} );
		} );
	}

	init() {
		// eslint-disable-next-line no-jquery/no-global-selector
		const $field = $( 'form > .mw-htmlform-field-HTMLTextFieldWithButton' );
		if ( $field.length ) {
			this.fieldLayout = OO.ui.infuse( $field );
			this.input = this.fieldLayout.fieldWidget;
			this.input.setValidation( this.validateInput.bind( this ) );
			this.submit = this.fieldLayout.buttonWidget;
			this.submit.on( 'click', this.onSubmit.bind( this ) );
		}
	}

	/**
	 * @param {string} status either 'submitting' or 'submit'
	 */
	setSubmit( status ) {
		if ( this.isQrCode ) {
			this.submit.setLabel( mw.msg( 'urlshortener-qrcode-form-submit' ) );
		} else {
			// The following messages are used here:
			// * urlshortener-url-input-submitting
			// * urlshortener-url-input-submit
			this.submit.setLabel( mw.msg( 'urlshortener-url-input-' + status ) );
			this.submit.setDisabled( status === 'submitting' );
		}
	}

	/**
	 * Shorten the provided url
	 *
	 * @param {string} url
	 * @return {jQuery.Promise}
	 */
	shortenUrl( url ) {
		const params = {
			action: 'shortenurl',
			qrcode: this.enableQrCode,
			url
		};
		return this.api.post( params ).then(
			( data ) => data.shortenurl,
			( errCode, data ) => $.Deferred().reject( data.error ).promise()
		);
	}
}

if ( window.QUnit ) {
	module.exports = { UrlShortener };
} else {
	$( () => {
		const shortener = new UrlShortener();
		shortener.init();
	} );
}
