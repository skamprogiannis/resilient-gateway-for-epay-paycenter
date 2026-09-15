<?php
/** Capture synthetic WooCommerce log output through its normal logging interface. */

defined( 'ABSPATH' ) || exit;

add_filter( 'woocommerce_logger_log_message', static function ( $message, $level, $context ) {
	if ( epay_test_test_request_allowed() && '1' === ( $_SERVER['HTTP_X_EPAY_TEST_LOGGER_FAILURE'] ?? '' ) && 'epay-paycenter' === ( $context['source'] ?? '' ) ) {
		throw new RuntimeException( 'Synthetic logger failure.' );
	}
	static $seen = array();
	$fingerprint = hash( 'sha256', $level . $message );
	if ( epay_test_test_request_allowed() && '1' === ( $_SERVER['HTTP_X_EPAY_TEST_DIAGNOSTICS'] ?? '' )
		&& 'epay-paycenter' === ( $context['source'] ?? '' ) && ! isset( $seen[ $fingerprint ] ) ) {
		$seen[ $fingerprint ] = true;
		add_option( 'epay_test_diagnostic_log_' . wp_generate_uuid4(), array( 'level' => $level, 'message' => $message ), '', false );
	}
	return $message;
}, 10, 3 );

add_filter( 'option_woocommerce_epay_paycenter_settings', static function ( $settings ) {
	if ( epay_test_test_request_allowed() && isset( $_SERVER['HTTP_X_EPAY_TEST_DIAGNOSTICS_DEBUG'] ) ) {
		$settings['debug'] = 'yes' === $_SERVER['HTTP_X_EPAY_TEST_DIAGNOSTICS_DEBUG'] ? 'yes' : 'no';
	}
	return $settings;
} );

add_filter( 'pre_option_cron', static function ( $value ) {
	return epay_test_recovery_scenario() === 'diagnostic-schedule-error' ? array( 'version' => 2 ) : $value;
} );
add_filter( 'pre_schedule_event', static function ( $value, $event ) {
	return epay_test_recovery_scenario() === 'diagnostic-schedule-error' && strpos( $event->hook, 'epay_paycenter_' ) === 0
		? new WP_Error( 'epay_test_schedule_failed', 'Synthetic scheduler failure https://example.test/?token=schedule-secret' ) : $value;
}, 10, 2 );

add_action( 'rest_api_init', static function () {
	register_rest_route( 'epay-test/v1', '/diagnostics/authorization', array(
		'methods' => 'POST',
		'permission_callback' => 'epay_test_test_request_allowed',
		'callback' => static function ( $request ) {
			$claims = $request->get_json_params();
			$encoded = base64_encode( wp_json_encode( $claims ) );
			return array( 'authorization' => $encoded . '.' . hash_hmac( 'sha256', 'epay-diagnostics-v1|' . $encoded, wp_salt( 'auth' ) ) );
		},
	) );
	register_rest_route( 'epay-test/v1', '/diagnostics/release-stock/(?P<id>\d+)', array(
		'methods' => 'POST',
		'permission_callback' => 'epay_test_test_request_allowed',
		'callback' => static function ( $request ) {
			Epay_Paycenter_Reconciliation::release_stock( (int) $request['id'] );
			return epay_test_describe_order( wc_get_order( (int) $request['id'] ) );
		},
	) );
	register_rest_route( 'epay-test/v1', '/diagnostics/logs', array(
		'methods' => 'GET',
		'permission_callback' => 'epay_test_test_request_allowed',
		'callback' => static function ( $request ) {
			global $wpdb;
			$reference = (string) $request->get_param( 'reference' );
			$names = $wpdb->get_col( $wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s ORDER BY option_id",
				$wpdb->esc_like( 'epay_test_diagnostic_log_' ) . '%', '%' . $wpdb->esc_like( $reference ) . '%'
			) );
			$logs = array();
			foreach ( $names as $name ) {
				$row = get_option( $name );
				if ( is_array( $row ) && ( '' === $reference || false !== strpos( $row['message'], $reference ) ) ) {
					$logs[] = $row;
				}
			}
			return $logs;
		},
	) );
} );
