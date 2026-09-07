/**
 * ePay Paycenter - WooCommerce Blocks checkout integration.
 *
 * Registers the payment method with the block checkout. The actual payment
 * is processed server side via WC_Payment_Gateway::process_payment() which
 * returns the redirect URL. This script is only responsible for the label
 * and description shown at checkout.
 */
( function () {
	var settings = ( window.wc && window.wc.wcSettings && window.wc.wcSettings.getSetting )
		? window.wc.wcSettings.getSetting( 'epay_paycenter_data', {} )
		: {};

	var registerPaymentMethod = ( window.wc && window.wc.wcBlocksRegistry )
		? window.wc.wcBlocksRegistry.registerPaymentMethod
		: null;

	if ( ! registerPaymentMethod ) {
		return;
	}

	var createElement = ( window.wp && window.wp.element ) ? window.wp.element.createElement : null;
	var decodeEntities = ( window.wp && window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities )
		? window.wp.htmlEntities.decodeEntities
		: function ( v ) { return v; };

	var title       = decodeEntities( settings.title || 'Credit / Debit Card (Piraeus Bank)' );
	var description = decodeEntities( settings.description || '' );
	var iconUrl     = settings.icon || '';

	var Label = function () {
		if ( ! createElement ) { return title; }
		return createElement( 'span', null, title );
	};

	var Content = function () {
		if ( ! createElement ) { return description; }

		var children = [];

		if ( iconUrl ) {
			children.push(
				createElement(
					'div',
					{ className: 'epay-paycenter-icon', key: 'icon' },
					createElement( 'img', {
						src: iconUrl,
						alt: title,
						className: 'epay-paycenter-icon__img'
					} )
				)
			);
		}

		if ( description ) {
			children.push( createElement( 'p', { key: 'desc' }, description ) );
		}

		return createElement( 'div', null, children );
	};

	registerPaymentMethod( {
		name: 'epay_paycenter',
		label: createElement ? createElement( Label, null ) : title,
		content: createElement ? createElement( Content, null ) : description,
		edit: createElement ? createElement( Content, null ) : description,
		canMakePayment: function () { return true; },
		ariaLabel: title,
		supports: {
			features: ( settings.supports && settings.supports.length ) ? settings.supports : [ 'products' ]
		}
	} );
} )();
