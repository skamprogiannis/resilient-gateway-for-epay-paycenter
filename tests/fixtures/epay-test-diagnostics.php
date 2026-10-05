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
	register_rest_route( 'epay-test/v1', '/diagnostics/schedule', array(
		'methods' => 'POST',
		'permission_callback' => 'epay_test_test_request_allowed',
		'callback' => 'epay_test_schedule_worker',
	) );
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

/** Exercise real WordPress cron writes with a deterministic competing writer. */
function epay_test_schedule_worker( $request ) {
	global $wpdb;
	$scenario = $request->get_param( 'scenario' );
	if ( ! in_array( $scenario, array( 'normal', 'existing', 'race', 'duplicate', 'missing', 'wrong_args', 'recurring', 'malformed', 'read_failure', 'write_failure', 'veto' ), true ) ) {
		return new WP_Error( 'invalid_scenario', 'Unknown scheduler scenario.', array( 'status' => 400 ) );
	}
	$hook = Epay_Paycenter_Reconciliation::CRON_HOOK;
	$original_cron = get_option( 'cron' );
	wp_clear_scheduled_hook( $hook );
	if ( 'existing' === $scenario ) {
		wp_schedule_single_event( time() + 300, $hook );
	}
	$errors = array();
	$injected = false;
	$capture = static function ( $message, $level, $context ) use ( &$errors ) {
		// WooCommerce can run this filter for more than one log handler.
		if ( 'error' === $level && 'epay-paycenter' === ( $context['source'] ?? '' ) && false !== strpos( $message, 'Could not schedule the ePay reconciliation worker.' ) && ! in_array( $message, $errors, true ) ) {
			$errors[] = $message;
		}
		return $message;
	};
	// Leave the request's autoloaded option cache stale after the competing write.
	$compete = static function ( $value ) use ( $wpdb, &$injected ) {
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $value ) ), array( 'option_name' => 'cron' ) );
		$injected = true;
		return $value;
	};
	$intercept = static function ( $value, $event ) use ( $wpdb, $hook, $scenario ) {
		if ( $hook !== $event->hook || in_array( $scenario, array( 'normal', 'existing', 'race', 'write_failure' ), true ) ) {
			return $value;
		}
		$cron = get_option( 'cron' );
		$args = 'wrong_args' === $scenario ? array( 123 ) : array();
		if ( 'missing' !== $scenario ) {
			$cron[ $event->timestamp ][ $hook ][ md5( serialize( $args ) ) ] = array(
				'schedule' => 'recurring' === $scenario ? 'hourly' : false,
				'args' => $args,
			);
		}
		if ( 'malformed' === $scenario ) {
			$cron = 'invalid cron data';
		}
		if ( 'duplicate' === $scenario ) {
			// A winner visible to core's duplicate check, after wp_next_scheduled().
			update_option( 'cron', $cron );
			return $value;
		}
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $cron ) ), array( 'option_name' => 'cron' ) );
		return new WP_Error( 'veto' === $scenario ? 'epay_test_schedule_denied' : 'could_not_set', 'Synthetic scheduler failure.' );
	};
	$fail_query = static function ( $query ) use ( $wpdb, $scenario, &$injected ) {
		$is_cron = false !== strpos( $query, "'cron'" ) && false !== strpos( $query, $wpdb->options );
		if ( $is_cron && ( ( 'write_failure' === $scenario && $injected && 0 === strpos( $query, 'UPDATE' ) )
			|| ( 'read_failure' === $scenario && 0 === strpos( $query, 'SELECT' ) ) ) ) {
			return 'SELECT * FROM epay_test_nonexistent_scheduler_table';
		}
		return $query;
	};
	$previous_suppress = $wpdb->suppress_errors( true );
	add_filter( 'woocommerce_logger_log_message', $capture, 20, 3 );
	add_filter( 'pre_schedule_event', $intercept, 20, 2 );
	if ( in_array( $scenario, array( 'race', 'write_failure' ), true ) ) {
		add_filter( 'pre_update_option_cron', $compete );
	}
	add_filter( 'query', $fail_query );
	try {
		$scheduled = Epay_Paycenter_Reconciliation::schedule();
		$cached = (bool) wp_next_scheduled( $hook );
	} finally {
		remove_filter( 'query', $fail_query );
		remove_filter( 'pre_update_option_cron', $compete );
		remove_filter( 'pre_schedule_event', $intercept, 20 );
		remove_filter( 'woocommerce_logger_log_message', $capture, 20 );
		$wpdb->suppress_errors( $previous_suppress );
	}
	// Confirm the persisted state through a fresh core read, then restore the fixture's cron list.
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'cron', 'options' );
	$persisted = (bool) wp_next_scheduled( $hook );
	update_option( 'cron', $original_cron );
	Epay_Paycenter_Reconciliation::schedule();
	return compact( 'scheduled', 'cached', 'persisted', 'injected', 'errors' );
}
