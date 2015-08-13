( function ( mw, $, OO ) {
	$( function () {
		var popup,
			popupLink = $( '#t-urlshortener' ),
			POPUP_WIDTH = 300,
			POPUP_HEIGHT = 50,
			api = new mw.Api(),
			progress = new OO.ui.ProgressBarWidget(),
			fieldset = new OO.ui.FieldsetLayout();

		fieldset.addItems( [
			new OO.ui.FieldLayout( progress,
				{ label: mw.msg( 'urlshortener-url-input-submitting' ), align: 'top' }
			)
		] );

		/**
		 * @param {OO.ui.Widget} widget
		 */
		function showWidget( widget ) {
			popup.setSize( POPUP_WIDTH, POPUP_HEIGHT + 50, true );
			fieldset.clearItems();
			fieldset.addItems( [ widget ] );
		}

		popup = new OO.ui.PopupWidget( {
			$content: fieldset.$element,
			padded: true,
			height: POPUP_HEIGHT,
			width: POPUP_WIDTH,
			classes: [ 'ext-urlshortener-popup' ]
		} );

		popupLink.after( popup.$element );
		popupLink.on( 'click', function () {
			popup.toggle( true );
			api.post( {
				action: 'shortenurl',
				url: window.location.href
			} ).done( function ( data ) {
				var input = new OO.ui.TextInputWidget( {
					value: data.shortenurl.shorturl,
					autofocus: true
				} ),
					widget = new OO.ui.FieldLayout( input,
						{ label: mw.msg( 'urlshortener-shortened-url-label' ), align: 'top' }
					);

				showWidget( widget );
			} ).fail( function ( code ) {
				// code will always be urlshortener-ratelimit
				showWidget( new OO.ui.LabelWidget( {
					label: mw.msg( code )
				} ) );
			} );

			return false;
		} );

		$( 'body' ).on( 'click', function () {
			popup.toggle( false );
		} );
	} );

} )( mediaWiki, jQuery, OO );
