( function ( mw, $, OO ) {

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
			var parsed, error, self = mw.urlshortener;
			try {
				parsed = new mw.Uri( input );
				if ( parsed.host.match( self.regex ) ) {
					this.setLabel( null );
					return true;
				} else {
					error = mw.msg( 'urlshortener-error-disallowed-url', parsed.host );
				}
			} catch ( e ) {
				error = mw.msg( 'urlshortener-error-malformed-url' );
			}

			this.setLabel( error );
			return false;
		},

		/**
		 * Click handler for the submit button
		 */
		onSubmit: function () {
			var self = mw.urlshortener;
			self.input.pushPending().setReadOnly( true );
			self.setSubmit( 'submitting' );
			self.input.isValid().done( function () {
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
							self.input.$element.after( new OO.ui.FieldLayout( self.shortened, {
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
						self.input.setLabel( err.info );
					} );
			} );
		},

		init: function () {
			this.input = OO.ui.infuse( 'mw-urlshortener-url-input' );
			this.input.focus(); // FIXME not staying focused due to T106313
			this.input.setValidation( this.validateInput );
			this.submit = OO.ui.infuse( 'mw-urlshortener-submit' );
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
} )( mediaWiki, jQuery, OO );
