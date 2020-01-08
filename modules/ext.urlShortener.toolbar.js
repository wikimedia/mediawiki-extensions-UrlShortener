( function () {
	$( function () {
		// eslint-disable-next-line no-jquery/no-global-selector
		var $shortenUrlListItem = $( '#t-urlshortener' ),
			api = new mw.Api();

		$shortenUrlListItem.on( 'click', function () {
			var $link = $( this ).find( 'a' );
			$link.text( mw.msg( 'urlshortener-url-input-submitting' ) );

			api.post( {
				action: 'shortenurl',
				url: window.location.href
			} ).done( function ( data ) {
				var $input = $( '<input>' ).val( data.shortenurl.shorturl );
				$shortenUrlListItem.empty().append( $input );
				$input.trigger( 'focus' ).trigger( 'select' );
			} ).fail( function () {
				$link.text( mw.msg( 'urlshortener-failed-try-again' ) );
			} ).always( function () {
				// Remove the click listner on the <li>
				// On failure: the link inside points to Special:UrlShortener
				// On success: don't trigger the API request every time the input is clicked
				$shortenUrlListItem.off( 'click' );
			} );

			return false;
		} );
	} );

}() );
