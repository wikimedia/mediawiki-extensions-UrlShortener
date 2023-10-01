( function () {
	// eslint-disable-next-line no-jquery/no-global-selector
	var $link = $( '#t-urlshortener' ).find( 'a' ),
		widgetPromise;

	$link.attr( 'aria-haspopup', 'dialog' );
	$link.on( 'click', function ( e ) {
		e.preventDefault();
		if ( !widgetPromise ) {
			var linkText = $link.html();
			$link.text( mw.msg( 'urlshortener-url-input-submitting' ) );
			widgetPromise = mw.loader.using( [
				'oojs-ui-windows',
				'mediawiki.api',
				'mediawiki.widgets'
			] ).then( function () {
				var api = new mw.Api();
				return api.post( {
					action: 'shortenurl',
					url: window.location.href
				} ).then( function ( data ) {
					var widget = new mw.widgets.CopyTextLayout( {
						align: 'top',
						label: mw.msg( 'urlshortener-shortened-url-label' ),
						classes: [ 'ext-urlshortener-result', 'ext-urlshortener-result-dialog' ],
						copyText: data.shortenurl.shorturl,
						help: mw.msg( 'urlshortener-shortened-url-alt' ),
						helpInline: true,
						successMessage: mw.msg( 'urlshortener-copy-success' ),
						failMessage: mw.msg( 'urlshortener-copy-fail' )
					} );
					var $alt = $( '<a>' );
					widget.$help.append( ' ', $alt );
					$alt.attr( 'href', data.shortenurl.shorturlalt )
						.text( data.shortenurl.shorturlalt );
					$alt.off( 'click' ).on( 'click', function ( event ) {
						event.preventDefault();
						widget.textInput.setValue( data.shortenurl.shorturlalt );
						widget.onButtonClick();
						widget.textInput.setValue( data.shortenurl.shorturl );
						$alt[ 0 ].focus();
					} );
					$link.html( linkText );
					return widget;
				} );
			} );
		}
		widgetPromise.then(
			function ( widget ) {
				OO.ui.alert( widget.$element );
				// HACK: Wait for setup and ready processes to complete
				setTimeout( function () {
					widget.button.focus();
				}, 500 );
			},
			function () {
				// Point the link to Special:UrlShortener
				$link.html( mw.msg( 'urlshortener-failed-try-again' ) );
				$link.off( 'click' ).removeAttr( 'aria-haspopup' );
			}
		);
		return false;
	} );
}() );
