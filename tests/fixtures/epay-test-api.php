<?php
/**
 * Local-only REST seam for payment qualification.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'EPAY_TEST_FIXTURES' ) || ! EPAY_TEST_FIXTURES || 'local' !== wp_get_environment_type() ) {
	return;
}

function epay_test_test_request_allowed() {
	return function_exists( 'epay_test_fixture_is_local' )
		&& epay_test_fixture_is_local()
		&& 'epay-qualification' === ( $_SERVER['HTTP_X_EPAY_TEST'] ?? '' );
}

add_filter(
	'determine_locale',
	static function ( $locale ) {
		return epay_test_test_request_allowed() && 'el' === ( $_SERVER['HTTP_X_EPAY_TEST_LOCALE'] ?? '' )
			? 'el'
			: $locale;
	}
);

function epay_test_locale() {
	return array(
		'locale'       => determine_locale(),
		'not_verified' => __( 'Not verified', 'resilient-gateway-for-epay-paycenter' ),
	);
}

add_filter(
	'epay_paycenter_allowed_callback_ips',
	static function () {
		return (array) get_option( 'epay_test_callback_allowed_ips', array() );
	}
);
add_filter(
	'epay_paycenter_trusted_proxy_ips',
	static function () {
		return (array) get_option( 'epay_test_callback_trusted_proxy_ips', array() );
	}
);
add_filter(
	'epay_paycenter_icon',
	static function ( $icon_url ) {
		$override = get_option( 'epay_test_epay_icon_override', false );
		return false === $override ? $icon_url : (string) $override;
	}
);

function epay_test_ticket_row_count( $order_id ) {
	global $wpdb;
	$table = $wpdb->prefix . 'epay_paycenter_tickets';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return 0;
	}

	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_id = %d", $order_id )
	);
}

function epay_test_ticket_statuses( $order_id ) {
	global $wpdb;
	$table = $wpdb->prefix . 'epay_paycenter_tickets';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return array();
	}

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT merchant_reference, status FROM {$table} WHERE order_id = %d ORDER BY id ASC",
			$order_id
		),
		ARRAY_A
	);
	$statuses = array();
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$reference = isset( $row['merchant_reference'] ) ? (string) $row['merchant_reference'] : '';
		if ( '' !== $reference ) {
			$statuses[ $reference ] = isset( $row['status'] ) ? (string) $row['status'] : '';
		}
	}
	return (object) $statuses;
}

function epay_test_ticket_follow_up( $order_id ) {
	global $wpdb;
	$table = $wpdb->prefix . 'epay_paycenter_tickets';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return array();
	}
	$column = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'follow_up_state'" );
	if ( 'follow_up_state' !== $column ) {
		return array();
	}

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT merchant_reference, follow_up_state, follow_up_attempts, follow_up_result_code,
				follow_up_response_code, follow_up_status_flag, follow_up_payment_method
			 FROM {$table} WHERE order_id = %d ORDER BY id ASC",
			$order_id
		),
		ARRAY_A
	);
	$details = array();
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$reference = (string) ( $row['merchant_reference'] ?? '' );
		if ( '' !== $reference ) {
			$details[ $reference ] = array(
				'state'         => (string) $row['follow_up_state'],
				'attempts'      => (int) $row['follow_up_attempts'],
				'result_code'   => (string) $row['follow_up_result_code'],
				'response_code' => (string) $row['follow_up_response_code'],
				'status_flag'   => (string) $row['follow_up_status_flag'],
				'payment_method'=> (string) $row['follow_up_payment_method'],
			);
		}
	}
	return (object) $details;
}

function epay_test_describe_order( $order ) {
	$open_tickets = class_exists( 'Epay_Paycenter_Open_Tickets' )
		? Epay_Paycenter_Open_Tickets::all( $order )
		: array();
	$open_tickets = is_array( $open_tickets ) ? $open_tickets : array();
	$has_secrets       = false;
	$open_secret_count = 0;
	foreach ( $open_tickets as $attempt ) {
		if ( is_array( $attempt ) && ( '' !== ( $attempt['ticket'] ?? '' ) || '' !== ( $attempt['cancel'] ?? '' ) ) ) {
			$has_secrets = true;
			++$open_secret_count;
		}
	}

	return apply_filters( 'epay_test_order_description', array(
		'order_id'          => $order->get_id(),
		'status'            => $order->get_status(),
		'payment_method'    => $order->get_payment_method(),
		'epay'              => array(
			'merchant_reference'         => (string) $order->get_meta( '_epay_merchant_reference', true ),
			'ticket_rows'                => epay_test_ticket_row_count( $order->get_id() ),
			'ticket_statuses'             => epay_test_ticket_statuses( $order->get_id() ),
			'open_ticket_count'          => $open_secret_count,
			'has_open_ticket_secrets'    => $has_secrets,
			'has_legacy_tran_ticket'     => '' !== (string) $order->get_meta( '_epay_tran_ticket', true ),
			'has_legacy_cancel_token'    => '' !== (string) $order->get_meta( '_epay_cancel_token', true ),
			'has_transaction_id'         => '' !== (string) $order->get_transaction_id(),
			'has_callback_transaction_id'=> '' !== (string) $order->get_meta( '_epay_transaction_id', true ),
			'transaction_id'             => (string) $order->get_transaction_id(),
			'callback_transaction_id'    => (string) $order->get_meta( '_epay_transaction_id', true ),
			'settled_reference'          => (string) $order->get_meta( '_epay_follow_up_settled_reference', true ),
			'payment_complete_count'     => (int) $order->get_meta( '_epay_test_payment_complete_count', true ),
			'result_code'                => (string) $order->get_meta( '_epay_result_code', true ),
			'payment_method'             => (string) $order->get_meta( '_epay_payment_method', true ),
			'follow_up'                  => epay_test_ticket_follow_up( $order->get_id() ),
			'stock_release_scheduled'    => false !== wp_next_scheduled( 'epay_paycenter_release_stock', array( $order->get_id() ) ),
			'follow_up_multiple_payments'=> rest_sanitize_boolean( $order->get_meta( '_epay_follow_up_multiple_payments', true ) ),
			'follow_up_late_payment'     => rest_sanitize_boolean( $order->get_meta( '_epay_follow_up_late_payment', true ) ),
			'has_recharge_attempt'       => '' !== (string) $order->get_meta( '_epay_recharge_attempt_at', true ),
		),
	), $order );
}

function epay_test_health() {
	$settings       = (array) get_option( 'woocommerce_epay_paycenter_settings', array() );
	$active_plugins = (array) get_option( 'active_plugins', array() );
	$password       = (string) ( $settings['password'] ?? '' );

	return apply_filters( 'epay_test_health', array(
		'local_only'            => epay_test_fixture_is_local(),
		'fake_epay_available'   => defined( 'EPAY_TEST_FAKE_HTTP' ) && EPAY_TEST_FAKE_HTTP,
		'epay_version'          => defined( 'EPAY_PAYCENTER_VERSION' ) ? EPAY_PAYCENTER_VERSION : '',
		'epay_enabled'          => 'yes' === ( $settings['enabled'] ?? '' ),
		'epay_mode'             => (string) ( $settings['mode'] ?? '' ),
		'epay_password_storage' => preg_match( '/^md5:[a-f0-9]{32}$/', $password ) ? 'md5-digest' : 'invalid',
		'legacy_papaki_active'  => in_array(
			'woo-payment-gateway-for-piraeus-bank/wooshop-piraeus.php',
			$active_plugins,
			true
		),
		'follow_up_enabled'     => class_exists( 'Epay_Paycenter_Reconciliation' )
			&& method_exists( 'Epay_Paycenter_Reconciliation', 'is_enabled' )
			&& Epay_Paycenter_Reconciliation::is_enabled(),
		'follow_up_channel'     => class_exists( 'Epay_Paycenter_Reconciliation' )
			&& method_exists( 'Epay_Paycenter_Reconciliation', 'verified_channel' )
			? Epay_Paycenter_Reconciliation::verified_channel()
			: '',
	) );
}

function epay_test_create_order( $request ) {
	$product = wc_get_product( absint( $request->get_param( 'product_id' ) ?: get_option( 'epay_test_product_id', 0 ) ) );
	if ( ! $product ) {
		return new WP_Error( 'epay_test_missing_product', 'A valid product_id is required.', array( 'status' => 400 ) );
	}

	$shipping_method = sanitize_text_field( $request->get_param( 'shipping_method' ) ?: 'flat_rate' );
	$order           = wc_create_order();
	$order->set_created_via( 'checkout' );
	$order->add_product( $product, 1 );
	$order->set_address(
		array(
			'first_name' => 'TST',
			'last_name'  => 'Checkout',
			'address_1'  => 'Test Street 1',
			'city'       => 'Athens',
			'state'      => 'I',
			'postcode'   => '10557',
			'country'    => 'GR',
			'email'      => 'checkout@example.test',
			'phone'      => '+306912345678',
		),
		'billing'
	);
	$order->set_address(
		array(
			'first_name' => 'TST',
			'last_name'  => 'Checkout',
			'address_1'  => 'Test Street 1',
			'city'       => 'Athens',
			'state'      => 'I',
			'postcode'   => '10557',
			'country'    => 'GR',
		),
		'shipping'
	);

	$shipping = new WC_Order_Item_Shipping();
	$shipping->set_method_title( 'Qualification shipping' );
	$shipping->set_method_id( $shipping_method );
	$shipping->set_total( 2 );
	$order->add_item( $shipping );
	$order->set_payment_method( 'epay_paycenter' );
	$order->set_payment_method_title( 'ePay Paycenter' );
	do_action( 'epay_test_order_created', $order, $request );
	$order->calculate_totals();
	$order->set_status( 'pending' );
	$order->save();

	return array(
		'order_id'    => $order->get_id(),
		'receipt_url' => $order->get_checkout_payment_url( true ),
		'order'       => epay_test_describe_order( $order ),
	);
}

function epay_test_read_order( $request ) {
	$order = wc_get_order( (int) $request['id'] );
	return $order instanceof WC_Order
		? epay_test_describe_order( $order )
		: new WP_Error( 'epay_test_order_not_found', 'Order not found.', array( 'status' => 404 ) );
}

function epay_test_cancel_unpaid_order( $request ) {
	global $wpdb;

	$order = wc_get_order( (int) $request['id'] );
	if ( ! $order instanceof WC_Order || 'pending' !== $order->get_status() ) {
		return new WP_Error( 'epay_test_order_not_pending', 'A pending order is required.', array( 'status' => 400 ) );
	}

	$hold_minutes = max( 1, absint( get_option( 'woocommerce_hold_stock_minutes', '60' ) ) );
	$age_minutes  = max( $hold_minutes + 5, absint( $request->get_param( 'age_minutes' ) ?: 90 ) );
	$timestamp    = time() - ( $age_minutes * MINUTE_IN_SECONDS );
	$order->set_date_created( $timestamp );
	$order->save();

	$orders_table = $wpdb->prefix . 'wc_orders';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_table ) ) === $orders_table ) {
		$wpdb->update(
			$orders_table,
			array( 'date_updated_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ) ),
			array( 'id' => $order->get_id() ),
			array( '%s' ),
			array( '%d' )
		);
	}
	$wpdb->update(
		$wpdb->posts,
		array(
			'post_modified'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) ),
			'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ),
		),
		array( 'ID' => $order->get_id() ),
		array( '%s', '%s' ),
		array( '%d' )
	);
	clean_post_cache( $order->get_id() );
	wc_cancel_unpaid_orders();

	return array( 'order' => epay_test_describe_order( wc_get_order( $order->get_id() ) ) );
}

function epay_test_set_fake_result( $request ) {
	$result_code = sanitize_text_field( (string) $request->get_param( 'result_code' ) );
	update_option( 'epay_test_fake_epay_result_code', $result_code, false );
	return array( 'result_code' => $result_code );
}

function epay_test_set_fake_barrier( $request ) {
	$target = min( 2, absint( $request->get_param( 'target' ) ) );
	update_option( 'epay_test_fake_epay_barrier_target', $target, false );
	update_option( 'epay_test_fake_epay_barrier_count', 0, false );
	return array( 'target' => $target );
}

function epay_test_set_fake_waf_result( $request ) {
	$scenario = $request->get_param( 'scenario' );
	if ( ! is_string( $scenario ) || ! in_array( $scenario, array( 'passthrough', 'handler_redirect', 'html_200', 'missing_marker', 'wrong_location' ), true ) ) {
		return new WP_Error( 'epay_test_invalid_waf_scenario', 'Unknown callback diagnostic response.', array( 'status' => 400 ) );
	}
	update_option( 'epay_test_fake_waf_scenario', $scenario, false );
	return array( 'scenario' => $scenario );
}

function epay_test_set_fake_follow_up( $request ) {
	$allowed_scenarios = array( 'paid', 'pending', 'declined', 'not_found', 'identity_mismatch', 'incomplete_paid', 'transport_error', 'failure_09_iris', 'failure_09_unknown', 'failure_09_card' );
	$allowed_channels  = array( 'eCommerce', '3DSecure' );
	$scenario          = sanitize_key( (string) $request->get_param( 'scenario' ) );
	$channel           = sanitize_text_field( (string) $request->get_param( 'channel' ) );
	if ( ! in_array( $scenario, $allowed_scenarios, true ) ) {
		$scenario = 'paid';
	}
	if ( ! in_array( $channel, $allowed_channels, true ) ) {
		$channel = 'eCommerce';
	}
	update_option( 'epay_test_fake_follow_up_scenario', $scenario, false );
	update_option( 'epay_test_fake_follow_up_channel', $channel, false );
	update_option( 'epay_test_fake_follow_up_requests', array(), false );
	return array( 'scenario' => $scenario, 'channel' => $channel );
}

function epay_test_reset_follow_up() {
	delete_option( 'epay_paycenter_reconcile_report' );
	delete_transient( 'epay_paycenter_reconcile_dismissed' );
	$settings                      = (array) get_option( 'woocommerce_epay_paycenter_settings', array() );
	$settings['follow_up_enabled'] = 'no';
	update_option( 'woocommerce_epay_paycenter_settings', $settings, false );
	delete_option( 'epay_paycenter_follow_up_verification' );
	delete_option( 'epay_paycenter_follow_up_active' );
	delete_option( 'epay_test_fail_next_payment_complete' );
	update_option( 'epay_test_fake_follow_up_scenario', 'paid', false );
	update_option( 'epay_test_fake_follow_up_channel', 'eCommerce', false );
	update_option( 'epay_test_fake_follow_up_requests', array(), false );
	return epay_test_health();
}

function epay_test_test_follow_up_channel( $request ) {
	if ( ! class_exists( 'Epay_Paycenter_Reconciliation' )
		|| ! method_exists( 'Epay_Paycenter_Reconciliation', 'test_channel_for_order' ) ) {
		return new WP_Error( 'epay_test_follow_up_unavailable', 'Follow-up implementation is unavailable.', array( 'status' => 501 ) );
	}
	return Epay_Paycenter_Reconciliation::test_channel_for_order( (int) $request['id'] );
}

function epay_test_enable_follow_up() {
	$settings                      = (array) get_option( 'woocommerce_epay_paycenter_settings', array() );
	$settings['follow_up_enabled'] = 'yes';
	update_option( 'woocommerce_epay_paycenter_settings', $settings, false );
	if ( class_exists( 'Epay_Paycenter_Reconciliation' )
		&& method_exists( 'Epay_Paycenter_Reconciliation', 'settings_updated' ) ) {
		Epay_Paycenter_Reconciliation::settings_updated();
	}
	return epay_test_health();
}

function epay_test_fail_next_payment_complete( $request ) {
	$failure_mode = rest_sanitize_boolean( $request->get_param( 'persist_reference' ) )
		? 'after_reference_saved'
		: 'before_reference_saved';
	update_option( 'epay_test_fail_next_payment_complete', $failure_mode, false );
	return array( 'armed' => true );
}

add_action(
	'woocommerce_pre_payment_complete',
	static function ( $order_id ) {
		$failure_mode = get_option( 'epay_test_fail_next_payment_complete' );
		if ( ! $failure_mode ) {
			return;
		}
		delete_option( 'epay_test_fail_next_payment_complete' );
		if ( 'after_reference_saved' === $failure_mode ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				throw new RuntimeException( 'TST fixture: payment completion order is missing.' );
			}
			$order->update_meta_data( '_epay_follow_up_settled_reference', $order->get_meta( '_epay_merchant_reference', true ) );
			$order->save();
		}
		throw new Exception( 'TST fixture: simulated payment completion failure.' );
	}
);

add_action(
	'woocommerce_payment_complete',
	static function ( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$order->update_meta_data( '_epay_test_payment_complete_count', (int) $order->get_meta( '_epay_test_payment_complete_count', true ) + 1 );
			$order->save();
		}
	}
);

function epay_test_run_follow_up( $request ) {
	if ( ! class_exists( 'Epay_Paycenter_Reconciliation' )
		|| ! method_exists( 'Epay_Paycenter_Reconciliation', 'reconcile_order' ) ) {
		return new WP_Error( 'epay_test_follow_up_unavailable', 'Follow-up implementation is unavailable.', array( 'status' => 501 ) );
	}
	$result   = Epay_Paycenter_Reconciliation::reconcile_order( (int) $request['id'], true );
	$order    = wc_get_order( (int) $request['id'] );
	$requests = array_values( (array) get_option( 'epay_test_fake_follow_up_requests', array() ) );
	return array(
		'result'          => $result,
		'requests'        => $requests,
		'order'           => $order ? epay_test_describe_order( $order ) : null,
		'ticket_statuses' => epay_test_ticket_statuses( (int) $request['id'] ),
		'follow_up'       => epay_test_ticket_follow_up( (int) $request['id'] ),
	);
}

/** Model an interrupted worker after persisting a sibling's bank approval. */
function epay_test_persist_paid_sibling( $request ) {
	global $wpdb;
	$order = wc_get_order( (int) $request['id'] );
	$reference = $request->get_param( 'reference' );
	if ( ! $order instanceof WC_Order || ! $order->is_paid()
		|| ! is_string( $reference )
		|| '' === (string) $order->get_meta( '_epay_follow_up_settled_reference', true )
		|| $reference === (string) $order->get_meta( '_epay_follow_up_settled_reference', true ) ) {
		return new WP_Error( 'epay_test_invalid_paid_sibling', 'An already-paid order and a distinct issued sibling are required.', array( 'status' => 400 ) );
	}
	$table = $wpdb->prefix . 'epay_paycenter_tickets';
	$row_id = $wpdb->get_var( $wpdb->prepare(
		'SELECT id FROM %i WHERE order_id = %d AND merchant_reference = %s',
		$table,
		$order->get_id(),
		$reference
	) );
	if ( ! $row_id ) {
		return new WP_Error( 'epay_test_attempt_not_found', 'The sibling reference was not issued for this order.', array( 'status' => 404 ) );
	}
	$now = gmdate( 'Y-m-d H:i:s' );
	$updated = $wpdb->update( $table, array(
		'status'                         => 'succeeded',
		'follow_up_state'                => 'paid_unsettled',
		'follow_up_attempts'             => 1,
		'last_checked_at'                => $now,
		'next_check_at'                  => $now,
		'follow_up_result_code'          => '0',
		'follow_up_response_code'        => '00',
		'follow_up_status_flag'          => 'Success',
		'follow_up_support_reference_id' => '7654321',
		'follow_up_transaction_id'       => '987654321',
		'follow_up_transaction_at'       => '2020-01-02 12:34:56',
		'follow_up_payment_method'       => 'IRIS',
		'follow_up_iris_transaction_id'  => 'TST-IRIS-987654321',
		'follow_up_iris_status'          => 'Authorised',
		'resolution_source'              => 'follow_up',
		'resolved_at'                    => null,
		'updated_at'                     => $now,
	), array( 'id' => (int) $row_id ) );
	if ( 1 !== $updated ) {
		return new WP_Error( 'epay_test_attempt_not_persisted', 'Could not persist the synthetic approval.', array( 'status' => 500 ) );
	}
	return epay_test_describe_order( $order );
}

function epay_test_run_follow_up_worker() {
	if ( ! class_exists( 'Epay_Paycenter_Reconciliation' )
		|| ! method_exists( 'Epay_Paycenter_Reconciliation', 'run' ) ) {
		return new WP_Error( 'epay_test_follow_up_unavailable', 'Follow-up implementation is unavailable.', array( 'status' => 501 ) );
	}
	return array( 'result' => Epay_Paycenter_Reconciliation::run() );
}

/** Seed only synthetic rows in the state written by the previous parser. */
function epay_test_seed_closed_09( $request ) {
	global $wpdb;
	$order = wc_get_order( (int) $request['id'] );
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'missing_order', 'Synthetic order not found.', array( 'status' => 404 ) );
	}
	$order->update_status( 'failed', 'TST previous parser declined an ambiguous response.' );
	$wpdb->update( $wpdb->prefix . 'epay_paycenter_tickets', array(
		'status' => 'failed', 'follow_up_state' => 'declined', 'follow_up_attempts' => 1,
		'follow_up_result_code' => '0', 'follow_up_status_flag' => 'Failure',
		'follow_up_response_code' => '09',
		'follow_up_payment_method' => 'card' === $request->get_param( 'method' ) ? 'Card' : '',
		'resolution_source' => 'follow_up', 'resolved_at' => gmdate( 'Y-m-d H:i:s' ),
		'next_check_at' => null,
	), array( 'order_id' => $order->get_id() ) );
	delete_option( 'epay_paycenter_ambiguous_09_repaired' );
	update_option( 'epay_paycenter_db_version', '2.1' );
	return epay_test_describe_order( $order );
}

function epay_test_age_attempt( $request ) {
	global $wpdb;
	$wpdb->update( $wpdb->prefix . 'epay_paycenter_tickets',
		array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ),
		array( 'order_id' => (int) $request['id'] ) );
	return array( 'aged' => true );
}

function epay_test_drop_stock_event( $request ) {
	wp_clear_scheduled_hook( 'epay_paycenter_release_stock', array( (int) $request['id'] ) );
	return array( 'cleared' => true );
}

function epay_test_prioritise_follow_up( $request ) {
	global $wpdb;
	$table = $wpdb->prefix . 'epay_paycenter_tickets';
	$wpdb->update(
		$table,
		array( 'next_check_at' => '2000-01-01 00:00:00' ),
		array( 'order_id' => (int) $request['id'] ),
		array( '%s' ),
		array( '%d' )
	);
	return array( 'order_id' => (int) $request['id'] );
}

function epay_test_trash_order( $request ) {
	$order = wc_get_order( (int) $request['id'] );
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'epay_test_order_not_found', 'Order not found.', array( 'status' => 404 ) );
	}
	$order->delete( false );
	$remaining = wc_get_order( (int) $request['id'] );
	return array( 'order' => $remaining ? epay_test_describe_order( $remaining ) : null );
}

function epay_test_refund_order( $request ) {
	$order = wc_get_order( (int) $request['id'] );
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'epay_test_order_not_found', 'Order not found.', array( 'status' => 404 ) );
	}
	$order->update_status( 'refunded', 'TST fixture: terminal refunded order.' );
	return array( 'order' => epay_test_describe_order( wc_get_order( $order->get_id() ) ) );
}

function epay_test_mark_order_locally_paid( $request ) {
	$order = wc_get_order( (int) $request['id'] );
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'epay_test_order_not_found', 'Order not found.', array( 'status' => 404 ) );
	}
	$references = array_keys( (array) epay_test_ticket_statuses( $order->get_id() ) );
	if ( empty( $references ) ) {
		return new WP_Error( 'epay_test_attempt_not_found', 'Issued payment attempt not found.', array( 'status' => 400 ) );
	}
	$order->update_meta_data( '_epay_merchant_reference', (string) $references[0] );
	$order->payment_complete( '123456789' );
	$order->save();
	return array( 'order' => epay_test_describe_order( wc_get_order( $order->get_id() ) ) );
}

function epay_test_mark_order_processing( $request ) {
	$order = wc_get_order( (int) $request['id'] );
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'epay_test_order_not_found', 'Order not found.', array( 'status' => 404 ) );
	}
	$order->set_status( 'processing' );
	$order->save();
	return array( 'order' => epay_test_describe_order( wc_get_order( $order->get_id() ) ) );
}

function epay_test_delete_order_permanently( $request ) {
	$order = wc_get_order( (int) $request['id'] );
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'epay_test_order_not_found', 'Order not found.', array( 'status' => 404 ) );
	}
	$order->delete( true );
	return array( 'deleted' => ! wc_get_order( (int) $request['id'] ) );
}

function epay_test_set_legacy_plugin_state( $request ) {
	$basename = 'woo-payment-gateway-for-piraeus-bank/wooshop-piraeus.php';
	$active   = array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( $basename ) ) );
	if ( rest_sanitize_boolean( $request->get_param( 'active' ) ) ) {
		$active[] = $basename;
	}
	update_option( 'active_plugins', array_values( array_unique( $active ) ) );
	return array( 'active' => in_array( $basename, $active, true ) );
}

function epay_test_gateway_icon_state( $request ) {
	$action = sanitize_key( (string) $request->get_param( 'action' ) );
	if ( 'clear' === $action ) {
		delete_option( 'epay_test_epay_icon_override' );
	} elseif ( 'set' === $action ) {
		update_option( 'epay_test_epay_icon_override', (string) $request->get_param( 'url' ), false );
	} else {
		return new WP_Error( 'epay_test_unknown_icon_action', 'Unknown icon fixture action.', array( 'status' => 400 ) );
	}

	$gateways = WC()->payment_gateways()->payment_gateways();
	$gateway  = $gateways['epay_paycenter'] ?? null;
	ob_start();
	if ( $gateway instanceof Epay_Paycenter_Gateway ) {
		$gateway->payment_fields();
	}
	$html = (string) ob_get_clean();
	preg_match( '/<img\\b[^>]*>/', $html, $matches );
	return array(
		'icon_html' => $matches[0] ?? '',
	);
}

function epay_test_credential_notice_state( $request ) {
	if ( 'clear' === sanitize_key( (string) $request->get_param( 'action' ) ) ) {
		Epay_Paycenter_Credential_Notice::clear();
	}

	$previous_user  = get_current_user_id();
	$administrators = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	if ( ! empty( $administrators ) ) {
		wp_set_current_user( (int) $administrators[0] );
	}
	ob_start();
	Epay_Paycenter_Credential_Notice::render();
	$notice = wp_strip_all_tags( (string) ob_get_clean() );
	wp_set_current_user( $previous_user );

	return array(
		'active' => is_array( get_option( Epay_Paycenter_Credential_Notice::OPTION ) ),
		'notice' => $notice,
	);
}

function epay_test_seed_legacy_open_ticket( $request ) {
	$order = wc_get_order( (int) $request['id'] );
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'epay_test_order_not_found', 'Order not found.', array( 'status' => 404 ) );
	}

	$format    = sanitize_key( (string) $request->get_param( 'format' ) );
	$reference = sanitize_text_field( (string) $request->get_param( 'reference' ) );
	$ticket    = sanitize_text_field( (string) $request->get_param( 'ticket' ) );
	$cancel    = sanitize_text_field( (string) $request->get_param( 'cancel' ) );
	if ( 'serialized' === $format ) {
		$order->update_meta_data(
			'_epay_open_tickets',
			array(
				$reference => array(
					'ticket' => $ticket,
					'cancel' => $cancel,
				),
			)
		);
	} elseif ( 'single' === $format ) {
		$order->update_meta_data( '_epay_merchant_reference', $reference );
		$order->update_meta_data( '_epay_tran_ticket', $ticket );
		$order->update_meta_data( '_epay_cancel_token', $cancel );
	} else {
		return new WP_Error( 'epay_test_unknown_ticket_format', 'Unknown legacy ticket format.', array( 'status' => 400 ) );
	}
	$order->save();

	return array( 'order' => epay_test_describe_order( wc_get_order( $order->get_id() ) ) );
}

function epay_test_legacy_callback_compatibility_state() {
	$normalized_hook = 'woocommerce_api_wc_piraeusbank_gateway';
	$mixed_case_hook = 'woocommerce_api_WC_Piraeusbank_Gateway';
	$previous_user   = get_current_user_id();
	$administrators  = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);

	if ( ! empty( $administrators ) ) {
		wp_set_current_user( (int) $administrators[0] );
	}
	ob_start();
	Epay_Paycenter_Legacy_Callback_Compat::admin_notice();
	$notice = wp_strip_all_tags( (string) ob_get_clean() );
	wp_set_current_user( $previous_user );

	return array(
		'normalized_hook_registered' => false !== has_action(
			$normalized_hook,
			array( 'Epay_Paycenter_Legacy_Callback_Compat', 'dispatch' )
		),
		'mixed_case_hook_registered' => false !== has_action(
			$mixed_case_hook,
			array( 'Epay_Paycenter_Legacy_Callback_Compat', 'dispatch' )
		),
		'admin_notice'               => $notice,
	);
}

function epay_test_set_callback_ip_policy( $request ) {
	$allowed = array_values( array_filter( array_map( 'strval', (array) $request->get_param( 'allowed' ) ) ) );
	$trusted = array_values( array_filter( array_map( 'strval', (array) $request->get_param( 'trusted' ) ) ) );
	update_option( 'epay_test_callback_allowed_ips', $allowed, false );
	update_option( 'epay_test_callback_trusted_proxy_ips', $trusted, false );
	return array(
		'allowed'     => $allowed,
		'trusted'     => $trusted,
		'remote_addr' => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '',
	);
}

function epay_test_credential_migration_state() {
	$settings    = (array) get_option( 'woocommerce_epay_paycenter_settings', array() );
	$stored      = (string) ( $settings['password'] ?? '' );
	$credentials = class_exists( 'Epay_Paycenter_Follow_Up' )
		? Epay_Paycenter_Follow_Up::credentials_from_settings()
		: array();

	return array(
		'db_version'      => (string) get_option( 'epay_paycenter_db_version', '' ),
		'stored_password' => $stored,
		'password_digest' => (string) ( $credentials['password_digest'] ?? '' ),
	);
}

function epay_test_run_credential_migration_fixture( $request ) {
	$action   = sanitize_key( (string) $request->get_param( 'action' ) );
	$settings = (array) get_option( 'woocommerce_epay_paycenter_settings', array() );
	$digest   = md5( EPAY_TEST_LOCAL_PASSWORD );

	if ( 'seed' === $action ) {
		$settings['password'] = EPAY_TEST_LOCAL_PASSWORD;
		update_option( 'woocommerce_epay_paycenter_settings', $settings, false );
		update_option( 'epay_paycenter_db_version', '2.0', false );
	} elseif ( 'seed-future-version' === $action ) {
		$settings['password'] = 'md5:' . $digest;
		update_option( 'woocommerce_epay_paycenter_settings', $settings, false );
		update_option( 'epay_paycenter_db_version', '99.0', false );
	} elseif ( 'upgrade' === $action ) {
		Epay_Paycenter_Plugin::on_activate();
	} elseif ( 'restore' === $action ) {
		$settings['password'] = 'md5:' . $digest;
		update_option( 'woocommerce_epay_paycenter_settings', $settings, false );
		update_option( 'epay_paycenter_db_version', Epay_Paycenter_Plugin::DB_VERSION, false );
	} else {
		return new WP_Error( 'epay_test_unknown_migration_action', 'Unknown migration fixture action.', array( 'status' => 400 ) );
	}

	return epay_test_credential_migration_state();
}

function epay_test_render_epay_receipt( $request ) {
	$order = wc_get_order( (int) $request['id'] );
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'epay_test_order_not_found', 'Order not found.', array( 'status' => 404 ) );
	}
	$gateways = WC()->payment_gateways()->payment_gateways();
	$gateway  = $gateways['epay_paycenter'] ?? null;
	if ( ! $gateway instanceof Epay_Paycenter_Gateway ) {
		return new WP_Error( 'epay_test_epay_unavailable', 'ePay gateway not found.', array( 'status' => 501 ) );
	}

	ob_start();
	$gateway->output_receipt_page( $order->get_id() );
	$html  = (string) ob_get_clean();
	$order = wc_get_order( $order->get_id() );

	return array(
		'has_redirect_form'  => false !== strpos( $html, 'id="epay-paycenter-form"' ),
		'merchant_reference' => $order ? (string) $order->get_meta( '_epay_merchant_reference', true ) : '',
	);
}

add_action(
	'rest_api_init',
	static function () {
		$permission = 'epay_test_test_request_allowed';
		register_rest_route( 'epay-test/v1', '/health', array( 'methods' => 'GET', 'permission_callback' => $permission, 'callback' => 'epay_test_health' ) );
		register_rest_route( 'epay-test/v1', '/locale', array( 'methods' => 'GET', 'permission_callback' => $permission, 'callback' => 'epay_test_locale' ) );
		register_rest_route( 'epay-test/v1', '/order/(?P<id>\d+)', array( 'methods' => 'GET', 'permission_callback' => $permission, 'callback' => 'epay_test_read_order' ) );
		register_rest_route( 'epay-test/v1', '/create-order', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_create_order' ) );
		register_rest_route( 'epay-test/v1', '/cancel-unpaid/(?P<id>\d+)', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_cancel_unpaid_order' ) );
		register_rest_route( 'epay-test/v1', '/fake-epay-result', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_set_fake_result' ) );
		register_rest_route( 'epay-test/v1', '/fake-epay-barrier', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_set_fake_barrier' ) );
		register_rest_route( 'epay-test/v1', '/fake-waf-result', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_set_fake_waf_result' ) );
		register_rest_route( 'epay-test/v1', '/fake-follow-up', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_set_fake_follow_up' ) );
		register_rest_route( 'epay-test/v1', '/follow-up/reset', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_reset_follow_up' ) );
		register_rest_route( 'epay-test/v1', '/follow-up/test-channel/(?P<id>\d+)', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_test_follow_up_channel' ) );
		register_rest_route( 'epay-test/v1', '/follow-up/enable', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_enable_follow_up' ) );
		register_rest_route( 'epay-test/v1', '/follow-up/fail-next-payment-complete', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_fail_next_payment_complete' ) );
		register_rest_route( 'epay-test/v1', '/follow-up/run/(?P<id>\d+)', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_run_follow_up' ) );
		register_rest_route( 'epay-test/v1', '/follow-up/persist-paid-sibling/(?P<id>\d+)', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_persist_paid_sibling' ) );
		register_rest_route( 'epay-test/v1', '/follow-up/worker', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_run_follow_up_worker' ) );
		register_rest_route( 'epay-test/v1', '/follow-up/prioritise/(?P<id>\d+)', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_prioritise_follow_up' ) );
		register_rest_route( 'epay-test/v1', '/follow-up/seed-closed-09/(?P<id>\d+)', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_seed_closed_09' ) );
		register_rest_route( 'epay-test/v1', '/follow-up/age-attempt/(?P<id>\d+)', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_age_attempt' ) );
		register_rest_route( 'epay-test/v1', '/follow-up/drop-stock-event/(?P<id>\d+)', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_drop_stock_event' ) );
		register_rest_route( 'epay-test/v1', '/trash-order/(?P<id>\d+)', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_trash_order' ) );
		register_rest_route( 'epay-test/v1', '/refund-order/(?P<id>\d+)', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_refund_order' ) );
		register_rest_route( 'epay-test/v1', '/mark-local-paid/(?P<id>\d+)', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_mark_order_locally_paid' ) );
		register_rest_route( 'epay-test/v1', '/mark-processing/(?P<id>\d+)', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_mark_order_processing' ) );
		register_rest_route( 'epay-test/v1', '/delete-order/(?P<id>\d+)', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_delete_order_permanently' ) );
		register_rest_route( 'epay-test/v1', '/legacy-plugin', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_set_legacy_plugin_state' ) );
		register_rest_route( 'epay-test/v1', '/gateway-icon', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_gateway_icon_state' ) );
		register_rest_route( 'epay-test/v1', '/credential-notice', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_credential_notice_state' ) );
		register_rest_route( 'epay-test/v1', '/legacy-open-ticket/(?P<id>\d+)', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_seed_legacy_open_ticket' ) );
		register_rest_route( 'epay-test/v1', '/legacy-callback-compatibility', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_legacy_callback_compatibility_state' ) );
		register_rest_route( 'epay-test/v1', '/callback-ip-policy', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_set_callback_ip_policy' ) );
		register_rest_route( 'epay-test/v1', '/credential-migration', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_run_credential_migration_fixture' ) );
		register_rest_route( 'epay-test/v1', '/render-epay-receipt/(?P<id>\d+)', array( 'methods' => 'POST', 'permission_callback' => $permission, 'callback' => 'epay_test_render_epay_receipt' ) );
	}
);
