<?php
/**
 * Reconciliation of payment attempts that never reached a final state.
 *
 * The failure this exists for has already happened in production: a stale
 * callback URL in the Euronet portal swallowed the bank's response, customers
 * were charged, and their orders sat in `pending` forever. Nothing detected it.
 * The merchant found out from the customers.
 *
 * What this job can and cannot do
 * ------------------------------
 * It CANNOT ask the bank whether a transaction settled. Redirection Manual §5
 * describes a «follow-up» Web Service that returns full detail for a given
 * MerchantReference, but states its technical specification "should be
 * requested from Euronet Merchant Services", and that document is not held in
 * this repository. Until it is, a stuck order is genuinely ambiguous: it may be
 * an abandoned checkout, or it may be a completed payment whose callback was
 * lost, and nothing available to this plugin can tell the two apart.
 *
 * So this job deliberately **never changes an order's status and never marks
 * anything paid**. Guessing would be worse than the bug: marking an abandoned
 * order paid ships goods for free, and cancelling a paid one takes money for
 * nothing. It flags, annotates, and tells the merchant which orders to check in
 * the epay eCommerce AdminTool. That converts a silent failure into a visible
 * one, which is the whole win.
 *
 * It also gives `{prefix}_epay_paycenter_tickets` the reader and the lifecycle
 * it never had. Rows were inserted as `pending` and left forever; they now
 * settle to `settled`, `closed`, or `stale`, and resolved rows are pruned, so
 * the table stops growing without bound.
 *
 * @package EpayPaycenter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scheduled reconciliation of stuck Paycenter payment attempts.
 */
class Epay_Paycenter_Reconciliation {

	/**
	 * Cron hook name.
	 */
	const CRON_HOOK = 'epay_paycenter_reconcile';

	/**
	 * Option holding the most recent report, read by the admin notice.
	 */
	const REPORT_OPTION = 'epay_paycenter_reconcile_report';

	/**
	 * Transient set when a merchant dismisses the notice.
	 */
	const DISMISS_TRANSIENT = 'epay_paycenter_reconcile_dismissed';

	/**
	 * Order meta recording when an attempt was first flagged, so repeat runs
	 * annotate the order once rather than on every pass.
	 */
	const FLAGGED_META = '_epay_reconcile_flagged_at';

	/**
	 * Hours an attempt must be untouched before it is considered stuck.
	 *
	 * The bank's own limits are far shorter: §4 gives a TranTicket 30 minutes
	 * and the hosted payment session 15. Two hours therefore sits well clear of
	 * any legitimately in-flight payment while still surfacing a lost callback
	 * the same day.
	 */
	const DEFAULT_GRACE_HOURS = 2;

	/**
	 * Days a resolved ticket row is retained before pruning.
	 */
	const DEFAULT_RETENTION_DAYS = 90;

	/**
	 * Maximum ticket rows examined per run, so a large or long-neglected store
	 * cannot turn one cron tick into a timeout.
	 */
	const DEFAULT_BATCH_SIZE = 200;

	/**
	 * Register hooks. Called from the plugin bootstrapper.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_dismiss' ) );

		// Safety net: the activation hook does not fire on a plugin *update*,
		// so a site upgrading into this release would otherwise never schedule
		// the event.
		self::schedule();
	}

	/**
	 * Schedule the daily event if it is not already scheduled.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Remove the scheduled event. Called on deactivation.
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * The job itself.
	 *
	 * Driven from the tickets table rather than from an order query: every
	 * issued attempt writes a row there, so the table is the authoritative list
	 * of "payments we started", which is exactly the population that can go
	 * missing. Orders are then consulted one row at a time.
	 *
	 * @return array{stuck:int,settled:int,closed:int,pruned:int} Run summary.
	 */
	public static function run() {
		global $wpdb;

		$summary = array(
			'stuck'   => 0,
			'settled' => 0,
			'closed'  => 0,
			'pruned'  => 0,
		);

		if ( ! function_exists( 'wc_get_order' ) ) {
			return $summary;
		}

		$table = $wpdb->prefix . 'epay_paycenter_tickets';

		$grace  = (int) apply_filters( 'epay_paycenter_reconcile_grace_hours', self::DEFAULT_GRACE_HOURS );
		$grace  = max( 1, $grace );
		$batch  = (int) apply_filters( 'epay_paycenter_reconcile_batch_size', self::DEFAULT_BATCH_SIZE );
		$batch  = max( 1, min( 1000, $batch ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $grace * HOUR_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT order_id, merchant_reference, created_at FROM %i WHERE status = %s AND created_at < %s ORDER BY created_at ASC LIMIT %d',
				$table,
				'pending',
				$cutoff,
				$batch
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( empty( $rows ) ) {
			self::store_report( array() );
			$summary['pruned'] = self::prune();
			return $summary;
		}

		// Collapse to one decision per order: an order reloaded five times has
		// five rows, but it is one thing for the merchant to look at.
		$by_order = array();
		foreach ( $rows as $row ) {
			$order_id = (int) $row['order_id'];
			if ( $order_id && ! isset( $by_order[ $order_id ] ) ) {
				$by_order[ $order_id ] = $row;
			}
		}

		$stuck = array();

		foreach ( $by_order as $order_id => $row ) {
			$order = wc_get_order( $order_id );

			if ( ! $order || $order->get_payment_method() !== EPAY_PAYCENTER_GATEWAY_ID ) {
				// Order deleted, or the attempt belongs to a method that has
				// since been switched. Nothing to reconcile.
				self::mark_rows( $order_id, 'closed' );
				++$summary['closed'];
				continue;
			}

			if ( $order->is_paid() ) {
				// The callback did arrive; the row was simply never updated.
				self::mark_rows( $order_id, 'settled' );
				++$summary['settled'];
				continue;
			}

			if ( $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ) ) {
				self::mark_rows( $order_id, 'closed' );
				++$summary['closed'];
				continue;
			}

			// Still open past the grace window. Ambiguous by construction: the
			// customer may have abandoned the payment page, or the bank may
			// have charged them and the callback never arrived. Flag, annotate
			// once, and leave the status alone.
			self::mark_rows( $order_id, 'stale' );
			++$summary['stuck'];
			$stuck[] = $order_id;

			if ( '' === (string) $order->get_meta( self::FLAGGED_META, true ) ) {
				$order->update_meta_data( self::FLAGGED_META, current_time( 'mysql', true ) );
				$order->add_order_note(
					sprintf(
						/* translators: 1: number of hours the payment attempt has been open, 2: MerchantReference of the attempt. */
						__( 'Paycenter reconciliation: a payment was started for this order more than %1$d hours ago and no final response was ever received. The order status has deliberately NOT been changed, because without the bank\'s follow-up Web Service the plugin cannot tell an abandoned checkout from a completed payment whose callback was lost. Check MerchantReference %2$s in the epay eCommerce AdminTool: if the transaction settled, mark this order paid; if not, it can be cancelled.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
						$grace,
						'' !== (string) $row['merchant_reference'] ? $row['merchant_reference'] : '-'
					)
				);
				$order->save();
			}
		}

		self::store_report( $stuck );
		$summary['pruned'] = self::prune();

		Epay_Paycenter_Logger::info( 'Paycenter reconciliation run complete', $summary );

		if ( ! empty( $stuck ) ) {
			Epay_Paycenter_Logger::error(
				'Paycenter reconciliation found payment attempts with no final response. Verify them in the epay eCommerce AdminTool.',
				array( 'order_ids' => array_slice( $stuck, 0, 50 ) )
			);
		}

		return $summary;
	}

	/**
	 * Move every still-pending ticket row for an order to a terminal state.
	 *
	 * @param int    $order_id Order id.
	 * @param string $status   One of settled | closed | stale.
	 */
	private static function mark_rows( $order_id, $status ) {
		global $wpdb;

		$allowed = array( 'settled', 'closed', 'stale' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return;
		}

		$table = $wpdb->prefix . 'epay_paycenter_tickets';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, updated_at = %s WHERE order_id = %d AND status = %s',
				$table,
				$status,
				gmdate( 'Y-m-d H:i:s' ),
				(int) $order_id,
				'pending'
			)
		);
	}

	/**
	 * Delete resolved ticket rows past the retention window.
	 *
	 * `stale` rows are deliberately NOT pruned: they are the unresolved ones,
	 * and they are the audit trail for a payment nobody has accounted for yet.
	 *
	 * @return int Rows removed.
	 */
	private static function prune() {
		global $wpdb;

		$days = (int) apply_filters( 'epay_paycenter_reconcile_retention_days', self::DEFAULT_RETENTION_DAYS );
		$days = max( 1, $days );

		$table  = $wpdb->prefix . 'epay_paycenter_tickets';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE status IN ( %s, %s ) AND updated_at < %s',
				$table,
				'settled',
				'closed',
				$cutoff
			)
		);

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Persist the report the admin notice reads.
	 *
	 * @param int[] $order_ids Flagged order ids.
	 */
	private static function store_report( array $order_ids ) {
		if ( empty( $order_ids ) ) {
			delete_option( self::REPORT_OPTION );
			delete_transient( self::DISMISS_TRANSIENT );
			return;
		}

		update_option(
			self::REPORT_OPTION,
			array(
				'time'      => current_time( 'mysql', true ),
				'total'     => count( $order_ids ),
				'order_ids' => array_map( 'absint', array_slice( $order_ids, 0, 50 ) ),
			),
			false
		);
	}

	/**
	 * Handle the notice's dismiss link.
	 */
	public static function maybe_handle_dismiss() {
		if ( ! isset( $_GET['epay_paycenter_dismiss_reconcile'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		check_admin_referer( 'epay_paycenter_dismiss_reconcile' );

		// Hidden for a week, not forever: the orders are still unaccounted for,
		// and silence is what caused the original incident.
		set_transient( self::DISMISS_TRANSIENT, 1, WEEK_IN_SECONDS );

		wp_safe_redirect( remove_query_arg( array( 'epay_paycenter_dismiss_reconcile', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Render the admin notice for unresolved attempts.
	 */
	public static function admin_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( get_transient( self::DISMISS_TRANSIENT ) ) {
			return;
		}

		$report = get_option( self::REPORT_OPTION );
		if ( ! is_array( $report ) || empty( $report['total'] ) ) {
			return;
		}

		$total     = (int) $report['total'];
		$order_ids = isset( $report['order_ids'] ) && is_array( $report['order_ids'] ) ? $report['order_ids'] : array();

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'epay_paycenter_dismiss_reconcile', '1' ),
			'epay_paycenter_dismiss_reconcile'
		);

		echo '<div class="notice notice-warning">';
		echo '<p><strong>' . esc_html__( 'ePay Paycenter: payments started but never confirmed', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ) . '</strong></p>';

		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: number of orders with an unconfirmed payment attempt. */
				_n(
					'%d order has a Paycenter payment that was started but for which no final response was ever received. The order status has not been changed automatically, because the plugin cannot tell an abandoned checkout from a completed payment whose callback was lost.',
					'%d orders have a Paycenter payment that was started but for which no final response was ever received. Their statuses have not been changed automatically, because the plugin cannot tell an abandoned checkout from a completed payment whose callback was lost.',
					$total,
					'secure-card-gateway-for-epay-paycenter-piraeus-bank'
				),
				$total
			)
		) . '</p>';

		echo '<p>' . esc_html__( 'Check each one in the epay eCommerce AdminTool. If the transaction settled, mark the order paid; if it did not, the order can be cancelled. Each affected order carries a note with its MerchantReference.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ) . '</p>';

		if ( ! empty( $order_ids ) ) {
			$links = array();
			foreach ( array_slice( $order_ids, 0, 20 ) as $order_id ) {
				$order_id = absint( $order_id );
				if ( ! $order_id ) {
					continue;
				}
				$links[] = '<a href="' . esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ) . '">#' . esc_html( (string) $order_id ) . '</a>';
			}
			if ( ! empty( $links ) ) {
				// Links are built from absint()-ed ids through esc_url/esc_html
				// above, so the joined string carries no unescaped input.
				echo '<p>' . wp_kses( implode( ', ', $links ), array( 'a' => array( 'href' => array() ) ) );
				if ( $total > count( $links ) ) {
					echo ' ' . esc_html(
						sprintf(
							/* translators: %d: number of additional affected orders not listed individually. */
							__( 'and %d more.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
							$total - count( $links )
						)
					);
				}
				echo '</p>';
			}
		}

		echo '<p><a href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Hide this for a week', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ) . '</a></p>';
		echo '</div>';
	}
}
