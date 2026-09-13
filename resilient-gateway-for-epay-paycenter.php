<?php
/**
 * Plugin Name:       Resilient Gateway for ePay Paycenter
 * Plugin URI:        https://github.com/skamprogiannis/resilient-gateway-for-epay-paycenter
 * Description:       A resilient WooCommerce gateway for the ePay Paycenter Redirection sale flow.
 * Version:           2.2.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Tested up to:      7.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   10.4.3
 * Author:            Stephanos Kamprogiannis
 * Author URI:        https://github.com/skamprogiannis
 * Update URI:        https://github.com/skamprogiannis/resilient-gateway-for-epay-paycenter
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       resilient-gateway-for-epay-paycenter
 * Domain Path:       /languages
 *
 * "ePay" and "Paycenter" are trademarks of their respective owners. This
 * independent plugin is not affiliated with or endorsed by Piraeus Bank,
 * Euronet Merchant Services, Automattic, or WooCommerce. See NOTICE.md and
 * UPSTREAM.md for copyright, provenance, and modification information.
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
define( 'EPAY_PAYCENTER_VERSION', '2.2.0' );
define( 'EPAY_PAYCENTER_GATEWAY_ID', 'epay_paycenter' );

require_once EPAY_PAYCENTER_PLUGIN_DIR . 'includes/class-epay-paycenter-plugin.php';
require_once EPAY_PAYCENTER_PLUGIN_DIR . 'includes/class-epay-paycenter-legacy-callback-compat.php';

register_activation_hook( __FILE__, array( 'Epay_Paycenter_Plugin', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'Epay_Paycenter_Plugin', 'on_deactivate' ) );

add_action( 'before_woocommerce_init', array( 'Epay_Paycenter_Plugin', 'declare_compatibility' ) );

add_action( 'plugins_loaded', array( 'Epay_Paycenter_Plugin', 'bootstrap' ), 11 );
add_action( 'plugins_loaded', array( 'Epay_Paycenter_Legacy_Callback_Compat', 'bootstrap' ), 12 );
