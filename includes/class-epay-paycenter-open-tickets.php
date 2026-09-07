<?php
/**
 * Open payment-attempt metadata.
 *
 * @package EpayPaycenter
 *
 * Added by the fork contributors on 2026-09-06; modified on 2026-09-07.
 * See NOTICE.md for attribution.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores, reads, resolves, and clears the secrets for open payment attempts.
 *
 * @phpstan-type OpenTicketSecrets array{ticket:string,cancel:string}
 */
final class Epay_Paycenter_Open_Tickets {

	/** Maximum number of concurrent open attempts retained per order. */
	const MAX_OPEN_TICKETS = 5;

	/** Repeatable order-meta key for independently persisted attempts. */
	const META_KEY = '_epay_open_ticket';

	/**
	 * Persist one attempt and prune only the oldest independently stored rows.
	 *
	 * Each attempt is an independent metadata row, so overlapping receipt
	 * requests cannot replace each other's reference and secrets. Saving before
	 * the forced metadata read preserves that insert-only race behaviour and
	 * lets pruning observe rows written by concurrent requests.
	 *
	 * @param WC_Order $order        Order carrying the latest compatibility meta.
	 * @param string   $reference    MerchantReference for this attempt.
	 * @param string   $tran_ticket  TranTicket used to authenticate its callback.
	 * @param string   $cancel_token Token used to authenticate its cancel link.
	 * @return void
	 */
	public static function store( $order, $reference, $tran_ticket, $cancel_token ) {
		$reference = (string) $reference;
		if ( '' !== $reference ) {
			$order->add_meta_data(
				self::META_KEY,
				array(
					'reference' => $reference,
					'ticket'    => (string) $tran_ticket,
					'cancel'    => (string) $cancel_token,
				),
				false
			);
		}

		$order->save();
		self::prune( $order );
	}

	/**
	 * Return every open attempt as a reference-to-secrets map.
	 *
	 * The serialized map and single-value metadata are supported migration
	 * contracts for payments issued by earlier releases and payments in flight
	 * during an upgrade. New attempts are read in metadata-id order; a repeated
	 * reference refreshes its position in the returned map.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,OpenTicketSecrets>
	 */
	public static function all( $order ) {
		$map    = array();
		$legacy = $order->get_meta( '_epay_open_tickets', true );
		if ( is_array( $legacy ) ) {
			foreach ( $legacy as $reference => $data ) {
				if ( ! is_string( $reference ) || '' === $reference || ! is_array( $data ) ) {
					continue;
				}
				$map[ $reference ] = array(
					'ticket' => isset( $data['ticket'] ) && is_string( $data['ticket'] ) ? $data['ticket'] : '',
					'cancel' => isset( $data['cancel'] ) && is_string( $data['cancel'] ) ? $data['cancel'] : '',
				);
			}
		}

		$rows = $order->get_meta( self::META_KEY, false, 'edit' );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! $row instanceof WC_Meta_Data ) {
					continue;
				}
				$data      = is_array( $row->value ) ? $row->value : array();
				$reference = isset( $data['reference'] ) && is_string( $data['reference'] ) ? $data['reference'] : '';
				if ( '' === $reference ) {
					continue;
				}
				unset( $map[ $reference ] );
				$map[ $reference ] = array(
					'ticket' => isset( $data['ticket'] ) && is_string( $data['ticket'] ) ? $data['ticket'] : '',
					'cancel' => isset( $data['cancel'] ) && is_string( $data['cancel'] ) ? $data['cancel'] : '',
				);
			}
		}

		$latest_reference = self::string_meta( $order, '_epay_merchant_reference' );
		if ( '' !== $latest_reference && ! isset( $map[ $latest_reference ] ) ) {
			$map[ $latest_reference ] = array(
				'ticket' => self::string_meta( $order, '_epay_tran_ticket' ),
				'cancel' => self::string_meta( $order, '_epay_cancel_token' ),
			);
		}

		return $map;
	}

	/**
	 * Read legacy scalar metadata without coercing malformed stored values.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $key Metadata key.
	 * @return string
	 */
	private static function string_meta( $order, $key ) {
		$value = $order->get_meta( $key, true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Constant-time membership check across every open cancel token.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $token Token supplied on the cancel backlink.
	 * @return bool
	 */
	public static function verify_cancel_token( $order, $token ) {
		return '' !== self::reference_for_cancel_token( $order, $token );
	}

	/**
	 * Resolve a cancel token to its issued MerchantReference.
	 *
	 * Every attempt is scanned even after a match so timing does not reveal
	 * which attempt supplied the token. Each comparison uses hash_equals().
	 *
	 * @param WC_Order $order Order.
	 * @param string   $token Token supplied on the cancel backlink.
	 * @return string Matching MerchantReference, or an empty string.
	 */
	public static function reference_for_cancel_token( $order, $token ) {
		$token = (string) $token;
		if ( '' === $token ) {
			return '';
		}

		$matched_reference = '';
		foreach ( self::all( $order ) as $reference => $data ) {
			$cancel = $data['cancel'];
			if ( '' !== $cancel && hash_equals( $cancel, $token ) ) {
				$matched_reference = (string) $reference;
			}
		}

		return $matched_reference;
	}

	/**
	 * Clear all open-attempt secrets while retaining the latest reference.
	 *
	 * The caller remains responsible for saving the order after any surrounding
	 * paid-state or audit metadata has been updated.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function clear( $order ) {
		$order->delete_meta_data( '_epay_open_tickets' );
		$order->delete_meta_data( self::META_KEY );
		$order->delete_meta_data( '_epay_tran_ticket' );
		$order->delete_meta_data( '_epay_cancel_token' );
	}

	/**
	 * Remove only the oldest independently persisted attempts.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private static function prune( $order ) {
		$order->read_meta_data( true );
		$rows = $order->get_meta( self::META_KEY, false, 'edit' );
		if ( ! is_array( $rows ) || count( $rows ) <= self::MAX_OPEN_TICKETS ) {
			return;
		}
		$rows = array_filter(
			$rows,
			static function ( $row ): bool {
				return $row instanceof WC_Meta_Data;
			}
		);
		if ( count( $rows ) <= self::MAX_OPEN_TICKETS ) {
			return;
		}

		usort(
			$rows,
			static function ( WC_Meta_Data $left, WC_Meta_Data $right ): int {
				return (int) $left->id <=> (int) $right->id;
			}
		);

		$remove = count( $rows ) - self::MAX_OPEN_TICKETS;
		foreach ( array_slice( $rows, 0, $remove ) as $row ) {
			if ( ! empty( $row->id ) ) {
				$order->delete_meta_data_by_mid( (int) $row->id );
			}
		}
		$order->save_meta_data();
	}
}
