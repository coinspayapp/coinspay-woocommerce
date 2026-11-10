/**
 * CoinsPay in the Cart and Checkout blocks.
 *
 * Plain JavaScript on the globals WooCommerce provides, so the plugin needs no
 * build step. Paying goes through the gateway on the server: it creates the
 * invoice and redirects the buyer to the CoinsPay payment page.
 */
( function () {
	var wc = window.wc || {};
	var wp = window.wp || {};
	if ( ! wc.wcBlocksRegistry || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var settings = wc.wcSettings && wc.wcSettings.getSetting ? wc.wcSettings.getSetting( 'coinspay_data', {} ) : {};
	var decode = wp.htmlEntities && wp.htmlEntities.decodeEntities ? wp.htmlEntities.decodeEntities : function ( text ) {
		return text;
	};
	var title = decode( settings.title || 'Cryptocurrency' );
	var description = decode( settings.description || '' );

	var Content = function () {
		return description ? el( 'div', { className: 'coinspay-description' }, description ) : null;
	};

	wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'coinspay',
		label: el( 'span', { className: 'coinspay-label' }, title ),
		content: el( Content, null ),
		edit: el( Content, null ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
