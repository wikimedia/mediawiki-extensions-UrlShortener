( function () {

	mw.urlshortener = {
		/**
		 * @member {mw.Api)
		 */
		api: new mw.Api(),

		/**
		 * @member {RegExp}
		 */
		regex: new RegExp( mw.config.get( 'wgUrlShortenerAllowedDomains' ) ),

		/**
		 * @member {boolean}
		 */
		allowArbitraryPorts: mw.config.get( 'wgUrlShortenerAllowArbitraryPorts' ),

		/**
		 * @member {OO.ui.TextInputWidget}
		 */
		shortened: null,

		/**
		 * @member {OO.ui.TextInputWidget}
		 */
		input: null,
		/**
		 * @member {OO.ui.ButtonInputWidget}
		 */
		submit: null,

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
		validateInput: function ( input ) {
			var parsed, url,
				self = mw.urlshortener;

			try {
				parsed = new mw.Uri( input );
			} catch ( e ) {
				self.fieldLayout.setErrors( [ mw.msg( 'urlshortener-error-malformed-url' ) ] );
				return false;
			}
			if ( !parsed.host.match( self.regex ) ) {
				url = parsed.protocol + '://' + mw.html.escape( parsed.host );
				self.fieldLayout.setErrors( [ mw.msg( 'urlshortener-error-disallowed-url', url ) ] );
				return false;
			}
			if ( parsed.port &&
				!self.allowArbitraryPorts &&
				!( parsed.port === '80' || parsed.port === '443' )
			) {
				self.fieldLayout.setErrors( [ mw.msg( 'urlshortener-error-badports' ) ] );
				return false;
			}

			if ( parsed.user || parsed.password ) {
				self.fieldLayout.setErrors( [ mw.msg( 'urlshortener-error-nouserpass' ) ] );
				return false;
			}

			self.fieldLayout.setErrors( [] );
			return true;
		},

		/**
		 * Click handler for the submit button
		 */
		onSubmit: function () {
			var self = mw.urlshortener;
			self.input.getValidity().done( function () {
				self.input.pushPending().setReadOnly( true );
				self.setSubmit( 'submitting' );
				self.shortenUrl(
					self.input.getValue()
				).done( function ( urls ) {
					if ( !self.shortened ) {
						self.shortened = new mw.widgets.CopyTextLayout( {
							align: 'top',
							label: mw.msg( 'urlshortener-shortened-url-label' ),
							classes: [ '.ext-urlshortener-result' ],
							copyText: urls.shorturl,
							help: mw.msg( 'urlshortener-shortened-url-alt' ),
							helpInline: true,
							successMessage: mw.msg( 'urlshortener-copy-success' ),
							failMessage: mw.msg( 'urlshortener-copy-fail' )
						} );
						self.$alt = $( '<a>' );
						self.shortened.$help.append( ' ', self.$alt );
						// Remove PHP result widget if present
						// eslint-disable-next-line no-jquery/no-global-selector
						$( '.ext-urlshortener-result' ).remove();
						// Wrap in a FieldLayout so we get the label
						self.fieldLayout.$element.after( self.shortened.$element );
					} else {
						self.shortened.textInput.setValue( urls.shorturl );
					}
					self.$alt.attr( 'href', urls.shorturlalt ).text( urls.shorturlalt );
					self.$alt.off( 'click' ).on( 'click', function ( e ) {
						self.shortened.textInput.setValue( urls.shorturlalt );
						self.shortened.onButtonClick();
						self.shortened.textInput.setValue( urls.shorturl );
						self.$alt[ 0 ].focus();
						e.preventDefault();
					} );
					self.shortened.textInput.select();
				} ).fail( function ( err ) {
					self.fieldLayout.setErrors( [ err.info ] );
				} ).always( function () {
					self.setSubmit( 'submit' );
					self.input.popPending().setReadOnly( false );
				} );
			} );
		},

		init: function () {
			// eslint-disable-next-line no-jquery/no-global-selector
			var $field = $( 'form > .mw-htmlform-field-HTMLTextFieldWithButton' );
			if ( $field.length ) {
				this.fieldLayout = OO.ui.infuse( $field );
				this.input = this.fieldLayout.fieldWidget;
				this.input.setValidation( this.validateInput );
				this.submit = this.fieldLayout.buttonWidget;
				this.submit.on( 'click', this.onSubmit );
			}
		},

		/**
		 * @param {string} status either 'submitting' or 'submit'
		 */
		setSubmit: function ( status ) {
			// The following messages are used here:
			// * urlshortener-url-input-submitting
			// * urlshortener-url-input-submit
			this.submit.setLabel( mw.msg( 'urlshortener-url-input-' + status ) );
			this.submit.setDisabled( status === 'submitting' );
		},

		/**
		 * Shorten the provided url
		 *
		 * @param {string} url
		 * @return {jQuery.Promise}
		 */
		shortenUrl: function ( url ) {
			return this.api.post( {
				action: 'shortenurl',
				url: url
			} ).then( function ( data ) {
				return data.shortenurl;
			}, function ( errCode, data ) {
				return $.Deferred().reject( data.error ).promise();
			} );
		}
	};

	$( function () {
		mw.urlshortener.init();
	} );
}() );
