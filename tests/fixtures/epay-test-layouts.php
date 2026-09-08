<?php
/**
 * Local-only setup for submitting real classic and Blocks checkout forms.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'EPAY_TEST_FIXTURES' ) || ! EPAY_TEST_FIXTURES || 'local' !== wp_get_environment_type() ) {
	return;
}

function epay_test_layout_request_allowed() {
	return function_exists( 'epay_test_fixture_is_local' )
		&& epay_test_fixture_is_local()
		&& 'epay-qualification' === ( $_SERVER['HTTP_X_EPAY_TEST'] ?? '' );
}

function epay_test_layout_block( $name, $children = '' ) {
	return '<!-- wp:woocommerce/' . $name . ' --><div class="wp-block-woocommerce-' . $name . '">'
		. $children . '</div><!-- /wp:woocommerce/' . $name . ' -->';
}

function epay_test_layout_checkout_content() {
	$fields = '';
	foreach ( array( 'express-payment', 'contact-information', 'shipping-address', 'billing-address', 'shipping-methods', 'payment', 'additional-information', 'order-note', 'terms', 'actions' ) as $field ) {
		$fields .= epay_test_layout_block( 'checkout-' . $field . '-block' );
	}
	$summary = '';
	foreach ( array( 'cart-items', 'subtotal', 'fee', 'discount', 'shipping', 'taxes' ) as $field ) {
		$summary .= epay_test_layout_block( 'checkout-order-summary-' . $field . '-block' );
	}
	return epay_test_layout_block(
		'checkout',
		epay_test_layout_block( 'checkout-fields-block', $fields )
		. epay_test_layout_block( 'checkout-totals-block', epay_test_layout_block( 'checkout-order-summary-block', $summary ) )
	);
}

function epay_test_layout_prepare( $request ) {
	$layout = $request->get_param( 'layout' );
	if ( ! in_array( $layout, array( 'classic', 'blocks', 'restore' ), true ) ) {
		return new WP_Error( 'epay_test_layout_invalid', 'Choose classic, blocks, or restore.', array( 'status' => 400 ) );
	}
	$checkout_id = wc_get_page_id( 'checkout' );
	if ( $checkout_id <= 0 ) {
		return new WP_Error( 'epay_test_checkout_missing', 'The checkout page has not been created.', array( 'status' => 409 ) );
	}
	if ( 'restore' === $layout ) {
		wp_update_post( array( 'ID' => $checkout_id, 'post_content' => '[woocommerce_checkout]' ) );
		foreach ( (array) get_option( 'epay_test_layout_original_options', array() ) as $name => $entry ) {
			if ( $entry['exists'] ) {
				update_option( $name, $entry['value'] );
			} else {
				delete_option( $name );
			}
		}
		delete_option( 'epay_test_layout_original_options' );
		delete_option( 'epay_test_layout_ticket_requests' );
		return array( 'layout' => 'classic' );
	}

	$settings = (array) get_option( 'woocommerce_epay_paycenter_settings', array() );
	$changes  = array(
		'woocommerce_enable_guest_checkout' => 'yes',
		'woocommerce_enable_signup_and_login_from_checkout' => 'no',
		'woocommerce_epay_paycenter_settings' => array_merge(
			$settings,
			array( 'enabled' => 'yes', 'title' => 'Card or IRIS', 'installments' => false === $request->get_param( 'installments' ) ? 'no' : 'yes', 'max_installments' => (string) ( $request->get_param( 'max_installments' ) ?? '3' ), 'min_amount_for_installments' => '0', 'installments_tiers' => '', 'follow_up_enabled' => 'no' )
		),
		'epay_test_fake_epay_result_code' => '0',
		'epay_test_fake_epay_barrier_target' => 0,
	);
	if ( false === get_option( 'epay_test_layout_original_options', false ) ) {
		$original = array();
		foreach ( $changes as $name => $value ) {
			$current = get_option( $name, null );
			$original[ $name ] = array( 'exists' => null !== $current, 'value' => $current );
		}
		update_option( 'epay_test_layout_original_options', $original );
	}
	foreach ( $changes as $name => $value ) {
		update_option( $name, $value );
	}
	delete_option( 'epay_test_layout_ticket_requests' );

	$product_id = (int) get_option( 'epay_test_layout_product_id', 0 );
	$product    = $product_id ? wc_get_product( $product_id ) : false;
	if ( ! $product instanceof WC_Product_Simple ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Checkout layout qualification product' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '20.00' );
		$product->set_virtual( true );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product_id = $product->save();
		update_option( 'epay_test_layout_product_id', $product_id );
	}
	wp_update_post( array( 'ID' => $checkout_id, 'post_content' => 'blocks' === $layout ? epay_test_layout_checkout_content() : '[woocommerce_checkout]' ) );
	return array(
		'layout' => $layout,
		'product_id' => $product_id,
		'checkout_url' => wc_get_checkout_url(),
		'add_to_cart_url' => add_query_arg( 'add-to-cart', $product_id, wc_get_checkout_url() ),
	);
}

function epay_test_layout_read_order( $request ) {
	$order = wc_get_order( (int) $request['id'] );
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'epay_test_order_missing', 'Order not found.', array( 'status' => 404 ) );
	}
	$requests  = (array) get_option( 'epay_test_layout_ticket_requests', array() );
	$reference = (string) $order->get_meta( '_epay_merchant_reference', true );
	return array(
		'order_id' => $order->get_id(),
		'created_via' => $order->get_created_via(),
		'payment_method' => $order->get_payment_method(),
		'installments' => (int) $order->get_meta( '_epay_installments', true ),
		'ticket_installments' => $requests[ $reference ] ?? null,
		'item_count' => $order->get_item_count(),
	);
}

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( ! epay_test_fixture_is_local() || false === get_option( 'epay_test_layout_original_options', false )
			|| 'https://paycenter.piraeusbank.gr/services/tickets/issuer.asmx' !== $url ) {
			return $preempt;
		}
		$body = (string) ( $args['body'] ?? '' );
		if ( preg_match( '/<MerchantReference>([^<]+)<\/MerchantReference>/', $body, $reference )
			&& preg_match( '/<Installments>(\d+)<\/Installments>/', $body, $installments ) ) {
			$requests = (array) get_option( 'epay_test_layout_ticket_requests', array() );
			$requests[ $reference[1] ] = (int) $installments[1];
			update_option( 'epay_test_layout_ticket_requests', $requests );
		}
		return $preempt;
	},
	5,
	3
);

add_action(
	'rest_api_init',
	static function () {
		register_rest_route( 'epay-test/v1', '/checkout-layout', array( 'methods' => 'POST', 'permission_callback' => 'epay_test_layout_request_allowed', 'callback' => 'epay_test_layout_prepare' ) );
		register_rest_route( 'epay-test/v1', '/checkout-layout/order/(?P<id>\d+)', array( 'methods' => 'GET', 'permission_callback' => 'epay_test_layout_request_allowed', 'callback' => 'epay_test_layout_read_order' ) );
	}
);
