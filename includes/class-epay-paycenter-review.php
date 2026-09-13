<?php
/**
 * Staff review queue for payment exceptions, separate from bank/order state.
 *
 * @package EpayPaycenter
 * Added by the fork contributors on 2026-09-07. See NOTICE.md.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records exceptions and acknowledges human review without altering payments.
 *
 * @phpstan-type ReviewItem array{type:string, order_id:int, reference:string, historical:bool, reviewed_at:string, reviewed_by:int}
 * @phpstan-type ReviewTicket array{follow_up_state:string, follow_up_attempts:int, created_at:string, last_checked_at:string}
 * @phpstan-type ReviewGroup 'payments'|'problems'|'unconfirmed'|'historical'|'reviewed'|'resolved'
 * @phpstan-type ReviewCase array{item:ReviewItem, group:ReviewGroup}
 */
final class Epay_Paycenter_Review {
	const REPORT_OPTION = 'epay_paycenter_reconcile_report';

	/**
	 * Preserve each exception until reviewed, including missing-order payments.
	 *
	 * @param string $type Exception category.
	 * @param int    $order_id Order id, possibly deleted.
	 * @param string $reference Attempt reference, if available.
	 * @param bool   $historical Whether checking began after the recovery window.
	 * @throws RuntimeException If the review record cannot be persisted.
	 */
	public static function record( string $type, int $order_id, string $reference = '', bool $historical = false ): void {
		$items     = self::items();
		$reference = substr( sanitize_text_field( $reference ), 0, 50 );
		// PHP 7.4 can return false for an empty substring.
		if ( false === $reference ) {
			$reference = '';
		}
		$key = sanitize_key( $type ) . ':' . absint( $order_id );
		if ( '' !== $reference ) {
			$key .= ':' . substr( hash( 'sha256', $reference ), 0, 12 );
		}
		if ( isset( $items[ $key ] ) ) {
			return;
		}
		$items[ $key ] = array(
			'type'        => sanitize_key( $type ),
			'order_id'    => absint( $order_id ),
			'reference'   => $reference,
			'historical'  => $historical,
			'reviewed_at' => '',
			'reviewed_by' => 0,
		);
		if ( ! self::save_item( $key, $items[ $key ], false ) ) {
			throw new RuntimeException( 'Could not persist the ePay payment review record.' );
		}
	}

	/**
	 * Merge one change without overwriting another worker's or employee's update.
	 *
	 * @param string $key Stable exception identity.
	 * @param array  $item Exception to insert or acknowledge.
	 * @param bool   $review Whether to acknowledge an existing exception.
	 * @phpstan-param ReviewItem $item
	 */
	private static function save_item( string $key, array $item, bool $review ): bool {
		global $wpdb;
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			$stored = get_option( self::REPORT_OPTION, false );
			$items  = is_array( $stored ) && isset( $stored['items'] ) && is_array( $stored['items'] ) ? $stored['items'] : array();
			if ( isset( $items[ $key ] ) ) {
				if ( ! $review || ! empty( $items[ $key ]['reviewed_at'] ) ) {
					return true;
				}
			} elseif ( $review ) {
				return false;
			}
			$items[ $key ] = $item;
			$report        = array(
				'time'  => current_time( 'mysql', true ),
				'items' => $items,
			);
			if ( false === $stored ) {
				if ( add_option( self::REPORT_OPTION, $report, '', false ) ) {
					return true;
				}
			} else {
				// Match the previous value so a concurrent update triggers a retry.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s', $wpdb->options, maybe_serialize( $report ), self::REPORT_OPTION, maybe_serialize( $stored ) ) );
				if ( false === $updated ) {
					return false;
				}
				if ( 1 === $updated ) {
					wp_cache_delete( self::REPORT_OPTION, 'options' );
					wp_cache_delete( 'alloptions', 'options' );
					return true;
				}
			}
			wp_cache_delete( self::REPORT_OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		return false;
	}

	/**
	 * Read both the original report and the acknowledged review format.
	 *
	 * @return array<string,ReviewItem>
	 * @throws RuntimeException If the report cannot be read reliably.
	 */
	private static function items(): array {
		global $wpdb;
		// A failed options read must not masquerade as an empty review queue.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::REPORT_OPTION ) );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not read the payment review report.' );
		}
		$report = null === $stored ? false : maybe_unserialize( $stored );
		$items  = is_array( $report ) && isset( $report['items'] ) && is_array( $report['items'] ) ? $report['items'] : array();
		$result = array();
		foreach ( $items as $key => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['type'], $item['order_id'] ) ) {
				continue;
			}
			$result[ (string) $key ] = array(
				'type'        => (string) $item['type'],
				'order_id'    => absint( $item['order_id'] ),
				'reference'   => (string) ( $item['reference'] ?? '' ),
				'historical'  => ! empty( $item['historical'] ),
				'reviewed_at' => (string) ( $item['reviewed_at'] ?? '' ),
				'reviewed_by' => absint( $item['reviewed_by'] ?? 0 ),
			);
		}
		return $result;
	}

	/**
	 * Retire resolved checks, but never infer bank settlement from order status alone.
	 *
	 * @param array         $item Stored exception.
	 * @phpstan-param ReviewItem $item
	 * @param array|null    $row Latest stored attempt, if it still exists.
	 * @param WC_Order|null $order Current order, if it still exists.
	 * @phpstan-param ReviewTicket|null $row
	 * @return 'payments'|'problems'|'unconfirmed'|'historical'|'reviewed'|'resolved'
	 */
	private static function group( array $item, ?array $row, ?WC_Order $order ): string {
		if ( '' !== $item['reviewed_at'] ) {
			return 'reviewed';
		}
		if ( in_array( $item['type'], array( 'unresolved', 'settlement_error' ), true ) && '' !== $item['reference'] ) {
			if ( is_array( $row ) ) {
				if ( 'unresolved' === $item['type'] && in_array( $row['follow_up_state'], array( 'paid', 'local_paid', 'declined' ), true ) ) {
					return 'resolved';
				}
				if ( 'settlement_error' === $item['type'] && 'paid' === $row['follow_up_state'] && $order instanceof WC_Order && $order->is_paid() ) {
					return 'resolved';
				}
				if ( 'unresolved' === $item['type'] && 1 === (int) $row['follow_up_attempts']
					&& strtotime( (string) $row['last_checked_at'] . ' UTC' ) - strtotime( (string) $row['created_at'] . ' UTC' ) >= 2 * DAY_IN_SECONDS ) {
					return 'historical';
				}
			}
		}
		if ( 'unresolved' === $item['type'] ) {
			return $item['historical'] ? 'historical' : 'unconfirmed';
		}
		return in_array( $item['type'], array( 'missing_paid', 'trash_paid', 'late_paid', 'double_paid', 'settlement_error', 'database_error' ), true ) ? 'payments' : 'problems';
	}

	/**
	 * Load attempt classifications in batches, rather than once per case.
	 *
	 * @param array $items Stored exceptions.
	 * @phpstan-param array<string,ReviewItem> $items
	 * @phpstan-return array<int,array<string,ReviewTicket>>
	 * @throws RuntimeException If the classification query fails.
	 */
	private static function tickets( array $items ): array {
		global $wpdb;
		$ids = array();
		foreach ( $items as $item ) {
			if ( '' === $item['reviewed_at'] && in_array( $item['type'], array( 'unresolved', 'settlement_error' ), true ) ) {
				$ids[] = $item['order_id'];
			}
		}
		$tickets = array();
		foreach ( array_chunk( array_unique( $ids ), 200 ) as $batch ) {
			$placeholders = implode( ',', array_fill( 0, count( $batch ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated integer placeholders are interpolated.
					"SELECT order_id, merchant_reference, follow_up_state, follow_up_attempts, created_at, last_checked_at FROM %i WHERE order_id IN ($placeholders) ORDER BY id ASC",
					array_merge( array( $wpdb->prefix . 'epay_paycenter_tickets' ), $batch )
				),
				ARRAY_A
			);
			if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
				throw new RuntimeException( 'Could not read review attempts.' );
			}
			foreach ( $rows as $row ) {
				$tickets[ (int) $row['order_id'] ][ (string) $row['merchant_reference'] ] = array(
					'follow_up_state'    => (string) $row['follow_up_state'],
					'follow_up_attempts' => (int) $row['follow_up_attempts'],
					'created_at'         => (string) $row['created_at'],
					'last_checked_at'    => (string) $row['last_checked_at'],
				);
			}
		}
		return $tickets;
	}


	/**
	 * Build a read-only snapshot; a failed read must never look like an empty queue.
	 *
	 * @param int $order_id Limit an order panel to its own evidence; zero reads all cases.
	 * @return array<string,ReviewCase>
	 */
	public static function cases( int $order_id = 0 ): array {
		$items = self::items();
		if ( $order_id ) {
			$items = array_filter(
				$items,
				static function ( array $item ) use ( $order_id ): bool {
					return $order_id === $item['order_id'];
				}
			);
		}
		$tickets = self::tickets( $items );
		$orders  = array();
		$cases   = array();
		foreach ( $items as $key => $item ) {
			$id = $item['order_id'];
			// Only settlement-error classification needs the order's current paid state.
			if ( 'settlement_error' === $item['type'] && ! array_key_exists( $id, $orders ) ) {
				$order         = wc_get_order( $id );
				$orders[ $id ] = $order instanceof WC_Order ? $order : null;
			}
			$order         = $orders[ $id ] ?? null;
			$cases[ $key ] = array(
				'item'  => $item,
				'group' => self::group( $item, $tickets[ $id ][ $item['reference'] ] ?? null, $order ),
			);
		}
		return $cases;
	}

	/**
	 * Acknowledge explicitly selected cases without changing payment evidence.
	 *
	 * @param array $keys Stable case keys.
	 * @param bool  $bulk Restrict a bulk request to routine cases.
	 * @phpstan-param list<string> $keys
	 * @return array{saved:list<string>, errors:array<string,string>}
	 */
	public static function acknowledge_keys( array $keys, bool $bulk ): array {
		$cases  = self::cases();
		$result = array(
			'saved'  => array(),
			'errors' => array(),
		);
		foreach ( array_unique( $keys ) as $key ) {
			$case = $cases[ $key ] ?? null;
			if ( ! $case || 'resolved' === $case['group'] ) {
				$result['errors'][ $key ] = __( 'This case is no longer available. Refresh the list.', 'resilient-gateway-for-epay-paycenter' );
				continue;
			}
			$item = $case['item'];
			if ( $bulk && ( 'unresolved' !== $item['type'] || ! in_array( $case['group'], array( 'unconfirmed', 'historical', 'reviewed' ), true ) ) ) {
				$result['errors'][ $key ] = __( 'Review this case individually.', 'resilient-gateway-for-epay-paycenter' );
				continue;
			}
			if ( '' === $item['reviewed_at'] ) {
				$item['reviewed_at'] = current_time( 'mysql', true );
				$item['reviewed_by'] = get_current_user_id();
			}
			if ( self::save_item( $key, $item, true ) ) {
				$result['saved'][] = $key;
			} else {
				$result['errors'][ $key ] = __( 'Review could not be saved. Please try again.', 'resilient-gateway-for-epay-paycenter' );
			}
		}
		return $result;
	}
}
