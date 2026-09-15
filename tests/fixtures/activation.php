<?php
/** Exercise the registered activation action without normal plugin bootstrap. */
if ( 'cli' !== PHP_SAPI || 'local' !== wp_get_environment_type()
	|| ! defined( 'EPAY_TEST_FIXTURES' ) || ! EPAY_TEST_FIXTURES
	|| class_exists( 'Epay_Paycenter_Plugin', false ) ) {
	throw new RuntimeException( 'Skip the gateway during synthetic CLI bootstrap.' );
}

$active_before = get_option( 'active_plugins' );
$schedule_attempted = false;
$error_logged = false;
add_filter( 'pre_option_cron', static function () { return array( 'version' => 2 ); } );
add_filter( 'pre_schedule_event', static function ( $pre, $event ) use ( &$schedule_attempted ) {
	if ( 'epay_paycenter_reconcile' !== $event->hook ) {
		return $pre;
	}
	$schedule_attempted = true;
	return new WP_Error( 'synthetic_schedule_failure', 'Synthetic activation scheduler failure.' );
}, 10, 2 );
add_filter( 'woocommerce_logger_log_message', static function ( $message, $level, $context ) use ( &$error_logged ) {
	if ( 'epay-paycenter' === ( $context['source'] ?? '' ) && 'error' === $level
		&& false !== strpos( $message, 'synthetic_schedule_failure' ) ) {
		$error_logged = true;
	}
	return $message;
}, 10, 3 );

$basename = 'resilient-gateway-for-epay-paycenter/resilient-gateway-for-epay-paycenter.php';
require WP_PLUGIN_DIR . '/' . $basename;
do_action( 'activate_' . $basename, false );
echo wp_json_encode( array(
	'activation_completed' => true,
	'schedule_attempted' => $schedule_attempted,
	'error_logged' => $error_logged,
	'active_plugins_unchanged' => $active_before === get_option( 'active_plugins' ),
) ) . "\n";
