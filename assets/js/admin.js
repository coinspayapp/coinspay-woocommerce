/**
 * CoinsPay settings screen: the copy button and "Check connection".
 */
( function ( $, config ) {
	'use strict';

	$( document ).on( 'click', '[data-coinspay-copy]', function () {
		var button = $( this );
		var input = document.getElementById( button.data( 'coinspay-copy' ) );
		if ( ! input ) {
			return;
		}
		input.select();
		var done = function () {
			var label = button.text();
			button.text( config.copied );
			setTimeout( function () {
				button.text( label );
			}, 1500 );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( input.value ).then( done );
		} else if ( document.execCommand( 'copy' ) ) {
			done();
		}
	} );

	$( document ).on( 'click', '#coinspay-check', function () {
		var button = $( this );
		var result = $( '#coinspay-check-result' );
		button.prop( 'disabled', true );
		result.css( 'color', '' ).text( config.checking );

		$.post( config.ajaxUrl, {
			action: config.action,
			nonce: config.nonce,
			api_url: $( '#woocommerce_coinspay_api_url' ).val(),
			api_key: $( '#woocommerce_coinspay_api_key' ).val(),
		} )
			.done( function ( response ) {
				var message = response && response.data && response.data.message ? response.data.message : config.failed;
				result.css( 'color', response && response.success ? '#007017' : '#b32d2e' ).text( message );
			} )
			.fail( function ( xhr ) {
				var data = xhr.responseJSON && xhr.responseJSON.data;
				result.css( 'color', '#b32d2e' ).text( data && data.message ? data.message : config.failed );
			} )
			.always( function () {
				button.prop( 'disabled', false );
			} );
	} );
} )( jQuery, window.coinspayAdmin || {} );
