<?php
/**
 * Read-only, non-secret payment evidence for order administration.
 *
 * @package EpayPaycenter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Binds transaction identifiers to their attempt and source before linking out.
 *
 * @phpstan-type OrderEvidence array{reference:string, issued_at:string, amount:string, currency_code:int, bank_status:string, response_code:string, method:string, last_checked:string, recovery_state:string, detail_url:string, search_url:string, settled:bool}
 */
final class Epay_Paycenter_Order_Evidence {

	/**
	 * Query only displayable fields, never ticket or cancellation secrets.
	 *
	 * @param WC_Order $order Current order.
	 * @return list<OrderEvidence>
	 * @throws RuntimeException If the attempt history cannot be read.
	 */
	public static function for_order( WC_Order $order ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT merchant_reference, created_at, amount, currency_code, follow_up_state, last_checked_at, follow_up_result_code, follow_up_response_code, follow_up_status_flag, follow_up_transaction_id, follow_up_payment_method FROM %i WHERE order_id = %d ORDER BY id DESC', $wpdb->prefix . 'epay_paycenter_tickets', $order->get_id() ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( 'Could not read order payment evidence.' );
		}
		$meta_reference = (string) $order->get_meta( '_epay_merchant_reference', true );
		$settled        = (string) $order->get_meta( '_epay_follow_up_settled_reference', true );
		$references     = array_column( $rows, 'merchant_reference' );
		// Metadata-only installations have no auditable issue date or attempt amount.
		if ( '' !== $meta_reference && ! in_array( $meta_reference, $references, true ) ) {
			$rows[] = array( 'merchant_reference' => $meta_reference );
		}
		$result = array();
		foreach ( $rows as $row ) {
			$reference = (string) $row['merchant_reference'];
			$matches   = '' !== $meta_reference && hash_equals( $meta_reference, $reference );
			$method    = (string) ( $row['follow_up_payment_method'] ?? '' );
			if ( '' === $method && $matches ) {
				$method = (string) $order->get_meta( '_epay_payment_method', true );
			}
			$method         = in_array( strtolower( $method ), array( 'card', 'iris' ), true ) ? strtolower( $method ) : '';
			$bank_status    = (string) ( $row['follow_up_status_flag'] ?? '' );
			$response_code  = (string) ( $row['follow_up_response_code'] ?? '' );
			$transaction_id = '';
			$has_follow_up  = '0' === (string) ( $row['follow_up_result_code'] ?? '' ) && '' !== $bank_status;
			if ( $has_follow_up ) {
				// FOLLOW_UP carries the AdminTool ID, unlike the long IRIS callback ID.
				$transaction_id = (string) ( $row['follow_up_transaction_id'] ?? '' );
			} elseif ( $matches ) {
				$bank_status   = (string) $order->get_meta( '_epay_status_flag', true );
				$response_code = (string) $order->get_meta( '_epay_response_code', true );
				if ( 'card' === $method && '' !== (string) $order->get_meta( '_epay_last_callback_at', true ) ) {
					$transaction_id = (string) $order->get_meta( '_epay_transaction_id', true );
				}
			}
			$detail_url = '';
			if ( 1 === preg_match( '/^[0-9]+$/D', $transaction_id ) ) {
				if ( 'iris' === $method && $has_follow_up ) {
					$detail_url = 'https://paycenter.piraeusbank.gr/AdminTool/IRISAdminToolDetails.aspx?id=8&mid=&ias=1&tid=' . rawurlencode( $transaction_id ) . '&aa=1';
				} elseif ( 'card' === $method && 'Success' === $bank_status && in_array( $response_code, array( '0', '00', '8', '08', '10', '16' ), true ) ) {
					// Only the approved card detail route has been verified independently of a search.
					$detail_url = 'https://paycenter.piraeusbank.gr/AdminTool/AdminToolDetails.aspx?id=2&mid=-1&ias=1&tid=' . rawurlencode( $transaction_id ) . '&aa=1';
				}
			}
			$search_url = 'https://paycenter.piraeusbank.gr/AdminTool/';
			if ( '' !== $method ) {
				$search_url .= ( 'iris' === $method ? 'IRISAdminTool.aspx' : 'AdminTool.aspx' ) . '?id=6';
			}
			$result[] = array(
				'reference'      => $reference,
				'issued_at'      => (string) ( $row['created_at'] ?? '' ),
				'amount'         => (string) ( $row['amount'] ?? '' ),
				'currency_code'  => (int) ( $row['currency_code'] ?? 0 ),
				'bank_status'    => $bank_status,
				'response_code'  => $response_code,
				'method'         => $method,
				'last_checked'   => (string) ( $row['last_checked_at'] ?? '' ),
				'recovery_state' => (string) ( $row['follow_up_state'] ?? '' ),
				'detail_url'     => $detail_url,
				'search_url'     => $search_url,
				'settled'        => '' !== $settled && hash_equals( $settled, $reference ),
			);
		}
		return $result;
	}
}
