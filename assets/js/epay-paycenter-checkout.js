/* Preserve only the customer's current, still-available classic-checkout choice. */
( function () {
	'use strict';
	if ( ! document.querySelector( 'form.checkout' ) ) {
		return;
	}
	/** @type {string|null} */
	let selected = null;
	let needsReview = false;

	function restore() {
		const picker = document.getElementById( 'epay_installments' );
		if ( picker instanceof HTMLSelectElement ) {
			if ( selected === null ) {
				selected = picker.value;
			} else if ( Array.from( picker.options ).some( option => option.value === selected && ! option.disabled ) ) {
				picker.value = selected;
			} else {
				picker.value = '1';
				selected = '1';
				needsReview = true;
			}
		} else if ( selected !== null && selected !== '1' ) {
			selected = '1';
			needsReview = true;
		}
		document.getElementById( 'epay-installments-notice' )?.remove();
		if ( needsReview ) {
			const notice = document.createElement( 'p' );
			notice.id = 'epay-installments-notice';
			notice.className = 'woocommerce-info';
			notice.setAttribute( 'role', 'status' );
			notice.textContent = window.epayPaycenterCheckout.installmentsChanged;
			document.querySelector( '.woocommerce-checkout-payment' )?.prepend( notice );
		}
	}

	document.addEventListener( 'change', function ( event ) {
		const picker = event.target;
		if ( picker instanceof HTMLSelectElement && picker.id === 'epay_installments' ) {
			selected = picker.value;
			needsReview = false;
			document.getElementById( 'epay-installments-notice' )?.remove();
		}
	} );
	window.jQuery( document.body ).on( 'updated_checkout', restore );
	restore();
}() );
