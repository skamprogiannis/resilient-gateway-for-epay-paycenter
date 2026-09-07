<?php
/**
 * Create synthetic WooCommerce test data through WP-CLI.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! epay_test_fixture_is_local() ) {
  throw new RuntimeException( 'The qualification setup requires the local WP-CLI test container.' );
}

update_option( 'woocommerce_epay_paycenter_settings', array(
  'enabled' => 'yes',
  'mode' => 'test',
  'title' => 'Card or IRIS',
  'description' => 'Pay securely through ePay.',
  'language_code' => 'en-US',
  'acquirer_id' => '14',
  'merchant_id' => '140000001',
  'pos_id' => '99999999',
  'username' => 'epay-test-local-test',
  'password' => 'md5:' . md5( EPAY_TEST_LOCAL_PASSWORD ),
  'debug' => 'yes',
  'follow_up_enabled' => 'no',
) );
update_option( 'woocommerce_currency', 'EUR' );
update_option( 'woocommerce_default_country', 'GR' );
update_option( 'woocommerce_hold_stock_minutes', '60' );
update_option( 'woocommerce_manage_stock', 'yes' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
update_option( 'woocommerce_checkout_order_received_endpoint', 'order-received' );
update_option( 'woocommerce_checkout_pay_endpoint', 'order-pay' );
WC_Install::create_pages();
foreach ( array( 'cart' => '[woocommerce_cart]', 'checkout' => '[woocommerce_checkout]' ) as $page => $shortcode ) {
  wp_update_post( array( 'ID' => wc_get_page_id( $page ), 'post_content' => $shortcode ) );
}

$product_id = (int) get_option( 'epay_test_product_id', 0 );
$product = $product_id ? wc_get_product( $product_id ) : false;
if ( ! $product instanceof WC_Product ) {
  $product = new WC_Product_Simple();
  $product->set_name( 'Qualification product' );
  $product->set_status( 'publish' );
  $product->set_regular_price( '20.00' );
  $product->set_manage_stock( false );
  $product->set_stock_status( 'instock' );
  $product_id = $product->save();
  update_option( 'epay_test_product_id', $product_id );
}
update_option( 'blog_public', 0 );
update_option( 'epay_test_fake_epay_result_code', '0' );
update_option( 'epay_test_fake_epay_barrier_target', 0 );
delete_option( 'epay_paycenter_follow_up_verification' );
delete_option( 'epay_paycenter_follow_up_active' );
