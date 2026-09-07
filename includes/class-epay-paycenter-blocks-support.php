<?php
/**
 * WooCommerce Checkout Block integration.
 *
 * Checkout Blocks delegates payment to the gateway's hosted redirect flow.
 *
 * @package EpayPaycenter
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Register the gateway with Checkout Blocks.
 */
class Epay_Paycenter_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * Payment method name.
	 *
	 * @var string
	 */
	protected $name = EPAY_PAYCENTER_GATEWAY_ID;

	/**
	 * Gateway settings snapshot used to populate the block.
	 *
	 * @var array{title?: string, description?: string}
	 */
	protected $settings;

	/**
	 * Load settings once requested.
	 *
	 * @return void
	 */
	public function initialize() {
		$settings       = get_option( 'woocommerce_' . EPAY_PAYCENTER_GATEWAY_ID . '_settings', array() );
		$this->settings = array();
		if ( ! is_array( $settings ) ) {
			return;
		}
		foreach ( array( 'title', 'description' ) as $key ) {
			if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
				$this->settings[ $key ] = $settings[ $key ];
			}
		}
	}

	/**
	 * Tells the blocks API whether the method is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! isset( $gateways[ EPAY_PAYCENTER_GATEWAY_ID ] ) ) {
			return false;
		}
		return $gateways[ EPAY_PAYCENTER_GATEWAY_ID ]->is_available();
	}

	/**
	 * Register and return the handles of the scripts used by the payment
	 * method in the block editor / front-end.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		$handle = 'epay-paycenter-blocks';
		$script = EPAY_PAYCENTER_PLUGIN_URL . 'assets/js/blocks.js';
		$asset  = require EPAY_PAYCENTER_PLUGIN_DIR . 'assets/js/blocks.asset.php';

		wp_register_script(
			$handle,
			$script,
			$asset['dependencies'],
			$asset['version'],
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_set_script_translations( $handle, 'resilient-gateway-for-epay-paycenter', EPAY_PAYCENTER_PLUGIN_DIR . 'languages' );

		return array( $handle );
	}

	/**
	 * Data passed to the block (title, description, icon).
	 *
	 * @return array{title: string, description: string, supports: string[], icon: string}
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => isset( $this->settings['title'] ) ? $this->settings['title'] : __( 'Credit / Debit Card (Piraeus Bank)', 'resilient-gateway-for-epay-paycenter' ),
			'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : '',
			'supports'    => array( 'products' ),
			'icon'        => $this->filtered_icon_url(),
		);
	}

	/**
	 * Resolve a filtered icon to an absolute HTTP(S) URL.
	 *
	 * @return string
	 */
	private function filtered_icon_url() {
		$icon_url = apply_filters( 'epay_paycenter_icon', '' );
		if ( ! is_string( $icon_url ) || ! wp_http_validate_url( $icon_url ) ) {
			return '';
		}

		return esc_url_raw( $icon_url );
	}
}
