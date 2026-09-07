<?php
/**
 * Local-only safety controls for the checkout qualification clone.
 */

defined( 'ABSPATH' ) || exit;

function epay_test_fixture_is_local() {
	if ( ! defined( 'EPAY_TEST_FIXTURES' ) || ! EPAY_TEST_FIXTURES || 'local' !== wp_get_environment_type() ) {
		return false;
	}

	$host = strtolower(
		(string) wp_parse_url(
			'http://' . (string) ( $_SERVER['HTTP_HOST'] ?? '' ),
			PHP_URL_HOST
		)
	);

	return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) || 'cli' === PHP_SAPI;
}

if ( ! epay_test_fixture_is_local() ) {
	return;
}

add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_option_blog_public', static fn() => '0' );

add_action(
	'admin_notices',
	static function () {
		echo '<div class="notice notice-warning"><p><strong>Local qualification clone:</strong> email and real Paycenter traffic are disabled.</p></div>';
	}
);
