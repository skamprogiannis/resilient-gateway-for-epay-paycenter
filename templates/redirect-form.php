<?php
/**
 * Redirect form rendered on the Pay-for-order page.
 *
 * @package EpayPaycenter
 *
 * @var string   $post_url   Paycenter form POST URL.
 * @var array    $form_fields Hidden form fields keyed by name.
 * @var WC_Order $order      Order.
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $post_url, $form_fields, $order ) ) {
	return;
}
?>
<div class="epay-paycenter-redirect">
	<p class="epay-paycenter-redirect__message">
		<?php esc_html_e( 'Redirecting you to the Piraeus Bank secure payment page to complete your order. Please do not close or reload this page.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
	</p>

	<form id="epay-paycenter-form" method="POST" action="<?php echo esc_url( $post_url ); ?>" accept-charset="UTF-8">
		<?php foreach ( $form_fields as $epay_paycenter_field_name => $epay_paycenter_field_value ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $epay_paycenter_field_name ); ?>" value="<?php echo esc_attr( $epay_paycenter_field_value ); ?>" />
		<?php endforeach; ?>
		<noscript>
			<p><?php esc_html_e( 'JavaScript is required to complete the payment automatically. Click the button below to continue to the secure payment page.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?></p>
			<button type="submit" class="button alt">
				<?php esc_html_e( 'Continue to secure payment', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
			</button>
		</noscript>
	</form>

	<p class="epay-paycenter-redirect__cancel">
		<a href="<?php echo esc_url( $order->get_cancel_order_url_raw() ); ?>">
			<?php esc_html_e( 'Cancel and return to checkout', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
		</a>
	</p>
</div>
<?php
/*
 * Auto-submit of the form above is handled by the enqueued
 * `epay-paycenter-redirect` script (assets/js/epay-paycenter-redirect.js),
 * registered via wp_enqueue_script() from
 * Epay_Paycenter_Gateway::output_receipt_page(). No inline <script> is
 * emitted from this template, per the WordPress plugin review guideline
 * that JavaScript must be loaded through wp_enqueue_script() or
 * wp_add_inline_script().
 */

