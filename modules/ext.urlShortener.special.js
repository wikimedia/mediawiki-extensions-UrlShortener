( function () {

	mw.urlshortener = {
		/**
		 * @var {mw.Api)
		 */
		api: new mw.Api(),

		/**
		 * @var {RegExp}
		 */
		regex: new RegExp( mw.config.get( 'wgUrlShortenerDomainsWhitelist' ) ),

		/**
		 * @var {boolean}
		 */
		allowArbitraryPorts: mw.config.get( 'wgUrlShortenerAllowArbitraryPorts' ),

		/**
		 * @var {OO.ui.TextInputWidget}
		 */
		shortened: null,

		/**
		 * @var {OO.ui.TextInputWidget}
		 */
		input: null,
		/**
		 * @var {OO.ui.ButtonInputWidget}
		 */
		submit: null,

		errors: [],

		/**
		 * Show an error
		 *
		 * @param {string} error Error
		 */
		showError: function ( error ) {
			var self = mw.urlshortener;

			if ( self.errors.indexOf( error ) === -1 ) {
				self.errors.push( error );
			}

			self.fieldLayout.setErrors( self.errors );
		},

		/**
		 * Validate the input URL clientside. Note that this is not the
		 * only check - they are checked serverside too.
		 *
		 * Checks for both URL validity and whitelist matching.
		 *
		 * @param {string} input the URL that is to be shortened
		 * @return {boolean|Object} true if object is validated, an object matching what is
		 *                         returned by the API in case of error.
		 */
		validateInput: function ( input ) {
			var parsed,
				self = mw.urlshortener;

			try {
				parsed = new mw.Uri( input );
			} catch ( e ) {
				self.showError( mw.msg( 'urlshortener-error-malformed-url' ) );
				return false;
			}
			if ( !parsed.host.match( self.regex ) ) {
				self.showError( mw.msg( 'urlshortener-error-disallowed-url', parsed.host ) );
				return false;
			}
			if ( parsed.port &&
				!self.allowArbitraryPorts &&
				!( parsed.port === '80' || parsed.port === '443' )
			) {
				self.showError( mw.msg( 'urlshortener-error-badports' ) );
				return false;
			}

			if ( parsed.user || parsed.password ) {
				self.showError( mw.msg( 'urlshortener-error-nouserpass' ) );
				return false;
			}

			self.errors = [];
			self.fieldLayout.setErrors( self.errors );

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
				).done( function ( shorturl ) {
					self.setSubmit( 'submit' );
					self.input.popPending().setReadOnly( false );

					if ( !self.shortened ) {
						self.shortened = new OO.ui.TextInputWidget( {
							value: shorturl,
							readOnly: true
						} );
						// Wrap in a FieldLayout so we get the label
						self.fieldLayout.$element.after( new OO.ui.FieldLayout( self.shortened, {
							align: 'top',
							label: mw.msg( 'urlshortener-shortened-url-label' )
						} ).$element );
					} else {
						self.shortened.setValue( shorturl );
					}
					self.shortened.select();
				} ).fail( function ( err ) {
					self.setSubmit( 'submit' );
					self.input.popPending().setReadOnly( false );
					self.errors.push( err.info );
					self.fieldLayout.setErrors( self.errors );
				} );
			} );
		},

		init: function () {
			// eslint-disable-next-line no-jquery/no-global-selector
			this.fieldLayout = OO.ui.infuse( $( 'form > .mw-htmlform-field-HTMLTextFieldWithButton' ) );
			this.input = this.fieldLayout.fieldWidget;
			this.input.setValidation( this.validateInput );
			this.submit = this.fieldLayout.buttonWidget;
			this.submit.on( 'click', this.onSubmit );
		},

		/**
		 * @param {string} status either 'submitting' or 'submit'
		 */
		setSubmit: function ( status ) {
			// urlshortener-url-input-submitting, urlshortener-url-input-submit
			this.submit.setLabel( mw.message( 'urlshortener-url-input-' + status ).text() );
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
				return data.shortenurl.shorturl;
			}, function ( errCode, data ) {
				return data.error;
			} );
		}
	};

	$( function () {
		mw.urlshortener.init();
	} );
}() );
