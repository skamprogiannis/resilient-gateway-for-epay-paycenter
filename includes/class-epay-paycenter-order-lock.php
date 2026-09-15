<?php
/**
 * Serialize local payment mutations without holding a lock during bank requests.
 *
 * @package EpayPaycenter
 */

defined( 'ABSPATH' ) || exit;

/** Shared by callbacks, recovery and stock release. */
final class Epay_Paycenter_Order_Lock {

	/**
	 * Wait briefly for another local mutation. Database disconnects release ownership.
	 *
	 * @param int $order_id Order ID.
	 * @return bool Whether this connection owns the lock.
	 */
	public static function acquire( int $order_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-owned synchronization cannot use the object cache.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', self::key( $order_id ) ) );
	}

	/**
	 * Release only the lock owned by this database connection.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function release( int $order_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::key( $order_id ) ) );
	}

	/**
	 * Discard request-local snapshots before reading inside the mutation lock.
	 *
	 * @param int $order_id Order ID.
	 * @return WC_Order|null Fresh order, or null if it no longer exists.
	 */
	public static function read_order( int $order_id ): ?WC_Order {
		clean_post_cache( $order_id );
		if ( class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
		}
		$store = WC_Data_Store::load( 'order' );
		// HPOS data caching is separate from order-object caching in newer WooCommerce.
		if ( $store->has_callable( 'clear_cached_data' ) ) {
			$store->clear_cached_data( array( $order_id ) );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$order->read_meta_data( true );
		return $order;
	}

	/**
	 * Keep the server-wide lock name within MySQL's 64-byte limit.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private static function key( int $order_id ): string {
		global $wpdb;
		return hash( 'sha256', 'epay-order|' . DB_NAME . '|' . $wpdb->prefix . '|' . $order_id );
	}
}
