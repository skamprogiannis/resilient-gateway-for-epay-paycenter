/**
 * ePay Paycenter - WooCommerce Blocks checkout integration.
 *
 * Registers the payment method with the block checkout. The actual payment
 * is processed server side via WC_Payment_Gateway::process_payment() which
 * returns the redirect URL. This script is only responsible for the label
 * and description shown at checkout.
 */
( function () {
	/** @type {EpayBlocksSettings} */
	var settings = ( window.wc && window.wc.wcSettings && window.wc.wcSettings.getSetting )
		? window.wc.wcSettings.getSetting( 'epay_paycenter_data', { description: '', icon: '', supports: [], title: '' } )
		: { description: '', icon: '', supports: [], title: '' };

	var registerPaymentMethod = ( window.wc && window.wc.wcBlocksRegistry )
		? window.wc.wcBlocksRegistry.registerPaymentMethod
		: null;

	if ( ! registerPaymentMethod ) {
		return;
	}

	var createElement = ( window.wp && window.wp.element ) ? window.wp.element.createElement : null;
	var decodeEntities = ( window.wp && window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities )
		? window.wp.htmlEntities.decodeEntities
		: function ( /** @type {string} */ value ) { return value; };

	var title       = decodeEntities( settings.title || 'Credit / Debit Card (Piraeus Bank)' );
	var description = decodeEntities( settings.description || '' );
	var iconUrl     = settings.icon || '';

	/** @returns {EpayRenderable} */
	var Label = function () {
		if ( ! createElement ) { return title; }
		return createElement( 'span', null, title );
	};

	/** @returns {EpayRenderable} */
	var Content = function () {
		if ( ! createElement ) { return description; }

		/** @type {EpayRenderable[]} */
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
