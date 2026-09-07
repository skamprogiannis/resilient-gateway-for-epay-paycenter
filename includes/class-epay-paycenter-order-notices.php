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
		if ( ! $order_id ) {
			return;
		}

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
		if ( ! $order_id ) {
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
