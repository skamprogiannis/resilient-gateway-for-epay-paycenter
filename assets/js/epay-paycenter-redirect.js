/**
 * Auto-submit helper for the ePay Paycenter (Piraeus Bank) redirect form.
 *
 * Loaded only on the WooCommerce "Pay for order" page, via
 * wp_enqueue_script() from Epay_Paycenter_Gateway::output_receipt_page().
 * Kept as a standalone file so the plugin does not emit inline <script>
 * tags, per the WordPress plugin review guidelines on asset loading.
 *
 * The <noscript> block inside templates/redirect-form.php shows a manual
 * "Continue to secure payment" button when JavaScript is disabled, so the
 * payment flow stays functional even without this script.
 */
( function () {
	'use strict';

	function submitRedirectForm() {
		if ( typeof document === 'undefined' ) {
			return;
		}
		var form = document.getElementById( 'epay-paycenter-form' );
		if ( ! form ) {
			return;
		}
		window.setTimeout( function () {
			if ( form instanceof HTMLFormElement ) {
				form.submit();
			}
		}, 100 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', submitRedirectForm );
	} else {
		submitRedirectForm();
	}
} )();
