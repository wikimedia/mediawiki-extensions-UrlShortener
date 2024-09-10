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
		/** @type {HTMLImageElement} */
		this.qrCodeImage = null;
		/** @type {HTMLAnchorElement} */
		this.qrCodeDownloadButton = null;
	}

	/**
	 * Validate the input URL clientside. Note that this is not the
	 * only check - they are checked serverside too.
	 *
	 * Checks for both URL validity and AllowedDomains matching.
	 *
	 * @param {string} input the URL that is to be shortened
	 * @return {boolean|Object} true if object is validated, an object matching what is
	 *                         returned by the API in case of error.
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
			!( url.port === '80' || url.port === '443' )
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
		const qrCodeUri = `data:image/svg+xml,${ encodeURIComponent( qrCodeSvg ) }`;
		if ( this.qrCodeImage ) {
			this.qrCodeImage.src = qrCodeUri;
			this.qrCodeDownloadButton.href = qrCodeUri;
			return;
		}
		// Remove PHP result if present
		// eslint-disable-next-line no-jquery/no-global-selector
		$( '.ext-urlshortener-qrcode-container' ).remove();
		this.qrCodeImage = document.createElement( 'img' );
		this.qrCodeImage.src = qrCodeUri;
		const qrCodeNode = document.createElement( 'div' );
		qrCodeNode.className = 'ext-urlshortener-qrcode';
		qrCodeNode.append( this.qrCodeImage );
		const qrCodeDownloadIcon = document.createElement( 'span' );
		qrCodeDownloadIcon.className = 'cdx-icon cdx-icon--medium';
		this.qrCodeDownloadButton = document.createElement( 'a' );
		this.qrCodeDownloadButton.classList.add(
			'ext-urlshortener-download-qr-button',
			'cdx-button',
			'cdx-button--fake-button',
			'cdx-button--fake-button--enabled'
		);
		this.qrCodeDownloadButton.href = qrCodeUri;
		this.qrCodeDownloadButton.download = 'qrcode.svg';
		this.qrCodeDownloadButton.append(
			qrCodeDownloadIcon,
			mw.msg( 'urlshortener-toolbox-qrcode' )
		);
		const qrCodeContainer = document.createElement( 'div' );
		qrCodeContainer.className = 'ext-urlshortener-qrcode-container';
		qrCodeContainer.append( qrCodeNode, this.qrCodeDownloadButton );
		document.querySelector( '.ext-urlshortener-container' )
			.append( qrCodeContainer );
	}

	/**
	 * Click handler for the submit button
	 */
	onSubmit() {
		this.input.getValidity().done( () => {
			this.input.pushPending().setReadOnly( true );
			this.setSubmit( 'submitting' );
			this.shortenUrl(
				this.input.getValue()
			).done( ( result ) => {
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
			} ).fail( ( err ) => {
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
		return this.api.post( params ).then( ( data ) => data.shortenurl, ( errCode, data ) => $.Deferred().reject( data.error ).promise() );
	}
}

$( () => {
	const urlShortener = new UrlShortener();
	urlShortener.init();
} );
