<?php
/**
 * Redirect form rendered on the Pay-for-order page.
 *
 * @package EpayPaycenter
 *
 * @var string                $post_url    Paycenter form POST URL.
 * @var array<string, string> $form_fields Hidden form fields keyed by name.
 * @var WC_Order              $order       Order.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="epay-paycenter-redirect">
	<p class="epay-paycenter-redirect__message">
		<?php esc_html_e( 'Redirecting you to the Piraeus Bank secure payment page to complete your order. Please do not close or reload this page.', 'resilient-gateway-for-epay-paycenter' ); ?>
	</p>

	<form id="epay-paycenter-form" method="POST" action="<?php echo esc_url( $post_url ); ?>" accept-charset="UTF-8">
		<?php foreach ( $form_fields as $epay_paycenter_field_name => $epay_paycenter_field_value ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $epay_paycenter_field_name ); ?>" value="<?php echo esc_attr( $epay_paycenter_field_value ); ?>" />
		<?php endforeach; ?>
		<noscript>
			<p><?php esc_html_e( 'JavaScript is required to complete the payment automatically. Click the button below to continue to the secure payment page.', 'resilient-gateway-for-epay-paycenter' ); ?></p>
			<button type="submit" class="button alt">
				<?php esc_html_e( 'Continue to secure payment', 'resilient-gateway-for-epay-paycenter' ); ?>
			</button>
		</noscript>
	</form>

	<p class="epay-paycenter-redirect__cancel">
		<a href="<?php echo esc_url( $order->get_cancel_order_url_raw() ); ?>">
			<?php esc_html_e( 'Cancel and return to checkout', 'resilient-gateway-for-epay-paycenter' ); ?>
		</a>
	</p>
</div>
<?php
// Auto-submit is handled by the enqueued epay-paycenter-redirect script.
