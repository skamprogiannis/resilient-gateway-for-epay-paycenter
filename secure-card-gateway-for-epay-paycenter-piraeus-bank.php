<?php
/**
 * Plugin Name:       WebHosting4U Secure Card Gateway for ePay Paycenter (Piraeus Bank)
 * Description:       WooCommerce gateway for ePay Paycenter (Piraeus Bank/Euronet). SOAP ticketing, HMAC-SHA256 callback verification, HPOS and Blocks support.
 * Version:           1.0.37
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Tested up to:      7.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   9.5
 * Author:            WebHosting4U
 * Author URI:        https://webhosting4u.gr/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       secure-card-gateway-for-epay-paycenter-piraeus-bank
 * Domain Path:       /languages
 *
 * Trademark notice:
 * "ePay", "Paycenter", and the Piraeus Bank payment mark bundled at
 * assets/img/wp-cards.png are trademarks of Piraeus Bank S.A. and/or Euronet
 * Merchant Services. This plugin is independent software published by
 * WebHosting4U for merchants who have signed an acquiring contract with
 * Euronet Merchant Services / Piraeus Bank for the ePay Paycenter
 * Redirection service, and the bundled payment mark is included with the
 * rights-holder's authorization for that merchant distribution scope. The
 * plugin name uses the third-party trademarks only after the brand-neutral
 * prefix "WebHosting4U" and the unaffiliation marker "for", as required by
 * the WordPress.org Detailed Plugin Guidelines on third-party trademarks
 * (Guideline 17). No affiliation with, endorsement by, or sponsorship from
 * Piraeus Bank S.A. or Euronet Merchant Services is implied. All other
 * trademarks are property of their respective owners. "WooCommerce" is a
 * trademark of Automattic Inc.
 *
 * @package EpayPaycenter
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'EPAY_PAYCENTER_PLUGIN_FILE' ) ) {
	return;
}

define( 'EPAY_PAYCENTER_PLUGIN_FILE', __FILE__ );
define( 'EPAY_PAYCENTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPAY_PAYCENTER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EPAY_PAYCENTER_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'EPAY_PAYCENTER_VERSION', '1.0.37' );
define( 'EPAY_PAYCENTER_GATEWAY_ID', 'epay_paycenter' );

require_once EPAY_PAYCENTER_PLUGIN_DIR . 'includes/class-epay-paycenter-plugin.php';

register_activation_hook( __FILE__, array( 'Epay_Paycenter_Plugin', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'Epay_Paycenter_Plugin', 'on_deactivate' ) );

add_action( 'before_woocommerce_init', array( 'Epay_Paycenter_Plugin', 'declare_compatibility' ) );

add_action( 'plugins_loaded', array( 'Epay_Paycenter_Plugin', 'bootstrap' ), 11 );
