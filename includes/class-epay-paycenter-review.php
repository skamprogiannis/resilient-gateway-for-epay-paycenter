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
 */
final class Epay_Paycenter_Review {
	const REPORT_OPTION = 'epay_paycenter_reconcile_report';

	/** Register only administrative presentation and acknowledgement hooks. */
	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_epay_paycenter_review', array( __CLASS__, 'acknowledge' ) );
	}

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
	 */
	private static function items(): array {
		$report = get_option( self::REPORT_OPTION );
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

	/** Persist acknowledgement; never delete the underlying bank evidence. */
	public static function acknowledge(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You cannot review payments.', 'resilient-gateway-for-epay-paycenter' ), '', array( 'response' => 403 ) );
		}
		// The item-specific nonce below authorises this POST.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$key = isset( $_POST['review_key'] ) && is_string( $_POST['review_key'] ) ? sanitize_text_field( wp_unslash( $_POST['review_key'] ) ) : '';
		check_admin_referer( 'epay_paycenter_review_' . $key );
		$items = self::items();
		if ( isset( $items[ $key ] ) && '' === $items[ $key ]['reviewed_at'] ) {
			$items[ $key ]['reviewed_at'] = current_time( 'mysql', true );
			$items[ $key ]['reviewed_by'] = get_current_user_id();
			if ( ! self::save_item( $key, $items[ $key ], true ) ) {
				wp_die( esc_html__( 'Review could not be saved. Please try again.', 'resilient-gateway-for-epay-paycenter' ) );
			}
		}
		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url( 'edit.php?post_type=shop_order' ) );
		exit;
	}

	/** Render a compact, screen-scoped queue using native WordPress controls. */
	public static function render(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// Read-only screen selection; no settings change occurs here.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings = 'woocommerce_page_wc-settings' === $screen->id && isset( $_GET['section'] ) && EPAY_PAYCENTER_GATEWAY_ID === $_GET['section'];
		if ( ! $settings && ! in_array( $screen->id, array( 'edit-shop_order', 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}
		$groups = array(
			'active'     => array(),
			'historical' => array(),
			'reviewed'   => array(),
		);
		foreach ( self::items() as $key => $item ) {
			$group = self::group( $item );
			if ( 'resolved' !== $group ) {
				$groups[ $group ][ $key ] = $item;
			}
		}
		if ( empty( $groups['active'] ) && empty( $groups['historical'] ) && ( ! $settings || empty( $groups['reviewed'] ) ) ) {
			return;
		}
		echo '<div class="notice epay-paycenter-review ' . ( $groups['active'] ? 'notice-warning' : 'notice-info' ) . '">';
		if ( $groups['active'] ) {
			echo '<p><strong>' . esc_html__( 'ePay: payments to review', 'resilient-gateway-for-epay-paycenter' ) . '</strong></p>';
			self::render_items( $groups['active'] );
		}
		if ( $groups['historical'] ) {
			echo '<details><summary>' . esc_html__( 'Historical checks', 'resilient-gateway-for-epay-paycenter' ) . ' (' . count( $groups['historical'] ) . ')</summary>';
			echo '<p>' . esc_html__( 'These attempts were first checked after the recovery window. Review them in AdminTool when reconciling older orders.', 'resilient-gateway-for-epay-paycenter' ) . '</p>';
			self::render_items( $groups['historical'] );
			echo '</details>';
		}
		if ( $settings && $groups['reviewed'] ) {
			echo '<details><summary>' . esc_html__( 'Reviewed cases', 'resilient-gateway-for-epay-paycenter' ) . ' (' . count( $groups['reviewed'] ) . ')</summary>';
			self::render_items( $groups['reviewed'] );
			echo '</details>';
		}
		echo '</div>';
	}

	/**
	 * Retire resolved checks, but never infer bank settlement from order status alone.
	 *
	 * @param array $item Stored exception.
	 * @phpstan-param ReviewItem $item
	 * @return 'active'|'historical'|'reviewed'|'resolved'
	 */
	private static function group( array $item ): string {
		if ( '' !== $item['reviewed_at'] ) {
			return 'reviewed';
		}
		if ( in_array( $item['type'], array( 'unresolved', 'settlement_error' ), true ) && '' !== $item['reference'] ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT follow_up_state, follow_up_attempts, created_at, last_checked_at FROM %i WHERE order_id = %d AND merchant_reference = %s ORDER BY id DESC LIMIT 1', $wpdb->prefix . 'epay_paycenter_tickets', $item['order_id'], $item['reference'] ), ARRAY_A );
			if ( is_array( $row ) ) {
				if ( 'unresolved' === $item['type'] && in_array( $row['follow_up_state'], array( 'paid', 'local_paid', 'declined' ), true ) ) {
					return 'resolved';
				}
				$order = wc_get_order( $item['order_id'] );
				if ( 'settlement_error' === $item['type'] && 'paid' === $row['follow_up_state'] && $order instanceof WC_Order && $order->is_paid() ) {
					return 'resolved';
				}
				if ( 'unresolved' === $item['type'] && 1 === (int) $row['follow_up_attempts']
					&& strtotime( (string) $row['last_checked_at'] . ' UTC' ) - strtotime( (string) $row['created_at'] . ' UTC' ) >= 2 * DAY_IN_SECONDS ) {
					return 'historical';
				}
			}
		}
		return 'unresolved' === $item['type'] && $item['historical'] ? 'historical' : 'active';
	}

	/**
	 * Explain the concrete reason and provide an explicit review action.
	 *
	 * @param array $items Visible exceptions.
	 * @phpstan-param array<string,ReviewItem> $items
	 */
	private static function render_items( array $items ): void {
		$reasons = array(
			'unresolved'           => __( 'No final bank result. Check this reference in AdminTool before treating it as paid or unpaid.', 'resilient-gateway-for-epay-paycenter' ),
			'missing_paid'         => __( 'Bank confirmed payment, but the order is missing or cannot be fulfilled. Account for the payment in AdminTool.', 'resilient-gateway-for-epay-paycenter' ),
			'trash_paid'           => __( 'Bank confirmed payment for a trashed order. Review the payment before restoring or refunding the order.', 'resilient-gateway-for-epay-paycenter' ),
			'late_paid'            => __( 'Payment arrived after stock was released. Confirm stock and fulfilment before dispatch.', 'resilient-gateway-for-epay-paycenter' ),
			'double_paid'          => __( 'More than one payment was detected. Compare the transactions in AdminTool before arranging a refund.', 'resilient-gateway-for-epay-paycenter' ),
			'settlement_error'     => __( 'Bank confirmed payment, but the order update failed. Check the gateway log and order status.', 'resilient-gateway-for-epay-paycenter' ),
			'database_error'       => __( 'A bank result could not be saved. Check the gateway log and AdminTool.', 'resilient-gateway-for-epay-paycenter' ),
			'reconciliation_error' => __( 'The bank check could not finish. Check the gateway log and AdminTool.', 'resilient-gateway-for-epay-paycenter' ),
		);
		echo '<ul>';
		foreach ( $items as $key => $item ) {
			$order = wc_get_order( $item['order_id'] );
			$label = '#' . $item['order_id'] . ( '' !== $item['reference'] ? ' — ' . $item['reference'] : '' );
			echo '<li><p>';
			if ( $order instanceof WC_Order ) {
				echo '<a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html( $label ) . '</a>';
			} else {
				echo esc_html( $label );
			}
			echo ' — ' . esc_html( $reasons[ $item['type'] ] ?? __( 'Review this payment in AdminTool.', 'resilient-gateway-for-epay-paycenter' ) ) . '</p>';
			if ( '' === $item['reviewed_at'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="epay_paycenter_review"><input type="hidden" name="review_key" value="' . esc_attr( $key ) . '">';
				wp_nonce_field( 'epay_paycenter_review_' . $key );
				echo '<button class="button button-small" type="submit">' . esc_html__( 'Mark reviewed', 'resilient-gateway-for-epay-paycenter' ) . '</button></form>';
			} else {
				echo '<p>' . esc_html__( 'Reviewed (UTC):', 'resilient-gateway-for-epay-paycenter' ) . ' ' . esc_html( $item['reviewed_at'] ) . '</p>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}
}
