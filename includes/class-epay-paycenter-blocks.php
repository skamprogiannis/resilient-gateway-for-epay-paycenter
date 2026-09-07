<?php
/**
 * WooCommerce Checkout Block integration.
 *
 * Registers the Paycenter payment method with the block-based checkout so
 * that customers using the modern Woo blocks see it in the payment options
 * list. The actual payment flow is unchanged — the block calls the server
 * `process_payment()` which performs a redirect.
 *
 * @package EpayPaycenter
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
	return;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Blocks registration.
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
	 * @var array
	 */
	protected $settings;

	/**
	 * Load settings once requested.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . EPAY_PAYCENTER_GATEWAY_ID . '_settings', array() );
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
		$asset  = EPAY_PAYCENTER_PLUGIN_DIR . 'assets/js/blocks.asset.php';

		$dependencies = array( 'wp-element', 'wp-html-entities', 'wp-i18n' );
		$version      = EPAY_PAYCENTER_VERSION;
		if ( file_exists( $asset ) ) {
			$asset_data   = include $asset;
			$dependencies = isset( $asset_data['dependencies'] ) ? $asset_data['dependencies'] : $dependencies;
			$version      = isset( $asset_data['version'] ) ? $asset_data['version'] : $version;
		}

		wp_register_script(
			$handle,
			$script,
			$dependencies,
			$version,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( $handle, 'secure-card-gateway-for-epay-paycenter-piraeus-bank', EPAY_PAYCENTER_PLUGIN_DIR . 'languages' );
		}

		return array( $handle );
	}

	/**
	 * Data passed to the block (title, description, icon).
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$icon_url = apply_filters(
			'epay_paycenter_icon',
			EPAY_PAYCENTER_PLUGIN_URL . 'assets/img/wp-cards.png'
		);

		return array(
			'title'       => isset( $this->settings['title'] ) ? $this->settings['title'] : __( 'Credit / Debit Card (Piraeus Bank)', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : '',
			'supports'    => array( 'products' ),
			'icon'        => $icon_url,
		);
	}
}
