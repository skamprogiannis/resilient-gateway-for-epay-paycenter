<?php
/**
 * Compatibility routing for the legacy Papaki Piraeus Bank callback URLs.
 *
 * @package EpayPaycenter
 *
 * Modified by the fork contributors on 2026-09-06. See NOTICE.md for attribution.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets the downstream gateway receive callbacks through the URLs previously
 * registered for the Papaki WC_Piraeusbank_Gateway plugin.
 *
 * This class only aliases the WC-API route. Payment verification and order
 * updates remain entirely inside Epay_Paycenter_Handler, including the
 * MerchantReference and HashKey checks.
 */
final class Epay_Paycenter_Legacy_Callback_Compat {

	/**
	 * Gateway identifier used by the legacy Papaki plugin.
	 */
	const LEGACY_GATEWAY_ID = 'WC_Piraeusbank_Gateway';

	/**
	 * WordPress.org folder used by the legacy Papaki distribution.
	 */
	const LEGACY_PLUGIN_FOLDER = 'woo-payment-gateway-for-piraeus-bank/';

	/**
	 * Register the normalized WooCommerce hook used for either URL spelling.
	 */
	public static function bootstrap(): void {
		add_action(
			'woocommerce_api_' . strtolower( self::LEGACY_GATEWAY_ID ),
			array( __CLASS__, 'dispatch' ),
			-10
		);
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/**
	 * Forward a legacy callback to the downstream handler.
	 *
	 * When the original Papaki plugin is still active, this callback returns
	 * without consuming the request so the original plugin remains the owner
	 * of its endpoint. This compatibility route is available whenever Papaki is
	 * inactive, including stores that keep their existing bank-side URLs.
	 */
	public static function dispatch(): void {
		if ( self::legacy_plugin_is_active() ) {
			return;
		}

		self::normalize_cancel_request();
		Epay_Paycenter_Plugin::dispatch_api_callback();
	}

	/**
	 * Explain callback ownership when both gateway plugins are active.
	 */
	public static function admin_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::legacy_plugin_is_active() ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'The Papaki Piraeus Bank gateway is active and owns the WC_Piraeusbank_Gateway callback route. Deactivate Papaki before relying on this plugin\'s compatibility route, and do not switch gateways while a payment is in flight.', 'resilient-gateway-for-epay-paycenter' )
			. '</p></div>';
	}

	/**
	 * Detect an active installation of the legacy gateway.
	 *
	 * The option checks cover normal and network activation without assuming
	 * a particular main PHP filename inside the stable WordPress.org folder.
	 * The class check is a final guard for renamed/manual installations that
	 * still load the historical gateway class.
	 *
	 * @return bool
	 */
	private static function legacy_plugin_is_active() {
		$active_plugins = (array) get_option( 'active_plugins', array() );
		foreach ( $active_plugins as $plugin_basename ) {
			if ( is_string( $plugin_basename ) && 0 === strpos( $plugin_basename, self::LEGACY_PLUGIN_FOLDER ) ) {
				return true;
			}
		}

		if ( is_multisite() ) {
			$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
			foreach ( array_keys( $network_plugins ) as $plugin_basename ) {
				if ( is_string( $plugin_basename ) && 0 === strpos( $plugin_basename, self::LEGACY_PLUGIN_FOLDER ) ) {
					return true;
				}
			}
		}

		return class_exists( self::LEGACY_GATEWAY_ID, false );
	}

	/**
	 * Translate the legacy cancel marker only when the authenticated
	 * downstream ParamBackLink fields are also present.
	 *
	 * Success and failure need no translation: the new handler derives their
	 * state from the signed Paycenter response payload, not from the URL. A
	 * bare `peiraeus=cancel` is deliberately insufficient because the new
	 * cancellation flow also requires the per-attempt order id and token.
	 */
	private static function normalize_cancel_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		$legacy_action = isset( $_REQUEST['peiraeus'] ) && is_scalar( $_REQUEST['peiraeus'] )
			? sanitize_key( wp_unslash( (string) $_REQUEST['peiraeus'] ) )
			: '';

		$has_order_id = isset( $_REQUEST['order_id'] ) && is_scalar( $_REQUEST['order_id'] );
		$has_token    = isset( $_REQUEST['token'] ) && is_scalar( $_REQUEST['token'] );
		$has_action   = isset( $_REQUEST['epp_action'] ) && is_scalar( $_REQUEST['epp_action'] );

		if ( 'cancel' !== $legacy_action || $has_action || ! $has_order_id || ! $has_token ) {
			// phpcs:enable
			return;
		}

		$_GET['epp_action']     = 'cancel';
		$_REQUEST['epp_action'] = 'cancel';
		// phpcs:enable
	}
}
