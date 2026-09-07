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
 * @phpstan-import-type RecoveryStatus from Epay_Paycenter_Reconciliation
 */
final class Epay_Paycenter_Review {
	const REPORT_OPTION = 'epay_paycenter_reconcile_report';

	/**
	 * Register administrative hooks with a read-only recovery status provider.
	 *
	 * @param callable $read_status Recovery status provider.
	 * @phpstan-param callable():RecoveryStatus $read_status
	 */
	public static function init( callable $read_status ): void {
		add_action(
			'admin_notices',
			static function () use ( $read_status ): void {
				self::render( $read_status );
			}
		);
		add_action( 'admin_post_epay_paycenter_review', array( __CLASS__, 'acknowledge' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/** Load presentation styles only where employees can review payments. */
	public static function enqueue_assets(): void {
		if ( 'none' !== self::screen_context() ) {
			wp_enqueue_style( 'epay-paycenter-review', EPAY_PAYCENTER_PLUGIN_URL . 'assets/css/epay-paycenter-review.css', array(), EPAY_PAYCENTER_VERSION );
		}
	}

	/**
	 * Restrict both the panel and its assets to staff order-management screens.
	 *
	 * @return 'none'|'settings'|'orders'
	 */
	private static function screen_context(): string {
		$screen = get_current_screen();
		if ( ! $screen || ! current_user_can( 'manage_woocommerce' ) ) {
			return 'none';
		}
		// Read-only screen selection; no settings change occurs here.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'woocommerce_page_wc-settings' === $screen->id && isset( $_GET['section'] ) && EPAY_PAYCENTER_GATEWAY_ID === $_GET['section'] ) {
			return 'settings';
		}
		return in_array( $screen->id, array( 'edit-shop_order', 'shop_order', 'woocommerce_page_wc-orders' ), true ) ? 'orders' : 'none';
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

	/**
	 * Render a compact, screen-scoped queue using native WordPress controls.
	 *
	 * @param callable $read_status Recovery status provider.
	 * @phpstan-param callable():RecoveryStatus $read_status
	 */
	public static function render( callable $read_status ): void {
		$context = self::screen_context();
		if ( 'none' === $context ) {
			return;
		}
		$settings = 'settings' === $context;
		$groups   = array_fill_keys( array( 'payments', 'problems', 'unconfirmed', 'historical', 'reviewed' ), array() );
		try {
			$items   = self::items();
			$tickets = self::tickets( $items );
			$status  = $read_status();
		} catch ( RuntimeException $error ) {
			echo '<div class="notice notice-warning epay-paycenter-review"><p>' . esc_html__( 'Payment review status is unavailable. Ask the site administrator to check the gateway log.', 'resilient-gateway-for-epay-paycenter' ) . '</p></div>';
			Epay_Paycenter_Logger::error( 'Could not read payment review status.' );
			return;
		}
		$orders = array();
		foreach ( $items as $key => $item ) {
			$order_id = $item['order_id'];
			if ( ! array_key_exists( $order_id, $orders ) ) {
				$order               = wc_get_order( $order_id );
				$orders[ $order_id ] = $order instanceof WC_Order ? $order : null;
			}
			$group = self::group( $item, $tickets[ $order_id ][ $item['reference'] ] ?? null, $orders[ $order_id ] );
			if ( 'resolved' !== $group ) {
				$groups[ $group ][ $key ] = $item;
			}
		}
		$count   = count( $groups['payments'] ) + count( $groups['problems'] ) + count( $groups['unconfirmed'] ) + count( $groups['historical'] );
		$warning = $groups['payments'] || $groups['problems'] || null === $status['awaiting']
			|| ( $status['enabled'] && ( $status['overdue'] || ! $status['scheduled'] || ! empty( $status['last_run']['errors'] ) ) );
		echo '<div class="notice epay-paycenter-review ' . ( $warning ? 'notice-warning' : 'notice-info' ) . '">';
		echo '<p><strong>' . esc_html__( 'ePay: payment reviews', 'resilient-gateway-for-epay-paycenter' ) . '</strong></p>';
		self::render_status( $status, $count );
		$labels = array(
			'payments'    => __( 'Payment discrepancies', 'resilient-gateway-for-epay-paycenter' ),
			'problems'    => __( 'Check problems', 'resilient-gateway-for-epay-paycenter' ),
			'unconfirmed' => __( 'Unconfirmed attempts', 'resilient-gateway-for-epay-paycenter' ),
			'historical'  => __( 'Historical checks', 'resilient-gateway-for-epay-paycenter' ),
			'reviewed'    => __( 'Reviewed cases', 'resilient-gateway-for-epay-paycenter' ),
		);
		$help   = array(
			'payments'    => __( 'The bank confirmed payment. Review the discrepancy before fulfilment. Marking a case reviewed does not change the payment or order.', 'resilient-gateway-for-epay-paycenter' ),
			'problems'    => __( 'Ask the site administrator to check the gateway log and resolve these technical problems.', 'resilient-gateway-for-epay-paycenter' ),
			'unconfirmed' => __( 'Automatic checks ended without a final bank result. Check these references in AdminTool, then mark each case reviewed. An unconfirmed result does not prove payment or failure.', 'resilient-gateway-for-epay-paycenter' ),
			'historical'  => __( 'These attempts were first checked after the recovery window. Review them in AdminTool when reconciling older orders.', 'resilient-gateway-for-epay-paycenter' ),
		);
		foreach ( $groups as $group => $group_items ) {
			if ( ! $group_items || ( 'reviewed' === $group && ! $settings ) ) {
				continue;
			}
			echo '<details' . ( in_array( $group, array( 'payments', 'problems' ), true ) ? ' open' : '' ) . '><summary>' . esc_html( $labels[ $group ] ) . ' (' . count( $group_items ) . ')</summary>';
			if ( isset( $help[ $group ] ) ) {
				echo '<p>' . esc_html( $help[ $group ] ) . '</p>';
			}
			self::render_items( $group_items, $orders );
			echo '</details>';
		}
		echo '</div>';
	}

	/**
	 * Distinguish configured recovery, queued attempts, and actual worker runs.
	 *
	 * @param array $status Read-only recovery snapshot.
	 * @param int   $count Unreviewed cases, not distinct orders.
	 * @phpstan-param RecoveryStatus $status
	 */
	private static function render_status( array $status, int $count ): void {
		$enabled = $status['verified']
			? ( $status['enabled'] ? __( 'Automatic recovery: Enabled', 'resilient-gateway-for-epay-paycenter' ) : __( 'Automatic recovery: Disabled', 'resilient-gateway-for-epay-paycenter' ) )
			: __( 'Automatic recovery: Requires verification', 'resilient-gateway-for-epay-paycenter' );
		echo '<div class="epay-review-overview"><p class="epay-review-status">' . esc_html( $enabled ) . '</p>';
		if ( null === $status['awaiting'] ) {
			echo '<p>' . esc_html__( 'Pending-check status is unavailable. Ask the site administrator to check the gateway log.', 'resilient-gateway-for-epay-paycenter' ) . '</p>';
		} else {
			echo '<p class="epay-review-awaiting">' . esc_html(
				sprintf(
				/* translators: %d: number of payment attempts, not distinct orders. */
					_n( 'Awaiting bank result: %d attempt', 'Awaiting bank result: %d attempts', $status['awaiting'], 'resilient-gateway-for-epay-paycenter' ),
					$status['awaiting']
				)
			) . '</p>';
		}
		echo '<p class="epay-review-count">' . esc_html(
			$count ? sprintf(
				/* translators: %d: number of unreviewed exceptions, not distinct orders. */
				__( 'Unreviewed cases: %d', 'resilient-gateway-for-epay-paycenter' ),
				$count
			) : __( 'No unreviewed payment exceptions.', 'resilient-gateway-for-epay-paycenter' )
		) . '</p></div>';
		if ( ! $status['enabled'] ) {
			echo '<p>' . esc_html__( 'Automatic checks are not running. Ask the site administrator to review the gateway settings.', 'resilient-gateway-for-epay-paycenter' ) . '</p>';
		} elseif ( ! $status['scheduled'] ) {
			echo '<p>' . esc_html__( 'No recovery run is scheduled. Ask the site administrator to check WordPress scheduled tasks.', 'resilient-gateway-for-epay-paycenter' ) . '</p>';
		} elseif ( $status['overdue'] ) {
			echo '<p>' . esc_html__( 'Automatic checks are more than 15 minutes behind. Ask the site administrator to check WordPress scheduled tasks and the gateway log.', 'resilient-gateway-for-epay-paycenter' ) . '</p>';
		}
		$run = $status['last_run'];
		echo '<p class="epay-review-last-run">';
		if ( null === $run ) {
			echo esc_html__( 'No run recorded yet.', 'resilient-gateway-for-epay-paycenter' );
		} else {
			echo esc_html(
				sprintf(
				/* translators: %s: recovery run completion time in the site's timezone. */
					__( 'Last recovery run: %s', 'resilient-gateway-for-epay-paycenter' ),
					wp_date( 'Y-m-d H:i:s T', $run['completed_at'] )
				)
			) . ' — ';
			echo esc_html(
				$run['errors'] > 0
				? __( 'Completed with errors. Ask the site administrator to check the gateway log.', 'resilient-gateway-for-epay-paycenter' )
				: __( 'Completed without reported errors.', 'resilient-gateway-for-epay-paycenter' )
			);
		}
		echo '</p>';
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
	 * Explain the concrete reason and provide an explicit review action.
	 *
	 * @param array $items Visible exceptions.
	 * @param array $orders Orders loaded once per distinct order id.
	 * @phpstan-param array<string,ReviewItem> $items
	 * @phpstan-param array<int,WC_Order|null> $orders
	 */
	private static function render_items( array $items, array $orders ): void {
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
			$order = $orders[ $item['order_id'] ];
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
