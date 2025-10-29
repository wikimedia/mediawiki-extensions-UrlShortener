class UrlShortener {

	constructor() {
		this.api = new mw.Api();
		// Defined server-side using UrlShortenerUtils::getAllowedDomainsRegex

		this.regex = new RegExp( mw.config.get( 'wgUrlShortenerAllowedDomains' ) );
		this.allowArbitraryPorts = mw.config.get( 'wgUrlShortenerAllowArbitraryPorts' );
		this.isQrCode = mw.config.get( 'wgCanonicalSpecialPageName' ) === 'QrCode';
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
		if ( !url.host.match( this.regex ) ) {
			const origin = mw.html.escape( url.origin );
			this.fieldLayout.setErrors( [ mw.msg( 'urlshortener-error-disallowed-url', origin ) ] );
			return false;
		}
		if ( url.port &&
			!this.allowArbitraryPorts &&
			!( url.port === '80' || url.port === '443' ) &&
			url.hostname !== mw.config.get( 'wgServerName' )
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

	qrCodeUiHandler( qrCodeSvg ) {
		const qrCodeUri = `data:image/svg+xml;charset=utf-8,${ encodeURIComponent( qrCodeSvg ) }`;
		if ( this.$qrCodeImage ) {
			this.$qrCodeImage.attr( 'src', qrCodeUri );
			this.qrCodeDownloadButton.$button.attr( 'href', qrCodeUri );
			return;
		}
		// Remove PHP result if present
		// eslint-disable-next-line no-jquery/no-global-selector
		$( '.ext-urlshortener-qrcode-container' ).remove();
		this.$qrCodeImage = $( '<img>' ).attr( 'src', qrCodeUri );

		this.qrCodeDownloadButton = new OO.ui.ButtonWidget( {
			icon: 'download',
			label: mw.msg( 'urlshortener-toolbox-qrcode' ),
			href: '.'
		} );

		this.qrCodeDownloadButton.$button.attr( {
			download: 'qrcode.svg',
			// OOUI prefixes './' for security, so set the attribute directly
			href: qrCodeUri
		} );

		// eslint-disable-next-line no-jquery/no-global-selector
		$( '.ext-urlshortener-container' ).append(
			$( '<div>' ).addClass( 'ext-urlshortener-qrcode-container' ).append(
				$( '<div>' ).addClass( 'ext-urlshortener-qrcode' ).append( this.$qrCodeImage ),
				this.qrCodeDownloadButton.$element
			)
		);
	}

	/**
	 * Click handler for the submit button
	 */
	onSubmit() {
		this.input.getValidity().then( () => {
			this.input.pushPending().setReadOnly( true );
			this.setSubmit( 'submitting' );
			this.shortenUrl(
				this.input.getValue()
			).then( ( result ) => {
				// shortUrlUiHandler() *may* be called for Special:QrCode, not always
				if ( result.shorturl ) {
					this.shortUrlUiHandler( result );
				} else if ( this.shortened ) {
					// There's no `shorturl` in the response, so remove the shortened field.
					this.shortened.$element.remove();
					this.shortened = null;
				}
				// qrCodeUiHandler() should only be called for Special:QrCode,
				// (but in that case result.qrcode shouldn't be present, anyway)
				if ( this.isQrCode ) {
					this.qrCodeUiHandler( result.qrcode );
				}
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
			url
		};
		if ( this.isQrCode ) {
			params.qrcode = true;
		}
		return this.api.post( params ).then(
			( data ) => data.shortenurl,
			( errCode, data ) => $.Deferred().reject( data.error ).promise()
		);
	}
}

$( () => {
	const urlShortener = new UrlShortener();
	urlShortener.init();
} );
