<?php
/**
 * Automatic reconciliation for Paycenter responses that never reached WooCommerce.
 *
 * @package EpayPaycenter
 *
 * Uses the ePay FOLLOW_UP Web Service with bounded checks and stock handling.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates scheduling, bank queries and idempotent WooCommerce settlement.
 *
 * @phpstan-import-type FollowUpResult from Epay_Paycenter_Follow_Up
 * @phpstan-type RunSummary array{paid:int, declined:int, pending:int, query_errors:int, local_paid:int, unresolved:int, double_payments:int, settlement_errors:int, lock_skipped:int}
 * @phpstan-type TicketRow array{id:int, order_id:int, merchant_reference:string, created_at:string, follow_up_attempts:int, follow_up_state:string, follow_up_result_code:string, follow_up_response_code:string, follow_up_status_flag:string, follow_up_support_reference_id:string, follow_up_transaction_id:string, follow_up_transaction_at:string, follow_up_payment_method:string, follow_up_iris_transaction_id:string, follow_up_iris_status:string}
 * @phpstan-type ChannelTestResult array{success:bool, message:string, channel?:string, status_label?:string, tested_channels?:list<string>, response_code?:string, payment_method?:string, transaction_at?:string}
 * @phpstan-type RecoveryStatus array{enabled:bool, verified:bool, awaiting:int|null, overdue:bool, scheduled:bool, last_run:array{completed_at:int, errors:int}|null}
 */
final class Epay_Paycenter_Reconciliation {

	const CRON_HOOK              = 'epay_paycenter_reconcile';
	const STOCK_RELEASE_HOOK     = 'epay_paycenter_release_stock';
	const VERIFICATION_OPTION    = 'epay_paycenter_follow_up_verification';
	const ACTIVE_OPTION          = 'epay_paycenter_follow_up_active';
	const STATUS_OPTION          = 'epay_paycenter_recovery_status';
	const REPORT_OPTION          = 'epay_paycenter_reconcile_report';
	const DISMISS_TRANSIENT      = 'epay_paycenter_reconcile_dismissed';
	const LOCK_PREFIX            = 'epay_paycenter_follow_up_lock_';
	const DEFAULT_HOLD_MINUTES   = 240;
	const DEFAULT_BATCH_SIZE     = 25;
	const MAX_ATTEMPTS_PER_ORDER = 10;
	const DEFAULT_RUN_BUDGET     = 45;

	/**
	 * Absolute query checkpoints measured from ticket creation, ending at 48h.
	 *
	 * @var int[]
	 */
	private static $checkpoints = array( 300, 900, 1800, 3600, 7200, 14400, 28800, 86400, 172800 );

	/** Register integration hooks. */
	public static function init(): void {
		add_action(
			self::CRON_HOOK,
			static function (): void {
				self::run();
			}
		);
		add_action( self::STOCK_RELEASE_HOOK, array( __CLASS__, 'release_stock' ) );
		Epay_Paycenter_Review::init( array( __CLASS__, 'status' ) );
		add_action( 'wp_ajax_epay_paycenter_follow_up_test', array( __CLASS__, 'ajax_test_channel' ) );
		add_action(
			'woocommerce_update_options_payment_gateways_' . EPAY_PAYCENTER_GATEWAY_ID,
			array( __CLASS__, 'settings_updated' ),
			20
		);
		add_filter( 'woocommerce_order_hold_stock_minutes', array( __CLASS__, 'filter_stock_hold_minutes' ), 10, 2 );
		add_filter( 'woocommerce_cancel_unpaid_order', array( __CLASS__, 'filter_cancel_unpaid_order' ), 10, 2 );

		if ( self::is_enabled() ) {
			self::schedule();
		}
	}

	/**
	 * Schedule a single worker. It schedules its successor after each run.
	 *
	 * @param int $delay Delay in seconds.
	 * @return bool Whether a worker is already scheduled or was scheduled now.
	 */
	public static function schedule( $delay = 300 ) {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return true;
		}
		$result = wp_schedule_single_event( time() + max( 10, (int) $delay ), self::CRON_HOOK, array(), true );
		if ( is_wp_error( $result ) ) {
			Epay_Paycenter_Logger::error( 'Could not schedule the ePay reconciliation worker.' );
			return false;
		}
		return true;
	}

	/** Remove workers and per-order stock-release events. */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::STOCK_RELEASE_HOOK );
	}

	/**
	 * Resolve the channel verified against the currently stored credentials.
	 *
	 * @return string eCommerce, 3DSecure, or empty when unverified/stale.
	 */
	public static function verified_channel() {
		$verification = get_option( self::VERIFICATION_OPTION );
		$fingerprint  = self::credential_fingerprint();
		if ( ! is_array( $verification ) || '' === $fingerprint ) {
			return '';
		}
		$stored  = isset( $verification['fingerprint'] ) ? (string) $verification['fingerprint'] : '';
		$channel = isset( $verification['channel'] ) ? (string) $verification['channel'] : '';
		if ( ! in_array( $channel, array( 'eCommerce', '3DSecure' ), true )
			|| '' === $stored
			|| ! hash_equals( $fingerprint, $stored ) ) {
			return '';
		}
		return $channel;
	}

	/**
	 * Automatic mutation requires both verification and a later explicit save.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings    = self::settings();
		$channel     = self::verified_channel();
		$active      = get_option( self::ACTIVE_OPTION );
		$fingerprint = self::credential_fingerprint();
		return 'yes' === (string) ( $settings['follow_up_enabled'] ?? 'no' )
			&& '' !== $channel
			&& is_array( $active )
			&& ! empty( $active['fingerprint'] )
			&& hash_equals( $fingerprint, (string) $active['fingerprint'] );
	}

	/**
	 * Test both documented channels against a known successful order.
	 * Does not update the order or ticket table.
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return ChannelTestResult
	 */
	public static function test_channel_for_order( $order_id ) {
		$order_id = absint( $order_id );
		$order    = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || EPAY_PAYCENTER_GATEWAY_ID !== $order->get_payment_method() ) {
			return array(
				'success' => false,
				'message' => __( 'Choose an order paid through this ePay gateway.', 'resilient-gateway-for-epay-paycenter' ),
			);
		}

		$client = Epay_Paycenter_Follow_Up::from_settings();
		if ( ! $client->is_configured() ) {
			return array(
				'success' => false,
				'message' => __( 'Save the ePay merchant credentials before testing follow-up.', 'resilient-gateway-for-epay-paycenter' ),
			);
		}

		$references = self::references_for_order( $order_id );
		if ( empty( $references ) ) {
			return array(
				'success' => false,
				'message' => __( 'No issued MerchantReference was found for this order.', 'resilient-gateway-for-epay-paycenter' ),
			);
		}

		$tested = array();
		foreach ( $references as $reference ) {
			foreach ( array( 'eCommerce', '3DSecure' ) as $channel ) {
				if ( ! in_array( $channel, $tested, true ) ) {
					$tested[] = $channel;
				}
				$result = $client->query( $reference, $channel );
				if ( 'paid' !== $result['state'] ) {
					continue;
				}

				$fingerprint = self::credential_fingerprint();
				update_option(
					self::VERIFICATION_OPTION,
					array(
						'channel'     => $channel,
						'fingerprint' => $fingerprint,
						'verified_at' => current_time( 'mysql', true ),
						'order_id'    => $order_id,
					),
					false
				);

				// Testing proves the channel but cannot enable order mutation.
				$settings                      = self::settings();
				$settings['follow_up_enabled'] = 'no';
				update_option( 'woocommerce_' . EPAY_PAYCENTER_GATEWAY_ID . '_settings', $settings, false );
				delete_option( self::ACTIVE_OPTION );
				self::unschedule();

				return array(
					'success'         => true,
					'channel'         => $channel,
					'status_label'    => sprintf(
						/* translators: %s: verified ePay ChannelType (eCommerce or 3DSecure). */
						__( 'Verified: %s', 'resilient-gateway-for-epay-paycenter' ),
						$channel
					),
					'tested_channels' => $tested,
					'response_code'   => (string) $result['response_code'],
					'payment_method'  => (string) $result['payment_method'],
					'transaction_at'  => (string) $result['transaction_at'],
					'message'         => __( 'Channel verified. Enable automatic reconciliation below and save the settings to start recovery.', 'resilient-gateway-for-epay-paycenter' ),
				);
			}
		}

		return array(
			'success'         => false,
			'tested_channels' => $tested,
			'message'         => __( 'Neither ChannelType returned an approved transaction for that order. Confirm that the order is successful in the ePay AdminTool and try again.', 'resilient-gateway-for-epay-paycenter' ),
		);
	}

	/** Authenticated admin wrapper for test_channel_for_order(). */
	public static function ajax_test_channel(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage WooCommerce payments.', 'resilient-gateway-for-epay-paycenter' ) ), 403 );
		}
		check_ajax_referer( 'epay_paycenter_follow_up_test', 'nonce' );

		$rate_key = 'epay_follow_up_test_' . get_current_user_id();
		if ( get_transient( $rate_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Please wait before running the channel test again.', 'resilient-gateway-for-epay-paycenter' ) ), 429 );
		}
		set_transient( $rate_key, 1, 20 );

		$order_id = isset( $_POST['order_id'] ) && is_scalar( $_POST['order_id'] )
			? absint( wp_unslash( $_POST['order_id'] ) )
			: 0;
		$result   = self::test_channel_for_order( $order_id );
		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result, 422 );
	}

	/** Enforce verify-then-enable and initialise historical rows. */
	public static function settings_updated(): void {
		$settings = self::settings();
		if ( 'yes' !== (string) ( $settings['follow_up_enabled'] ?? 'no' ) ) {
			delete_option( self::ACTIVE_OPTION );
			self::unschedule();
			return;
		}

		$channel     = self::verified_channel();
		$fingerprint = self::credential_fingerprint();
		if ( '' === $channel || '' === $fingerprint ) {
			$settings['follow_up_enabled'] = 'no';
			update_option( 'woocommerce_' . EPAY_PAYCENTER_GATEWAY_ID . '_settings', $settings, false );
			delete_option( self::ACTIVE_OPTION );
			self::unschedule();
			if ( class_exists( 'WC_Admin_Settings' ) ) {
				WC_Admin_Settings::add_error( __( 'Automatic ePay reconciliation was not enabled. First verify the follow-up channel with a known successful order.', 'resilient-gateway-for-epay-paycenter' ) );
			}
			return;
		}

		update_option(
			self::ACTIVE_OPTION,
			array(
				'fingerprint' => $fingerprint,
				'enabled_at'  => current_time( 'mysql', true ),
			),
			false
		);
		self::migrate_from_warning_only_version();
		self::initialize_backfill();
		self::schedule( 10 );
	}

	/** Remove only state created by the warning-only reconciliation version. */
	public static function migrate_from_warning_only_version(): void {
		$report = get_option( self::REPORT_OPTION );
		if ( false !== $report
			&& ( ! is_array( $report ) || ! isset( $report['items'] ) || ! is_array( $report['items'] ) ) ) {
			delete_option( self::REPORT_OPTION );
			delete_transient( self::DISMISS_TRANSIENT );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/** Make unresolved historical attempts eligible for bounded work. */
	private static function initialize_backfill(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'epay_paycenter_tickets';
		$now   = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET next_check_at = %s
				 WHERE resolved_at IS NULL AND (follow_up_state = '' OR follow_up_state IN ('pending','query_error'))",
				$table,
				$now
			)
		);
		if ( false === $result ) {
			Epay_Paycenter_Logger::error( 'Could not initialise ePay reconciliation backfill.' );
		}
	}

	/**
	 * Register a newly issued attempt for polling and stock release.
	 *
	 * @param int    $order_id WooCommerce order id.
	 * @param string $reference Issued MerchantReference.
	 */
	public static function schedule_attempt( $order_id, $reference ): void {
		global $wpdb;
		$order_id  = absint( $order_id );
		$reference = (string) $reference;
		if ( ! $order_id || '' === $reference ) {
			return;
		}

		$enabled = self::is_enabled();
		if ( $enabled ) {
			$table = $wpdb->prefix . 'epay_paycenter_tickets';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$table,
				array( 'next_check_at' => gmdate( 'Y-m-d H:i:s', time() + self::$checkpoints[0] ) ),
				array(
					'order_id'           => $order_id,
					'merchant_reference' => $reference,
				),
				array( '%s' ),
				array( '%d', '%s' )
			);
			if ( false === $updated ) {
				Epay_Paycenter_Logger::error(
					'Could not schedule an ePay payment attempt for reconciliation.',
					array( 'order_id' => $order_id )
				);
			}
			self::schedule();
		}

		if ( $enabled ) {
			$args = array( $order_id );
			if ( ! wp_next_scheduled( self::STOCK_RELEASE_HOOK, $args ) ) {
				$scheduled = wp_schedule_single_event( time() + ( self::stock_hold_minutes() * MINUTE_IN_SECONDS ), self::STOCK_RELEASE_HOOK, $args, true );
				if ( is_wp_error( $scheduled ) ) {
					Epay_Paycenter_Logger::error(
						'Could not schedule stock release for an ePay payment attempt.',
						array( 'order_id' => $order_id )
					);
				}
			}
		}
	}

	/**
	 * Process due orders within a bounded time budget.
	 *
	 * @throws RuntimeException Caught locally if the work queue cannot be read.
	 * @return RunSummary Run summary.
	 */
	public static function run() {
		$summary = self::empty_summary();
		if ( ! self::is_enabled() || ! function_exists( 'wc_get_order' ) ) {
			return $summary;
		}

		$fingerprint        = self::credential_fingerprint();
		$maintenance_errors = 0;
		global $wpdb;
		$table  = $wpdb->prefix . 'epay_paycenter_tickets';
		$batch  = max( 1, min( 100, (int) apply_filters( 'epay_paycenter_reconcile_batch_size', self::DEFAULT_BATCH_SIZE ) ) );
		$budget = max( 10, min( 240, (int) apply_filters( 'epay_paycenter_reconcile_time_budget', self::DEFAULT_RUN_BUDGET ) ) );
		$now    = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The mutable work queue must be read fresh.
			$order_ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT order_id, MIN(next_check_at) AS due_at FROM %i
					 WHERE resolved_at IS NULL AND next_check_at IS NOT NULL AND next_check_at <= %s
					 GROUP BY order_id ORDER BY due_at ASC LIMIT %d',
					$table,
					$now,
					$batch
				)
			);
			if ( '' !== $wpdb->last_error || ! is_array( $order_ids ) ) {
				throw new RuntimeException( 'Could not load due reconciliation orders.' );
			}

			$started   = microtime( true );
			$processed = 0;
			foreach ( $order_ids as $order_id ) {
				if ( $processed > 0 && microtime( true ) - $started >= $budget ) {
					break;
				}
				try {
					$result = self::reconcile_order( (int) $order_id, false );
				} catch ( Throwable $error ) {
					$result                 = self::empty_summary();
					$result['query_errors'] = 1;
					Epay_Paycenter_Logger::error(
						'Unexpected failure while reconciling one ePay order; later orders will continue.',
						array( 'order_id' => (int) $order_id )
					);
				}
				++$processed;
				foreach ( $summary as $key => $value ) {
					$summary[ $key ] += $result[ $key ];
				}
			}
		} catch ( Throwable $error ) {
			++$summary['query_errors'];
			Epay_Paycenter_Logger::error( 'Could not load the ePay reconciliation work queue.' );
		} finally {
			try {
				self::prune();
			} catch ( Throwable $error ) {
				++$maintenance_errors;
				Epay_Paycenter_Logger::error( 'Could not prune old ePay reconciliation records.' );
			}
			if ( ! self::schedule() ) {
				++$maintenance_errors;
			}
		}
		$status = array(
			'fingerprint'  => $fingerprint,
			'completed_at' => time(),
			'errors'       => $summary['query_errors'] + $summary['settlement_errors'] + $maintenance_errors,
		);
		if ( ! update_option( self::STATUS_OPTION, $status, false ) && get_option( self::STATUS_OPTION ) !== $status ) {
			Epay_Paycenter_Logger::error( 'Could not save the ePay recovery worker status.' );
		}
		Epay_Paycenter_Logger::info( 'Paycenter follow-up reconciliation run complete', $summary );
		return $summary;
	}

	/**
	 * Read queue health without querying the bank or scheduling work.
	 *
	 * @phpstan-return RecoveryStatus
	 * @throws RuntimeException If worker history cannot be read.
	 */
	public static function status(): array {
		global $wpdb;
		$status = array(
			'enabled'   => self::is_enabled(),
			'verified'  => '' !== self::verified_channel(),
			'awaiting'  => null,
			'overdue'   => false,
			'scheduled' => false !== wp_next_scheduled( self::CRON_HOOK ),
			'last_run'  => null,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(follow_up_state IN ('','pending','query_error')),0) AS awaiting, MIN(next_check_at) AS due_at
			 FROM %i WHERE resolved_at IS NULL AND next_check_at IS NOT NULL",
				$wpdb->prefix . 'epay_paycenter_tickets'
			),
			ARRAY_A
		);
		if ( '' === $wpdb->last_error && is_array( $row ) ) {
			$status['awaiting'] = (int) $row['awaiting'];
			$due                = $row['due_at'] ? strtotime( (string) $row['due_at'] . ' UTC' ) : false;
			$status['overdue']  = false !== $due && $due < time() - 15 * MINUTE_IN_SECONDS;
		} else {
			Epay_Paycenter_Logger::error( 'Could not read the ePay pending-check status.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::STATUS_OPTION ) );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Could not read the ePay worker history.' );
		}
		$stored = null === $raw ? false : maybe_unserialize( $raw );
		if ( is_array( $stored ) && isset( $stored['fingerprint'], $stored['completed_at'], $stored['errors'] )
			&& is_string( $stored['fingerprint'] ) && is_int( $stored['completed_at'] ) && is_int( $stored['errors'] )
			&& hash_equals( self::credential_fingerprint(), $stored['fingerprint'] ) ) {
			$status['last_run'] = array(
				'completed_at' => $stored['completed_at'],
				'errors'       => $stored['errors'],
			);
		}
		return $status;
	}

	/**
	 * Reconcile every eligible attempt for one order under an atomic lock.
	 *
	 * @param int  $order_id WooCommerce order id.
	 * @param bool $force    Ignore next-check time (local test/manual runner).
	 * @return RunSummary
	 */
	public static function reconcile_order( $order_id, $force = false ) {
		$summary  = self::empty_summary();
		$order_id = absint( $order_id );
		$channel  = self::verified_channel();
		if ( ! $order_id || '' === $channel || ( ! $force && ! self::is_enabled() ) ) {
			return $summary;
		}
		if ( ! self::acquire_lock( $order_id ) ) {
			$summary['lock_skipped'] = 1;
			return $summary;
		}

		try {
			$rows = self::rows_for_order( $order_id, $force );
			if ( empty( $rows ) ) {
				return $summary;
			}
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				self::restore_stock_release( $order );
			}
			$can_mutate = $order instanceof WC_Order
				&& EPAY_PAYCENTER_GATEWAY_ID === $order->get_payment_method()
				&& ( $order->is_paid() || $order->has_status( array( 'pending', 'on-hold', 'failed', 'cancelled' ) ) );
			if ( ! $can_mutate || 'trash' === $order->get_status() ) {
				$client = Epay_Paycenter_Follow_Up::from_settings();
				$paid   = 0;
				foreach ( $rows as $row ) {
					$result = $client->query( (string) $row['merchant_reference'], $channel );
					self::store_result( $row, $result );
					$state = (string) $result['state'];
					if ( 'paid' === $state ) {
						++$paid;
						++$summary['paid'];
						Epay_Paycenter_Review::record(
							$order && 'trash' === $order->get_status() ? 'trash_paid' : 'missing_paid',
							$order_id,
							(string) $row['merchant_reference']
						);
					} elseif ( 'declined' === $state ) {
						++$summary['declined'];
					} elseif ( 'pending' === $state ) {
						++$summary['pending'];
					} else {
						++$summary['query_errors'];
					}
				}
				if ( $paid > 1 || self::paid_attempt_count( $order_id ) > 1 ) {
					$summary['double_payments'] = 1;
					Epay_Paycenter_Review::record( 'double_paid', $order_id );
				}
				$summary['unresolved'] = self::unresolved_count( $order_id );
				return $summary;
			}

			// A Processing/Completed status alone may have been set manually.
			// Historical local classification requires payment-specific evidence.
			if ( self::has_local_payment_provenance( $order )
				&& '' === (string) $order->get_meta( '_epay_follow_up_settled_reference', true ) ) {
				self::mark_locally_paid( $order, $rows );
				$summary['local_paid'] = count( $rows );
				return $summary;
			}

			$client       = Epay_Paycenter_Follow_Up::from_settings();
			$paid_results = array();
			$all_declined = true;
			foreach ( $rows as $row ) {
				$result = 'paid_unsettled' === (string) $row['follow_up_state']
					? self::bank_result_from_row( $row )
					: $client->query( (string) $row['merchant_reference'], $channel );
				$state  = (string) $result['state'];
				if ( 'paid' === $state ) {
					++$summary['paid'];
					$paid_results[] = array(
						'row'       => $row,
						'reference' => (string) $row['merchant_reference'],
						'result'    => $result,
					);
				} elseif ( 'declined' === $state ) {
					self::store_result( $row, $result );
					++$summary['declined'];
				} elseif ( 'pending' === $state ) {
					self::store_result( $row, $result );
					++$summary['pending'];
					$all_declined = false;
				} else {
					self::store_result( $row, $result );
					++$summary['query_errors'];
					$all_declined = false;
				}
			}

			if ( ! empty( $paid_results ) ) {
				$stored_paid_results = array();
				foreach ( $paid_results as $paid_result ) {
					if ( 'paid_unsettled' === (string) $paid_result['row']['follow_up_state']
						|| self::store_paid_unsettled( $paid_result['row'], $paid_result['result'] ) ) {
						$stored_paid_results[] = $paid_result;
					} else {
						++$summary['settlement_errors'];
						Epay_Paycenter_Review::record( 'database_error', $order_id, $paid_result['reference'] );
					}
				}
				$paid_results = $stored_paid_results;
				if ( empty( $paid_results ) ) {
					$summary['unresolved'] = self::unresolved_count( $order_id );
					return $summary;
				}
				if ( count( $paid_results ) > 1 || self::paid_attempt_count( $order_id ) > 1 ) {
					$summary['double_payments'] = 1;
					Epay_Paycenter_Review::record( 'double_paid', $order_id );
				}

				// Keep the first verified settlement canonical. A subsequent paid
				// sibling is evidence for manual duplicate-charge review, not a
				// reason to overwrite the original transaction metadata.
				// A stored reference can survive a failed save while the order is unpaid.
				$settled_reference         = (string) $order->get_meta( '_epay_follow_up_settled_reference', true );
				$retrying_local_settlement = 'paid_unsettled' === (string) $paid_results[0]['row']['follow_up_state']
					&& $settled_reference === $paid_results[0]['reference'];
				if ( ! $order->is_paid() || $retrying_local_settlement || '' === $settled_reference ) {
					$settled = self::settle_order( $order, $paid_results[0]['reference'], $paid_results[0]['result'] );
					if ( ! $settled ) {
						foreach ( $paid_results as $paid_result ) {
							Epay_Paycenter_Review::record( 'settlement_error', $order_id, $paid_result['reference'] );
						}
						$summary['settlement_errors'] = count( $paid_results );
						$summary['unresolved']        = self::unresolved_count( $order_id );
						return $summary;
					}
				}
				foreach ( $paid_results as $paid_result ) {
					self::finalize_paid_result( $paid_result['row'] );
				}
				$paid_count = self::paid_attempt_count( $order_id );
				if ( $paid_count > 1 ) {
					self::flag_multiple_payments( $order, $paid_count );
					$summary['double_payments'] = 1;
				}
			} elseif ( $all_declined
				&& $summary['declined'] > 0
				&& 0 === self::open_attempt_count( $order_id )
				&& $order->has_status( array( 'pending', 'on-hold' ) ) ) {
				$order->update_status( 'failed', __( 'Paycenter follow-up confirmed that every payment attempt was declined.', 'resilient-gateway-for-epay-paycenter' ) );
				$order->save();
			}

			$summary['unresolved'] = self::unresolved_count( $order_id );
			return $summary;
		} catch ( Throwable $error ) {
			++$summary['query_errors'];
			Epay_Paycenter_Review::record( 'reconciliation_error', $order_id );
			Epay_Paycenter_Logger::error(
				'Unexpected failure while reconciling an ePay order; the attempt remains queued for review.',
				array( 'order_id' => $order_id )
			);
			return $summary;
		} finally {
			self::release_lock( $order_id );
		}
	}

	/**
	 * Persist selected non-card fields and calculate the next retry.
	 *
	 * @throws RuntimeException If the attempt date is invalid or persistence fails.
	 * @param array $row    Ticket row.
	 * @phpstan-param TicketRow $row
	 * @param array $result Normalised result.
	 * @phpstan-param FollowUpResult $result
	 */
	private static function store_result( array $row, array $result ): void {
		global $wpdb;
		$table    = $wpdb->prefix . 'epay_paycenter_tickets';
		$attempts = (int) $row['follow_up_attempts'] + 1;
		$created  = strtotime( (string) $row['created_at'] . ' UTC' );
		if ( false === $created ) {
			throw new RuntimeException( 'Invalid reconciliation attempt creation time.' );
		}
		$state       = (string) $result['state'];
		$now         = time();
		$next        = null;
		$resolved_at = null;
		$resolution  = '';

		if ( in_array( $state, array( 'paid', 'declined' ), true ) ) {
			$resolved_at = gmdate( 'Y-m-d H:i:s', $now );
			$resolution  = 'follow_up';
		} else {
			$next = self::next_checkpoint( $created, $now );
			if ( null === $next && ( 'query_error' !== $state || $attempts >= 3 ) ) {
				$state       = 'unresolved';
				$resolved_at = gmdate( 'Y-m-d H:i:s', $now );
				$resolution  = 'timeout';
			}
			if ( null === $next && 'query_error' === $state && $attempts < 3 ) {
				$next = gmdate( 'Y-m-d H:i:s', $now + HOUR_IN_SECONDS );
			}
		}

		$data = array(
			'follow_up_state'                => $state,
			'follow_up_attempts'             => $attempts,
			'last_checked_at'                => gmdate( 'Y-m-d H:i:s', $now ),
			'next_check_at'                  => $next,
			'follow_up_result_code'          => (string) $result['result_code'],
			'follow_up_response_code'        => (string) $result['response_code'],
			'follow_up_status_flag'          => (string) $result['status_flag'],
			'follow_up_support_reference_id' => (string) $result['support_reference_id'],
			'follow_up_transaction_id'       => (string) $result['transaction_id'],
			'follow_up_transaction_at'       => self::normalise_datetime( (string) $result['transaction_at'] ),
			'follow_up_payment_method'       => (string) $result['payment_method'],
			'follow_up_iris_transaction_id'  => (string) $result['iris_transaction_id'],
			'follow_up_iris_status'          => (string) $result['iris_status'],
			'resolution_source'              => $resolution,
			'resolved_at'                    => $resolved_at,
			'updated_at'                     => gmdate( 'Y-m-d H:i:s', $now ),
		);
		if ( 'paid' === $state ) {
			$data['status'] = Epay_Paycenter_Ticket_Audit::STATUS_SUCCEEDED;
		} elseif ( 'declined' === $state ) {
			$data['status'] = Epay_Paycenter_Ticket_Audit::STATUS_FAILED;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ) );
		if ( false === $updated ) {
			throw new RuntimeException( 'Could not persist an ePay follow-up result.' );
		}
		if ( 'unresolved' === $state ) {
			Epay_Paycenter_Review::record( 'unresolved', (int) $row['order_id'], (string) $row['merchant_reference'], 1 === $attempts && $now - $created >= 2 * DAY_IN_SECONDS );
		}
	}

	/**
	 * Persist bank approval before attempting the local WooCommerce transition.
	 *
	 * @param array $row    Ticket row.
	 * @phpstan-param TicketRow $row
	 * @param array $result Normalised paid bank result.
	 * @phpstan-param FollowUpResult $result
	 * @return bool
	 */
	private static function store_paid_unsettled( array $row, array $result ) {
		global $wpdb;
		$table = $wpdb->prefix . 'epay_paycenter_tickets';
		$now   = time();
		$data  = array(
			'status'                         => Epay_Paycenter_Ticket_Audit::STATUS_SUCCEEDED,
			'follow_up_state'                => 'paid_unsettled',
			'follow_up_attempts'             => (int) $row['follow_up_attempts'] + 1,
			'last_checked_at'                => gmdate( 'Y-m-d H:i:s', $now ),
			'next_check_at'                  => gmdate( 'Y-m-d H:i:s', $now + 300 ),
			'follow_up_result_code'          => (string) $result['result_code'],
			'follow_up_response_code'        => (string) $result['response_code'],
			'follow_up_status_flag'          => (string) $result['status_flag'],
			'follow_up_support_reference_id' => (string) $result['support_reference_id'],
			'follow_up_transaction_id'       => (string) $result['transaction_id'],
			'follow_up_transaction_at'       => self::normalise_datetime( (string) $result['transaction_at'] ),
			'follow_up_payment_method'       => (string) $result['payment_method'],
			'follow_up_iris_transaction_id'  => (string) $result['iris_transaction_id'],
			'follow_up_iris_status'          => (string) $result['iris_status'],
			'resolution_source'              => 'follow_up',
			'resolved_at'                    => null,
			'updated_at'                     => gmdate( 'Y-m-d H:i:s', $now ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ) );
		if ( false === $updated ) {
			Epay_Paycenter_Logger::error(
				'Could not persist bank approval before WooCommerce settlement.',
				array( 'order_id' => (int) $row['order_id'] )
			);
			return false;
		}
		return true;
	}

	/**
	 * Mark a previously persisted approval locally settled.
	 *
	 * @throws RuntimeException If the settled result cannot be persisted.
	 * @param array $row Ticket row.
	 * @phpstan-param TicketRow $row
	 */
	private static function finalize_paid_result( array $row ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'epay_paycenter_tickets';
		$now   = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			array(
				'status'            => Epay_Paycenter_Ticket_Audit::STATUS_SUCCEEDED,
				'follow_up_state'   => 'paid',
				'next_check_at'     => null,
				'resolution_source' => 'follow_up',
				'resolved_at'       => $now,
				'updated_at'        => $now,
			),
			array( 'id' => (int) $row['id'] )
		);
		if ( false === $updated ) {
			throw new RuntimeException( 'Could not finalize a settled ePay follow-up result.' );
		}
	}

	/**
	 * Rehydrate a bank approval that is awaiting only local persistence.
	 *
	 * @param array $row Ticket row.
	 * @phpstan-param TicketRow $row
	 * @return FollowUpResult
	 */
	private static function bank_result_from_row( array $row ) {
		return array(
			'state'                => 'paid',
			'result_code'          => (string) $row['follow_up_result_code'],
			'result_description'   => '',
			'response_code'        => (string) $row['follow_up_response_code'],
			'response_description' => '',
			'status_flag'          => (string) $row['follow_up_status_flag'],
			'support_reference_id' => (string) $row['follow_up_support_reference_id'],
			'transaction_id'       => (string) $row['follow_up_transaction_id'],
			'transaction_at'       => (string) $row['follow_up_transaction_at'],
			'approval_code'        => '',
			'payment_method'       => (string) $row['follow_up_payment_method'],
			'iris_transaction_id'  => (string) $row['follow_up_iris_transaction_id'],
			'iris_status'          => (string) $row['follow_up_iris_status'],
			'error'                => '',
		);
	}

	/**
	 * Require payment-specific evidence before classifying a paid order locally.
	 *
	 * @param WC_Order $order Order being classified.
	 */
	private static function has_local_payment_provenance( WC_Order $order ): bool {
		return $order->is_paid()
			&& '' !== (string) $order->get_transaction_id()
			&& '' !== (string) $order->get_meta( '_epay_merchant_reference', true );
	}

	/**
	 * Apply one verified late success through WooCommerce's payment path.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $reference Successful reference.
	 * @param array    $result    Normalised bank result.
	 * @phpstan-param FollowUpResult $result
	 * @return bool Whether WooCommerce persisted the paid state.
	 */
	private static function settle_order( $order, $reference, array $result ) {
		$already_paid = $order->is_paid();
		$late         = $order->has_status( array( 'cancelled', 'failed' ) );
		if ( 'trash' === $order->get_status() ) {
			Epay_Paycenter_Review::record( 'trash_paid', $order->get_id() );
			return false;
		}

		try {
			$order->update_meta_data( '_epay_merchant_reference', $reference );
			$order->update_meta_data( '_epay_result_code', (string) $result['result_code'] );
			$order->update_meta_data( '_epay_result_description', (string) $result['result_description'] );
			$order->update_meta_data( '_epay_response_code', (string) $result['response_code'] );
			$order->update_meta_data( '_epay_response_description', (string) $result['response_description'] );
			$order->update_meta_data( '_epay_status_flag', (string) $result['status_flag'] );
			$order->update_meta_data( '_epay_support_reference_id', (string) $result['support_reference_id'] );
			$order->update_meta_data( '_epay_transaction_id', (string) $result['transaction_id'] );
			$order->update_meta_data( '_epay_approval_code', (string) $result['approval_code'] );
			$order->update_meta_data( '_epay_payment_method', (string) $result['payment_method'] );
			$order->update_meta_data( '_epay_iris_transaction_id', (string) $result['iris_transaction_id'] );
			$order->update_meta_data( '_epay_iris_status', (string) $result['iris_status'] );
			$order->update_meta_data( '_epay_follow_up_settled_reference', $reference );
			$order->update_meta_data( '_epay_follow_up_checked_at', current_time( 'mysql', true ) );
			if ( $late ) {
				$order->update_meta_data( '_epay_follow_up_late_payment', 'yes' );
			}

			if ( ! $already_paid && ! $order->payment_complete( (string) $result['transaction_id'] ) ) {
				Epay_Paycenter_Logger::error(
					'Paycenter follow-up was approved but WooCommerce could not complete the order',
					array( 'order_id' => $order->get_id() )
				);
				return false;
			}
			if ( $already_paid && '' === $order->get_transaction_id() ) {
				// A manually processed order still needs its verified bank transaction ID.
				$order->set_transaction_id( $result['transaction_id'] );
			}
			Epay_Paycenter_Open_Tickets::clear( $order );
			$order->add_order_note(
				sprintf(
					/* translators: 1: reference, 2: method, 3: response code, 4: transaction time. */
					__( 'ePay FOLLOW_UP confirmed payment. MerchantReference: %1$s; method: %2$s; ResponseCode: %3$s; transaction time: %4$s.', 'resilient-gateway-for-epay-paycenter' ),
					$reference,
					'' !== (string) $result['payment_method'] ? (string) $result['payment_method'] : '-',
					(string) $result['response_code'],
					'' !== (string) $result['transaction_at'] ? (string) $result['transaction_at'] : '-'
				)
			);
			if ( $late ) {
				$order->add_order_note( __( 'Attention: this payment was recovered after the order had been cancelled or failed. Verify stock and fulfilment before dispatch.', 'resilient-gateway-for-epay-paycenter' ) );
				Epay_Paycenter_Review::record( 'late_paid', $order->get_id(), $reference );
			}
			$order->save();
			return true;
		} catch ( Throwable $error ) {
			Epay_Paycenter_Logger::error(
				'WooCommerce could not persist an approved ePay follow-up result; it will be retried.',
				array( 'order_id' => $order->get_id() )
			);
			return false;
		}
	}

	/**
	 * Close historical attempts for an order with local payment provenance.
	 *
	 * @throws RuntimeException If an attempt classification cannot be persisted.
	 * @param WC_Order $order Order.
	 * @param array    $rows  Ticket rows.
	 * @phpstan-param list<TicketRow> $rows
	 */
	private static function mark_locally_paid( $order, array $rows ): void {
		global $wpdb;
		$table     = $wpdb->prefix . 'epay_paycenter_tickets';
		$reference = (string) $order->get_meta( '_epay_merchant_reference', true );
		$now       = gmdate( 'Y-m-d H:i:s' );
		foreach ( $rows as $row ) {
			$is_match = '' !== $reference && hash_equals( $reference, (string) $row['merchant_reference'] );
			$data     = array(
				'follow_up_state'   => 'local_paid',
				'next_check_at'     => null,
				'resolved_at'       => $now,
				'resolution_source' => 'woocommerce',
				'updated_at'        => $now,
				'status'            => $is_match ? Epay_Paycenter_Ticket_Audit::STATUS_SUCCEEDED : Epay_Paycenter_Ticket_Audit::STATUS_SUPERSEDED,
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ) );
			if ( false === $updated ) {
				throw new RuntimeException( 'Could not classify a locally paid ePay attempt.' );
			}
		}
		Epay_Paycenter_Open_Tickets::clear( $order );
		$order->save();
	}

	/**
	 * Record one fulfilment warning when several attempts were paid.
	 *
	 * @param WC_Order $order      Order.
	 * @param int      $paid_count Successful references.
	 */
	private static function flag_multiple_payments( $order, $paid_count ): void {
		if ( 'yes' === (string) $order->get_meta( '_epay_follow_up_multiple_payments', true ) ) {
			return;
		}
		$order->update_meta_data( '_epay_follow_up_multiple_payments', 'yes' );
		$order->add_order_note(
			sprintf(
				/* translators: %d: number of successful references. */
				__( 'URGENT: ePay FOLLOW_UP found %d successful MerchantReferences for this order. Check the AdminTool for a possible duplicate charge before fulfilment.', 'resilient-gateway-for-epay-paycenter' ),
				(int) $paid_count
			)
		);
		$order->save();
		Epay_Paycenter_Review::record( 'double_paid', $order->get_id() );
	}

	/**
	 * Apply the gateway's stock reservation only while recovery is enabled.
	 *
	 * @param int      $minutes Existing minutes.
	 * @param WC_Order $order   Order.
	 * @return int
	 */
	public static function filter_stock_hold_minutes( $minutes, $order ) {
		return self::is_enabled()
			&& EPAY_PAYCENTER_GATEWAY_ID === $order->get_payment_method()
			? self::stock_hold_minutes()
			: $minutes;
	}

	/**
	 * Delay WooCommerce cancellation until the recovery reservation expires.
	 *
	 * @param bool     $cancel Whether core would cancel.
	 * @param WC_Order $order  Order.
	 * @return bool
	 */
	public static function filter_cancel_unpaid_order( $cancel, $order ) {
		if ( ! $cancel
			|| ! self::is_enabled()
			|| EPAY_PAYCENTER_GATEWAY_ID !== $order->get_payment_method() ) {
			return $cancel;
		}
		$created = $order->get_date_created();
		if ( ! $created ) {
			return $cancel;
		}
		return ( time() - $created->getTimestamp() ) >= self::stock_hold_minutes() * MINUTE_IN_SECONDS;
	}

	/**
	 * Restore timers removed during a package switch without extending the hold.
	 *
	 * @param WC_Order $order Order with an unfinished bank check.
	 */
	private static function restore_stock_release( WC_Order $order ): void {
		$args    = array( $order->get_id() );
		$created = $order->get_date_created();
		if ( ! self::is_enabled() || ! $created || doing_action( self::STOCK_RELEASE_HOOK )
			|| EPAY_PAYCENTER_GATEWAY_ID !== $order->get_payment_method()
			|| ! $order->has_status( array( 'pending', 'on-hold' ) )
			|| wp_next_scheduled( self::STOCK_RELEASE_HOOK, $args ) ) {
			return;
		}
		$due    = max( time() + 10, $created->getTimestamp() + self::stock_hold_minutes() * MINUTE_IN_SECONDS );
		$result = wp_schedule_single_event( $due, self::STOCK_RELEASE_HOOK, $args, true );
		if ( is_wp_error( $result ) ) {
			Epay_Paycenter_Logger::error( 'Could not restore ePay stock release after activation.', array( 'order_id' => $order->get_id() ) );
		}
	}

	/**
	 * Four-hour fallback releases stock while bank polling continues.
	 *
	 * @param int $order_id Order id.
	 */
	public static function release_stock( $order_id ): void {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! self::is_enabled()
			|| ! $order instanceof WC_Order
			|| EPAY_PAYCENTER_GATEWAY_ID !== $order->get_payment_method()
			|| ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			return;
		}
		$result = self::reconcile_order( $order->get_id(), true );
		if ( ! empty( $result['paid'] ) ) {
			return;
		}
		if ( ! empty( $result['lock_skipped'] ) || ! empty( $result['query_errors'] ) || ! empty( $result['settlement_errors'] ) ) {
			$args      = array( $order->get_id() );
			$scheduled = wp_schedule_single_event( time() + 300, self::STOCK_RELEASE_HOOK, $args, true );
			if ( is_wp_error( $scheduled ) ) {
				Epay_Paycenter_Logger::error(
					'Could not retry ePay stock release after an inconclusive reconciliation.',
					array( 'order_id' => $order->get_id() )
				);
			}
			return;
		}
		$order = wc_get_order( $order->get_id() );
		if ( ! $order instanceof WC_Order || $order->is_paid() || ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			return;
		}
		$order->update_status( 'cancelled', __( 'ePay payment was not confirmed within the four-hour stock reservation. Bank follow-up continues for 48 hours.', 'resilient-gateway-for-epay-paycenter' ) );
		$order->save();
	}

	/**
	 * Constrain the configured stock reservation to 60..1440 minutes.
	 *
	 * @return int
	 */
	public static function stock_hold_minutes() {
		$settings = self::settings();
		$stored   = isset( $settings['follow_up_hold_minutes'] ) ? (string) $settings['follow_up_hold_minutes'] : '';
		$value    = '' === $stored ? self::DEFAULT_HOLD_MINUTES : absint( $stored );
		return max( 60, min( 1440, $value ) );
	}

	/**
	 * Load unresolved attempts in creation order.
	 *
	 * @param int  $order_id Order ID.
	 * @param bool $force Include attempts before their next scheduled check.
	 * @throws RuntimeException If the database query fails.
	 * @return list<TicketRow>
	 */
	private static function rows_for_order( int $order_id, bool $force ) {
		global $wpdb;
		$table = $wpdb->prefix . 'epay_paycenter_tickets';
		$now   = gmdate( 'Y-m-d H:i:s' );
		if ( $force ) {
			$sql = $wpdb->prepare(
				'SELECT * FROM %i WHERE order_id = %d AND resolved_at IS NULL ORDER BY id ASC LIMIT %d',
				$table,
				$order_id,
				self::MAX_ATTEMPTS_PER_ORDER
			);
		} else {
			$sql = $wpdb->prepare(
				'SELECT * FROM %i WHERE order_id = %d AND resolved_at IS NULL AND next_check_at IS NOT NULL AND next_check_at <= %s ORDER BY id ASC LIMIT %d',
				$table,
				$order_id,
				$now,
				self::MAX_ATTEMPTS_PER_ORDER
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Both query branches prepare every identifier and value; the queue must be read fresh.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( 'Could not load ePay reconciliation attempts.' );
		}
		return array_map( array( __CLASS__, 'normalise_row' ), array_values( $rows ) );
	}

	/**
	 * Validate the database boundary before using an attempt for settlement.
	 *
	 * @param array<string,mixed> $row Database result.
	 * @return TicketRow
	 */
	private static function normalise_row( array $row ): array {
		return array(
			'id'                             => self::row_integer( $row, 'id' ),
			'order_id'                       => self::row_integer( $row, 'order_id' ),
			'merchant_reference'             => self::row_string( $row, 'merchant_reference' ),
			'created_at'                     => self::row_string( $row, 'created_at' ),
			'follow_up_attempts'             => self::row_integer( $row, 'follow_up_attempts' ),
			'follow_up_state'                => self::row_string( $row, 'follow_up_state' ),
			'follow_up_result_code'          => self::row_string( $row, 'follow_up_result_code' ),
			'follow_up_response_code'        => self::row_string( $row, 'follow_up_response_code' ),
			'follow_up_status_flag'          => self::row_string( $row, 'follow_up_status_flag' ),
			'follow_up_support_reference_id' => self::row_string( $row, 'follow_up_support_reference_id' ),
			'follow_up_transaction_id'       => self::row_string( $row, 'follow_up_transaction_id' ),
			'follow_up_transaction_at'       => self::row_string( $row, 'follow_up_transaction_at', true ),
			'follow_up_payment_method'       => self::row_string( $row, 'follow_up_payment_method' ),
			'follow_up_iris_transaction_id'  => self::row_string( $row, 'follow_up_iris_transaction_id' ),
			'follow_up_iris_status'          => self::row_string( $row, 'follow_up_iris_status' ),
		);
	}

	/**
	 * Read a string column, allowing NULL only where the schema permits it.
	 *
	 * @throws RuntimeException If the column is missing or violates the schema.
	 * @param array<string,mixed> $row Database result.
	 * @param string              $column Column name.
	 * @param bool                $nullable Whether NULL represents an absent value.
	 * @return string
	 */
	private static function row_string( array $row, string $column, bool $nullable = false ): string {
		if ( ! array_key_exists( $column, $row ) ) {
			throw new RuntimeException( 'A reconciliation column is missing.' );
		}
		$value = $row[ $column ];
		if ( $nullable && null === $value ) {
			return '';
		}
		if ( ! is_string( $value ) ) {
			throw new RuntimeException( 'A reconciliation string column is invalid.' );
		}
		return $value;
	}

	/**
	 * Read integer columns returned as either native integers or decimal text.
	 *
	 * @throws RuntimeException If the column does not contain a supported integer.
	 * @param array<string,mixed> $row Database result.
	 * @param string              $column Column name.
	 * @return int
	 */
	private static function row_integer( array $row, string $column ): int {
		$value = $row[ $column ] ?? null;
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( ! is_string( $value ) || ! ctype_digit( $value ) || false === filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) ) ) {
			throw new RuntimeException( 'A reconciliation integer column is invalid.' );
		}
		return (int) $value;
	}

	/**
	 * Find recent issued references for a read-only channel verification.
	 *
	 * @param int $order_id Order ID.
	 * @return string[]
	 */
	private static function references_for_order( int $order_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'epay_paycenter_tickets';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$references = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT merchant_reference FROM %i WHERE order_id = %d AND merchant_reference <> '' ORDER BY id DESC LIMIT %d",
				$table,
				$order_id,
				self::MAX_ATTEMPTS_PER_ORDER
			)
		);
		if ( ! is_array( $references ) ) {
			Epay_Paycenter_Logger::error( 'Could not load issued references for ePay channel verification.' );
			return array();
		}
		return array_values( array_unique( array_map( 'strval', $references ) ) );
	}

	/**
	 * Select the first future checkpoint within the 48-hour polling window.
	 *
	 * @param int $created Attempt creation timestamp.
	 * @param int $now Current timestamp.
	 * @return string|null
	 */
	private static function next_checkpoint( int $created, int $now ) {
		$age = max( 0, $now - $created );
		foreach ( self::$checkpoints as $offset ) {
			if ( $offset > $age ) {
				return gmdate( 'Y-m-d H:i:s', $created + $offset );
			}
		}
		return null;
	}

	/**
	 * Count bank-approved attempts, including approvals awaiting local settlement.
	 *
	 * @param int $order_id Order ID.
	 * @throws RuntimeException If the count query fails.
	 * @return int
	 */
	private static function paid_attempt_count( int $order_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'epay_paycenter_tickets';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE order_id = %d AND follow_up_state IN ('paid','paid_unsettled')", $table, $order_id ) );
		if ( null === $count ) {
			throw new RuntimeException( 'Could not count paid ePay attempts.' );
		}
		return (int) $count;
	}

	/**
	 * Count attempts that still require bank or local settlement work.
	 *
	 * @param int $order_id Order ID.
	 * @throws RuntimeException If the count query fails.
	 * @return int
	 */
	private static function open_attempt_count( int $order_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'epay_paycenter_tickets';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id = %d AND resolved_at IS NULL', $table, $order_id ) );
		if ( null === $count ) {
			throw new RuntimeException( 'Could not count open ePay attempts.' );
		}
		return (int) $count;
	}

	/**
	 * Count attempts that exhausted the polling window without a final result.
	 *
	 * @param int $order_id Order ID.
	 * @throws RuntimeException If the count query fails.
	 * @return int
	 */
	private static function unresolved_count( int $order_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'epay_paycenter_tickets';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE order_id = %d AND follow_up_state = 'unresolved'", $table, $order_id ) );
		if ( null === $count ) {
			throw new RuntimeException( 'Could not count unresolved ePay attempts.' );
		}
		return (int) $count;
	}

	/**
	 * Normalize a bank timestamp for the UTC database column.
	 *
	 * @param string $value Bank timestamp.
	 * @return string|null
	 */
	private static function normalise_datetime( string $value ) {
		if ( '' === $value ) {
			return null;
		}
		$timestamp = strtotime( $value );
		return false === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Delete resolved audit rows after the upstream 90-day window.
	 *
	 * @throws RuntimeException If the deletion fails.
	 */
	private static function prune(): void {
		global $wpdb;
		$table  = $wpdb->prefix . 'epay_paycenter_tickets';
		$days   = max( 1, (int) apply_filters( 'epay_paycenter_reconcile_retention_days', 90 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE resolved_at IS NOT NULL AND follow_up_state <> 'unresolved' AND updated_at < %s", $table, $cutoff ) );
		if ( false === $result ) {
			throw new RuntimeException( 'Could not prune old ePay reconciliation records.' );
		}
	}

	/**
	 * Acquire the per-order option lock, expiring abandoned workers after ten minutes.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	private static function acquire_lock( int $order_id ) {
		$key = self::LOCK_PREFIX . absint( $order_id );
		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}
		$started = (int) get_option( $key, 0 );
		if ( $started > 0 && $started < time() - 600 ) {
			delete_option( $key );
			return add_option( $key, time(), '', false );
		}
		return false;
	}

	/**
	 * Release the per-order lock.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function release_lock( int $order_id ): void {
		delete_option( self::LOCK_PREFIX . absint( $order_id ) );
	}

	/**
	 * Bind channel verification to the current credentials without storing them.
	 *
	 * @return string HMAC fingerprint.
	 */
	private static function credential_fingerprint() {
		$credentials = Epay_Paycenter_Follow_Up::credentials_from_settings();
		if ( empty( $credentials['merchant_id'] ) || empty( $credentials['pos_id'] ) || empty( $credentials['user'] ) || empty( $credentials['password_digest'] ) ) {
			return '';
		}
		$material = implode(
			"\0",
			array(
				(string) $credentials['mode'],
				(string) $credentials['merchant_id'],
				(string) $credentials['pos_id'],
				(string) $credentials['user'],
				(string) $credentials['password_digest'],
			)
		);
		return hash_hmac( 'sha256', $material, wp_salt( 'auth' ) );
	}

	/**
	 * Read the shared WooCommerce gateway settings.
	 *
	 * @return array<string,mixed>
	 */
	private static function settings() {
		return (array) get_option( 'woocommerce_' . EPAY_PAYCENTER_GATEWAY_ID . '_settings', array() );
	}

	/**
	 * Initialize every outcome counter for a reconciliation run.
	 *
	 * @return RunSummary
	 */
	private static function empty_summary() {
		return array(
			'paid'              => 0,
			'declined'          => 0,
			'pending'           => 0,
			'query_errors'      => 0,
			'local_paid'        => 0,
			'unresolved'        => 0,
			'double_payments'   => 0,
			'settlement_errors' => 0,
			'lock_skipped'      => 0,
		);
	}
}
