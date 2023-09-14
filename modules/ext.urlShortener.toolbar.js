( function () {
	// eslint-disable-next-line no-jquery/no-global-selector
	var $link = $( '#t-urlshortener' ).find( 'a' ),
		widget;

	$link.attr( 'aria-haspopup', 'dialog' );
	$link.on( 'click', function ( e ) {
		e.preventDefault();
		if ( widget ) {
			OO.ui.alert( widget.$element );
		} else {
			var linkText = $link.html();
			$link.text( mw.msg( 'urlshortener-url-input-submitting' ) );
			mw.loader.using( [
				'oojs-ui',
				'oojs-ui.styles.icons-content',
				'mediawiki.api',
				'mediawiki.widgets'
			] ).done( function () {
				var api = new mw.Api();
				api.post( {
					action: 'shortenurl',
					url: window.location.href
				} ).done( function ( data ) {
					widget = new mw.widgets.CopyTextLayout( {
						align: 'top',
						label: mw.msg( 'urlshortener-shortened-url-label' ),
						classes: [ 'ext-urlshortener-result' ],
						copyText: data.shortenurl.shorturl,
						help: mw.msg( 'urlshortener-shortened-url-alt' ),
						helpInline: true,
						successMessage: mw.msg( 'urlshortener-copy-success' ),
						failMessage: mw.msg( 'urlshortener-copy-fail' )
					} );
					// Adjust for MessageDialog's 1.1em font size
					widget.$element.css( 'font-size', '0.90909em' );
					var $alt = $( '<a>' );
					widget.$help.append( ' ', $alt );
					$alt.attr( 'href', data.shortenurl.shorturlalt )
						.text( data.shortenurl.shorturlalt )
						.css( 'overflow-wrap', 'break-word' );
					$alt.off( 'click' ).on( 'click', function ( event ) {
						event.preventDefault();
						widget.textInput.setValue( data.shortenurl.shorturlalt );
						widget.onButtonClick();
						widget.textInput.setValue( data.shortenurl.shorturl );
						$alt[ 0 ].focus();
					} );
					OO.ui.alert( widget.$element );
					$link.html( linkText );
				} ).fail( function () {
					// Point the link to Special:UrlShortener
					$link.html( mw.msg( 'urlshortener-failed-try-again' ) );
					$link.off( 'click' ).removeAttr( 'aria-haspopup' );
				} );
			} ).fail( function () {
				$link.html( mw.msg( 'urlshortener-failed-try-again' ) );
				$link.off( 'click' ).removeAttr( 'aria-haspopup' );
			} );
		}
		return false;
	} );
}() );
