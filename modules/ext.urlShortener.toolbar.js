( function () {
	// eslint-disable-next-line no-jquery/no-global-selector
	const $shortenUrlLink = $( '#t-urlshortener' ).find( 'a' );
	let $qrCodeLink,
		widgetPromise;

	if ( mw.config.get( 'skin' ) === 'minerva' ) {
		// eslint-disable-next-line no-jquery/no-global-selector
		$qrCodeLink = $( '.ext-urlshortener-qrcode-download-minerva' );
	} else {
		// eslint-disable-next-line no-jquery/no-global-selector
		$qrCodeLink = $( '#t-urlshortener-qrcode' ).find( 'a' );
	}

	$shortenUrlLink.attr( 'aria-haspopup', 'dialog' );
	$shortenUrlLink.on( 'click', ( e ) => {
		e.preventDefault();
		if ( !widgetPromise ) {
			const linkText = $shortenUrlLink.html();
			$shortenUrlLink.text( mw.msg( 'urlshortener-url-input-submitting' ) );
			widgetPromise = mw.loader.using( [
				'oojs-ui-windows',
				'mediawiki.api',
				'mediawiki.widgets'
			] ).then( () => {
				const api = new mw.Api();
				return Promise.all( [ undefined, 1 ].map( ( qr ) => api.post( {
					action: 'shortenurl',
					url: window.location.href,
					qrcode: qr
				} ) ) ).then( ( data ) => {
					const urlRepsonse = data[ 0 ].shortenurl;
					const widget = new mw.widgets.CopyTextLayout( {
						align: 'top',
						label: mw.msg( 'urlshortener-shortened-url-label' ),
						classes: [ 'ext-urlshortener-result', 'ext-urlshortener-result-dialog' ],
						copyText: urlRepsonse.shorturl,
						help: mw.msg( 'urlshortener-shortened-url-alt' ),
						helpInline: true,
						successMessage: mw.msg( 'urlshortener-copy-success' ),
						failMessage: mw.msg( 'urlshortener-copy-fail' )
					} );
					const $alt = $( '<a>' );
					widget.$help.append( ' ', $alt );
					$alt.attr( 'href', urlRepsonse.shorturlalt )
						.text( urlRepsonse.shorturlalt );
					$alt.off( 'click' ).on( 'click', ( event ) => {
						event.preventDefault();
						widget.textInput.setValue( urlRepsonse.shorturlalt );
						widget.onButtonClick();
						widget.textInput.setValue( urlRepsonse.shorturl );
						$alt[ 0 ].focus();
					} );
					const qrResponse = data[ 1 ].shortenurl;
					if ( qrResponse.qrcode ) {
						$shortenUrlLink.html( linkText );
						const qrCodeUri = `data:image/svg+xml;charset=utf-8,${ encodeURIComponent( qrResponse.qrcode ) }`;
						const download = new OO.ui.ButtonWidget( {
							icon: 'download',
							label: mw.msg( 'urlshortener-toolbox-qrcode' ),
							href: '.'
						} );
						download.$button.attr( {
							download: 'qrcode.svg',
							// OOUI prefixes './' for security, so set the attribute directly
							href: qrCodeUri
						} );
						widget.$element.append(
							$( '<div>' ).addClass( 'ext-urlshortener-qrcode' ).append(
								$( '<img>' ).attr( 'src', qrCodeUri ),
								download.$element
							)
						);
					}
					return widget;
				} );
			} );
		}
		widgetPromise.then(
			( widget ) => {
				OO.ui.alert( widget.$element, { size: 'medium' } );
				// HACK: Wait for setup and ready processes to complete
				setTimeout( () => {
					widget.button.focus();
				}, 500 );
			},
			() => {
				// Point the link to Special:UrlShortener
				$shortenUrlLink.html( mw.msg( 'urlshortener-failed-try-again' ) );
				$shortenUrlLink.off( 'click' ).removeAttr( 'aria-haspopup' );
			}
		);
		return false;
	} );

	$qrCodeLink.on( 'click', ( e ) => {
		e.preventDefault();
		mw.loader.using( 'mediawiki.api' ).done( () => {
			$qrCodeLink.find( '.toggle-list-item__label' )
				.text( mw.msg( 'urlshortener-url-input-submitting' ) );
			const api = new mw.Api();
			api.post( {
				action: 'shortenurl',
				url: window.location.href,
				qrcode: true
			} ).done( ( data ) => {
				// Create hidden anchor and force a download. This seems hacky,
				// but we'd otherwise we need a specialized API with the proper response header.
				const downloadLink = document.createElement( 'a' );
				downloadLink.download = 'qrcode.svg';
				downloadLink.href = 'data:image/svg+xml,' + encodeURIComponent( data.shortenurl.qrcode );
				document.body.appendChild( downloadLink );
				downloadLink.click();
				document.body.removeChild( downloadLink );
				// Restore original copy to link and notify user of the download.
				$qrCodeLink.find( '.toggle-list-item__label' )
					.text( mw.msg( 'urlshortener-toolbox-qrcode' ) );
				mw.notify( mw.msg( 'urlshortener-qrcode-downloaded' ), { type: 'success' } );
			} ).fail( () => {
				$qrCodeLink.find( '.toggle-list-item__label' )
					.text( mw.msg( 'urlshortener-failed-try-again' ) );
			} );
		} );

		return false;
	} );
}() );
