<?php
/**
 * Session-independent customer notices for payment results.
 *
 * @package EpayPaycenter
 *
 * Added by the fork contributors on 2026-09-06; modified on 2026-09-07.
 * See NOTICE.md for attribution.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queues and drains notices across the bank callback redirect chain.
 */
final class Epay_Paycenter_Order_Notices {

	/** Stable transient-key prefix used by existing queued notices. */
	const TRANSIENT_PREFIX = 'epay_paycenter_notice_';

	/**
	 * Keep a cancelled order out of the non-payable order-pay endpoint.
	 *
	 * @param WC_Order $order Authenticated cancellation's order.
	 */
	public static function checkout_return_url( WC_Order $order ): string {
		return add_query_arg(
			array(
				'epay_return' => $order->get_id(),
				'key'         => $order->get_order_key(),
			),
			wc_get_checkout_url()
		);
	}

	/** Transfer the notice to the returning browser before checkout/cart redirects. */
	public static function checkout_return(): void {
		// This read-only return is authorised by the order key, not a logged-in nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_GET['epay_return'] ) && is_scalar( $_GET['epay_return'] ) ? absint( $_GET['epay_return'] ) : 0;
		if ( ! $order_id || ! self::has_order_key( $order_id ) ) {
			return;
		}
		self::drain_payment_notice( $order_id );
		// A lost bank-return session gets only this notice, never another cart's data.
		$session = WC()->session;
		if ( $session instanceof WC_Session_Handler ) {
			$session->set_customer_session_cookie( true );
		}
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Require the same bearer key carried by WooCommerce payment links.
	 *
	 * @param int $order_id Order id.
	 */
	private static function has_order_key( int $order_id ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key   = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$order = wc_get_order( $order_id );
		return $order instanceof WC_Order && EPAY_PAYCENTER_GATEWAY_ID === $order->get_payment_method()
			&& '' !== $key && hash_equals( $order->get_order_key(), $key );
	}

	/**
	 * Queue a notice for the order's payment or thank-you page.
	 *
	 * @param int    $order_id Order id.
	 * @param string $message  Notice body, already translated.
	 * @param string $type     WooCommerce notice type.
	 * @return void
	 */
	public static function queue( $order_id, $message, $type = 'error' ) {
		$order_id = absint( $order_id );
		$message  = (string) $message;
		if ( ! $order_id || '' === $message ) {
			return;
		}

		if ( ! in_array( $type, array( 'error', 'notice', 'success' ), true ) ) {
			$type = 'error';
		}

		set_transient(
			self::TRANSIENT_PREFIX . $order_id,
			array(
				'message' => $message,
				'type'    => $type,
			),
			15 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Drain a queued notice into the pay-for-order page's notice stack.
	 *
	 * An identical session notice is not added twice, but the transient is
	 * consumed in either case.
	 *
	 * @return void
	 */
	public static function render_payment_page() {
		global $wp;

		$order_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
		if ( ! $order_id || ! self::has_order_key( $order_id ) ) {
			return;
		}
		self::drain_payment_notice( $order_id );
	}

	/**
	 * Consume one queued notice after verifying the destination order key.
	 *
	 * @param int $order_id Order id.
	 */
	private static function drain_payment_notice( int $order_id ): void {
		$key     = self::TRANSIENT_PREFIX . $order_id;
		$pending = get_transient( $key );
		if ( ! is_array( $pending ) || ! isset( $pending['message'] ) || ! is_string( $pending['message'] ) || '' === $pending['message'] ) {
			return;
		}

		$message         = (string) $pending['message'];
		$type            = isset( $pending['type'] ) && in_array( $pending['type'], array( 'error', 'notice', 'success' ), true ) ? $pending['type'] : 'error';
		$already_present = function_exists( 'wc_has_notice' ) && wc_has_notice( $message, $type );
		if ( ! $already_present && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $type );
		}

		delete_transient( $key );
	}

	/**
	 * Print a queued notice on an already-paid order's thank-you page.
	 *
	 * @param int $order_id Order id supplied by WooCommerce.
	 * @return void
	 */
	public static function render_thankyou_page( $order_id ) {
		$order_id = absint( $order_id );
		if ( ! $order_id || ! self::has_order_key( $order_id ) ) {
			return;
		}

		$key     = self::TRANSIENT_PREFIX . $order_id;
		$pending = get_transient( $key );
		if ( ! is_array( $pending ) || ! isset( $pending['message'] ) || ! is_string( $pending['message'] ) || '' === $pending['message'] ) {
			return;
		}

		delete_transient( $key );
		if ( function_exists( 'wc_print_notice' ) ) {
			$type = isset( $pending['type'] ) && in_array( $pending['type'], array( 'error', 'notice', 'success' ), true ) ? $pending['type'] : 'notice';
			wc_print_notice( (string) $pending['message'], $type );
		}
	}
}
