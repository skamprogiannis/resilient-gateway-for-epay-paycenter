<?php
/**
 * Merchant credential-outage notice.
 *
 * @package EpayPaycenter
 *
 * Added by the fork contributors on 2026-09-06; modified on 2026-09-07.
 * See NOTICE.md for attribution.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records, clears, and renders a store-wide credential outage.
 */
final class Epay_Paycenter_Credential_Notice {

	/** Stable option holding the active credential alert. */
	const OPTION = 'epay_paycenter_credentials_alert';

	/**
	 * Record a Ticketing Web Service authentication rejection.
	 *
	 * The first failure time is retained across a run of failures while the
	 * last-seen time and bank description are refreshed.
	 *
	 * @param string $description ResultDescription returned by the bank.
	 * @return void
	 */
	public static function flag( $description ) {
		$existing = get_option( self::OPTION );
		$since    = ( is_array( $existing ) && ! empty( $existing['since'] ) && is_string( $existing['since'] ) )
			? (string) $existing['since']
			: current_time( 'mysql', true );

		update_option(
			self::OPTION,
			array(
				'since'       => $since,
				'last_seen'   => current_time( 'mysql', true ),
				'description' => substr( (string) $description, 0, 200 ),
			),
			false
		);
	}

	/**
	 * Clear the alert after a successful authenticated ticket issuance.
	 *
	 * @return void
	 */
	public static function clear() {
		if ( false !== get_option( self::OPTION ) ) {
			delete_option( self::OPTION );
		}
	}

	/**
	 * Render the non-dismissible outage notice for WooCommerce administrators.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$alert = get_option( self::OPTION );
		if ( ! is_array( $alert ) || empty( $alert['since'] ) || ! is_string( $alert['since'] ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . EPAY_PAYCENTER_GATEWAY_ID );

		echo '<div class="notice notice-error">';
		echo '<p><strong>' . esc_html__( 'ePay Paycenter: payments are failing', 'resilient-gateway-for-epay-paycenter' ) . '</strong></p>';
		echo '<p>' . esc_html__(
			'The bank rejected this store\'s Ticketing Web Service credentials (ResultCode 100, Authentication Error). No payment can be started while this lasts: every customer choosing this payment method will see a failure. Check the Username and Password on the gateway settings screen against the values issued by Euronet Merchant Services, and remember that the environment (Test / Live) has its own separate credentials.',
			'resilient-gateway-for-epay-paycenter'
		) . '</p>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: date and time the failures began, in UTC. 2: description returned by the bank. */
				__( 'First seen: %1$s UTC. Bank response: %2$s', 'resilient-gateway-for-epay-paycenter' ),
				(string) $alert['since'],
				isset( $alert['description'] ) && is_string( $alert['description'] ) && '' !== $alert['description'] ? $alert['description'] : '-'
			)
		) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $settings_url ) . '">'
			. esc_html__( 'Check the gateway credentials', 'resilient-gateway-for-epay-paycenter' )
			. '</a></p>';
		echo '<p><em>' . esc_html__( 'This notice disappears on its own as soon as a payment is successfully started, so there is nothing to dismiss once the credentials are corrected.', 'resilient-gateway-for-epay-paycenter' ) . '</em></p>';
		echo '</div>';
	}
}
