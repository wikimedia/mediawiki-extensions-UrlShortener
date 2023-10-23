( function () {
	// eslint-disable-next-line no-jquery/no-global-selector
	var $shortenUrlLink = $( '#t-urlshortener' ).find( 'a' ),
		$qrCodeLink,
		widgetPromise;

	if ( mw.config.get( 'skin' ) === 'minerva' ) {
		// eslint-disable-next-line no-jquery/no-global-selector
		$qrCodeLink = $( '.ext-urlshortener-qrcode-download-minerva' );
	} else {
		// eslint-disable-next-line no-jquery/no-global-selector
		$qrCodeLink = $( '#t-urlshortener-qrcode' ).find( 'a' );
	}

	$shortenUrlLink.attr( 'aria-haspopup', 'dialog' );
	$shortenUrlLink.on( 'click', function ( e ) {
		e.preventDefault();
		if ( !widgetPromise ) {
			var linkText = $shortenUrlLink.html();
			$shortenUrlLink.text( mw.msg( 'urlshortener-url-input-submitting' ) );
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
					$shortenUrlLink.html( linkText );
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
				$shortenUrlLink.html( mw.msg( 'urlshortener-failed-try-again' ) );
				$shortenUrlLink.off( 'click' ).removeAttr( 'aria-haspopup' );
			}
		);
		return false;
	} );

	$qrCodeLink.on( 'click', function ( e ) {
		e.preventDefault();
		mw.loader.using( 'mediawiki.api' ).done( function () {
			$qrCodeLink.find( '.toggle-list-item__label' )
				.text( mw.msg( 'urlshortener-url-input-submitting' ) );
			var api = new mw.Api();
			api.post( {
				action: 'shortenurl',
				url: window.location.href,
				qrcode: true
			} ).done( function ( data ) {
				// Create hidden anchor and force a download. This seems hacky,
				// but we'd otherwise we need a specialized API with the proper response header.
				var downloadLink = document.createElement( 'a' );
				downloadLink.download = 'qrcode.svg';
				downloadLink.href = 'data:image/svg+xml,' + encodeURIComponent( data.shortenurl.qrcode );
				document.body.appendChild( downloadLink );
				downloadLink.click();
				document.body.removeChild( downloadLink );
				// Restore original copy to link and notify user of the download.
				$qrCodeLink.find( '.toggle-list-item__label' )
					.text( mw.msg( 'urlshortener-toolbox-qrcode' ) );
				mw.notify( mw.msg( 'urlshortener-qrcode-downloaded' ), { type: 'success' } );
			} ).fail( function () {
				$qrCodeLink.find( '.toggle-list-item__label' )
					.text( mw.msg( 'urlshortener-failed-try-again' ) );
			} );
		} );

		return false;
	} );
}() );
