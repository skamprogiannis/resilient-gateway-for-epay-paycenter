<?php
/**
 * Persistent audit trail for issued Paycenter tickets.
 *
 * @package EpayPaycenter
 *
 * Customer backlinks are never treated as final bank outcomes.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps ticket-attempt bookkeeping separate from WooCommerce order state.
 */
final class Epay_Paycenter_Ticket_Audit {

	const STATUS_PENDING    = 'pending';
	const STATUS_SUCCEEDED  = 'succeeded';
	const STATUS_FAILED     = 'failed';
	const STATUS_CANCELLED  = 'cancelled';
	const STATUS_EXPIRED    = 'expired';
	const STATUS_SUPERSEDED = 'superseded';

	/**
	 * Record a trusted terminal outcome for one issued attempt.
	 *
	 * Audit failures must never interrupt the payment callback that owns the
	 * authoritative WooCommerce order transition.
	 *
	 * @param int    $order_id          WooCommerce order id.
	 * @param string $merchant_reference Issued Paycenter reference.
	 * @param string $status             Ticket audit status.
	 * @return bool Whether the audit row was updated.
	 */
	public static function mark( $order_id, $merchant_reference, $status ) {
		global $wpdb;

		$order_id           = absint( $order_id );
		$merchant_reference = (string) $merchant_reference;
		$status             = (string) $status;
		$allowed            = array( self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCELLED );
		if ( 0 === $order_id || '' === $merchant_reference || ! in_array( $status, $allowed, true ) ) {
			return false;
		}

		$table         = $wpdb->prefix . 'epay_paycenter_tickets';
		$where         = array(
			'order_id'           => $order_id,
			'merchant_reference' => $merchant_reference,
		);
		$where_formats = array( '%d', '%s' );
		if ( self::STATUS_SUCCEEDED !== $status ) {
			$where['status'] = self::STATUS_PENDING;
			$where_formats[] = '%s';
		}

		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = array(
			'status'     => $status,
			'updated_at' => $now,
		);
		if ( self::STATUS_SUCCEEDED === $status || self::STATUS_FAILED === $status ) {
			$data['follow_up_state']   = self::STATUS_SUCCEEDED === $status ? 'paid' : 'declined';
			$data['resolution_source'] = 'callback';
			$data['resolved_at']       = $now;
			$data['next_check_at']     = null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			$data,
			$where,
			array( '%s', '%s' ),
			$where_formats
		);

		if ( false === $result ) {
			Epay_Paycenter_Logger::error(
				'Could not update Paycenter ticket audit status',
				array(
					'order_id' => $order_id,
					'status'   => $status,
				)
			);
			return false;
		}

		return 0 < $result;
	}

	/**
	 * Settle a verified successful attempt and close its sibling attempts.
	 *
	 * @param int    $order_id           WooCommerce order id.
	 * @param string $merchant_reference Successful Paycenter reference.
	 * @return bool Whether the successful attempt and sibling closure persisted.
	 */
	public static function settle_success( $order_id, $merchant_reference ) {
		if ( ! self::mark( $order_id, $merchant_reference, self::STATUS_SUCCEEDED )
			&& ! self::has_status( $order_id, $merchant_reference, self::STATUS_SUCCEEDED ) ) {
			return false;
		}
		return self::mark_remaining_pending(
			$order_id,
			$merchant_reference,
			self::STATUS_SUPERSEDED
		);
	}

	/**
	 * Confirm an idempotent audit transition without weakening DB failures.
	 *
	 * @param int    $order_id           WooCommerce order id.
	 * @param string $merchant_reference Issued Paycenter reference.
	 * @param string $status             Expected ticket status.
	 * @return bool
	 */
	private static function has_status( $order_id, $merchant_reference, $status ) {
		global $wpdb;
		$table = $wpdb->prefix . 'epay_paycenter_tickets';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$current = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived from the trusted WordPress prefix.
				"SELECT status FROM {$table} WHERE order_id = %d AND merchant_reference = %s LIMIT 1",
				absint( $order_id ),
				(string) $merchant_reference
			)
		);
		if ( null === $current && '' !== (string) $wpdb->last_error ) {
			Epay_Paycenter_Logger::error(
				'Could not verify the persisted Paycenter ticket audit status.',
				array( 'order_id' => absint( $order_id ) )
			);
			return false;
		}
		return is_string( $current ) && hash_equals( (string) $status, $current );
	}

	/**
	 * Close pending attempts other than one selected reference.
	 *
	 * @param int    $order_id          WooCommerce order id.
	 * @param string $except_reference Reference to leave untouched.
	 * @param string $status            Replacement status.
	 * @return bool Whether the sibling update completed without a DB error.
	 */
	private static function mark_remaining_pending( $order_id, $except_reference, $status ) {
		global $wpdb;

		$order_id         = absint( $order_id );
		$except_reference = (string) $except_reference;
		if ( 0 === $order_id || '' === $except_reference || self::STATUS_SUPERSEDED !== $status ) {
			return false;
		}

		$table = $wpdb->prefix . 'epay_paycenter_tickets';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is derived from the trusted WordPress prefix.
				"UPDATE {$table}
				 SET status = %s, updated_at = %s
				 WHERE order_id = %d AND status = %s AND merchant_reference <> %s",
				$status,
				gmdate( 'Y-m-d H:i:s' ),
				$order_id,
				self::STATUS_PENDING,
				$except_reference
			)
		);
		// phpcs:enable

		if ( false === $result ) {
			Epay_Paycenter_Logger::error(
				'Could not close superseded Paycenter ticket audit rows',
				array( 'order_id' => $order_id )
			);
			return false;
		}
		return true;
	}

	/**
	 * Expire every still-pending attempt when WooCommerce cancels an order.
	 *
	 * A Paycenter cancel backlink marks its matching attempt first; this hook
	 * therefore expires only any other attempts that remained pending. The same
	 * hook covers WooCommerce's unpaid-order timeout and manual cancellation.
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return void
	 */
	public static function expire_pending_for_cancelled_order( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( 0 === $order_id ) {
			return;
		}

		$table = $wpdb->prefix . 'epay_paycenter_tickets';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			array(
				'status'     => self::STATUS_EXPIRED,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'order_id' => $order_id,
				'status'   => self::STATUS_PENDING,
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( false === $result ) {
			Epay_Paycenter_Logger::error(
				'Could not expire pending Paycenter ticket audit rows',
				array( 'order_id' => $order_id )
			);
		}
	}
}
