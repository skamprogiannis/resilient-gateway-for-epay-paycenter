<?php
/**
 * Deterministic local replacement for the ePay ticketing HTTP boundary.
 */

defined( 'ABSPATH' ) || exit;

const EPAY_TEST_TICKETING_ENDPOINT = 'https://paycenter.piraeusbank.gr/services/tickets/issuer.asmx';
const EPAY_TEST_FOLLOW_UP_ENDPOINT = 'https://paycenter.piraeusbank.gr/services/paymentgateway.asmx';
const EPAY_TEST_LOCAL_PASSWORD     = 'local-fake-password';

if ( ! defined( 'EPAY_TEST_FAKE_HTTP' ) ) {
	define( 'EPAY_TEST_FAKE_HTTP', true );
}

function epay_test_fake_epay_ticket( $merchant_reference ) {
	return 'TST' . substr( hash( 'sha256', (string) $merchant_reference ), 0, 29 );
}

function epay_test_fake_epay_request_fields( $body ) {
	$previous = libxml_use_internal_errors( true );
	$document = new DOMDocument();
	$loaded   = $document->loadXML( (string) $body, LIBXML_NONET );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );

	if ( ! $loaded || $document->doctype ) {
		return array();
	}

	$xpath  = new DOMXPath( $document );
	$fields = array();
	foreach ( array( 'MerchantReference', 'Password', 'RequestType', 'RequestMethod', 'ExpirePreauth', 'AcquirerID', 'MerchantID', 'PosID', 'ChannelType', 'User' ) as $field ) {
		$nodes            = $xpath->query( sprintf( "//*[local-name()='%s']", $field ) );
		$fields[ $field ] = $nodes && $nodes->length > 0
			? sanitize_text_field( (string) $nodes->item( 0 )->textContent )
			: '';
	}

	return $fields;
}

function epay_test_fake_epay_xml( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
}

function epay_test_fake_follow_up_response( array $fields, $scenario ) {
	$result_code        = '0';
	$result_description = 'TST fake follow-up result';
	$status_flag        = 'Success';
	$response_code      = '00';
	$response_desc      = 'Approved';
	$reference          = (string) $fields['MerchantReference'];
	$payment_method     = 'IRIS';
	$transaction_id     = '987654321';
	$transaction_at     = '2020-01-02T12:34:56';

	if ( 'paid_unknown' === $scenario ) {
		$payment_method = '';
	} elseif ( 'not_found' === $scenario ) {
		$result_code        = '1010';
		$result_description = 'Wrong original transaction';
		$status_flag        = '';
		$response_code      = '';
		$response_desc      = '';
	} elseif ( 'pending' === $scenario ) {
		$status_flag   = 'Pending';
		$response_code = '09';
		$response_desc = 'Transaction pending';
	} elseif ( 'declined' === $scenario ) {
		$status_flag    = 'Failure';
		$response_code  = '05';
		$response_desc  = 'Declined by issuer';
		$payment_method = 'Card';
	} elseif ( in_array( $scenario, array( 'failure_09_iris', 'failure_09_unknown', 'failure_09_card' ), true ) ) {
		$status_flag    = 'Failure';
		$response_code  = '09';
		$response_desc  = 'Transaction response 09';
		$payment_method = 'failure_09_card' === $scenario ? 'Card' : ( 'failure_09_iris' === $scenario ? 'IRIS' : '' );
	} elseif ( 'identity_mismatch' === $scenario ) {
		$reference .= '-WRONG';
	} elseif ( 'incomplete_paid' === $scenario ) {
		$transaction_id = '';
	}

	$transaction_info = '';
	if ( '0' === $result_code ) {
		$transaction_info = sprintf(
			'<Body xmlns="http://piraeusbank.gr/paycenter/1.0"><TransactionInfo>'
			. '<StatusFlag>%1$s</StatusFlag><ResponseCode>%2$s</ResponseCode>'
			. '<ResponseDescription>%3$s</ResponseDescription>'
			. '<TransactionID>%6$s</TransactionID>'
			. '<TransactionDateTime>%7$s</TransactionDateTime>'
			. '<TransactionTraceNum>4321</TransactionTraceNum>'
			. '<MerchantReference>%4$s</MerchantReference>'
			. '<ApprovalCode>TST123</ApprovalCode><RetrievalRef>TSTREF123456</RetrievalRef>'
			. '<PackageNo>1</PackageNo><SessionKey></SessionKey>'
			. '<PaymentMethod>%5$s</PaymentMethod>'
			. '<IRISTransactionID>%8$s</IRISTransactionID>'
			. '<IRISStatus>%9$s</IRISStatus>'
			. '</TransactionInfo></Body>',
			epay_test_fake_epay_xml( $status_flag ),
			epay_test_fake_epay_xml( $response_code ),
			epay_test_fake_epay_xml( $response_desc ),
			epay_test_fake_epay_xml( $reference ),
			epay_test_fake_epay_xml( $payment_method ),
			epay_test_fake_epay_xml( $transaction_id ),
			epay_test_fake_epay_xml( $transaction_at ),
			'Success' === $status_flag ? 'TST-IRIS-987654321' : '',
			'Success' === $status_flag ? 'Authorised' : ''
		);
	}

	$body = sprintf(
		'<?xml version="1.0" encoding="utf-8"?>'
		. '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
		. '<soap:Body><ProcessTransactionResponse xmlns="http://piraeusbank.gr/paycenter">'
		. '<TransactionResponse><Header xmlns="http://piraeusbank.gr/paycenter/1.0">'
		. '<RequestType>FOLLOW_UP</RequestType><MerchantInfo>'
		. '<MerchantID>%1$s</MerchantID><PosID>%2$s</PosID><ChannelType>%3$s</ChannelType><User>%4$s</User>'
		. '</MerchantInfo><ResultCode>%5$s</ResultCode><ResultDescription>%6$s</ResultDescription>'
		. '<SupportReferenceID>7654321</SupportReferenceID></Header>%7$s'
		. '</TransactionResponse></ProcessTransactionResponse></soap:Body></soap:Envelope>',
		epay_test_fake_epay_xml( $fields['MerchantID'] ),
		epay_test_fake_epay_xml( $fields['PosID'] ),
		epay_test_fake_epay_xml( $fields['ChannelType'] ),
		epay_test_fake_epay_xml( $fields['User'] ),
		epay_test_fake_epay_xml( $result_code ),
		epay_test_fake_epay_xml( $result_description ),
		$transaction_info
	);

	return array(
		'headers'  => array( 'content-type' => 'text/xml; charset=utf-8' ),
		'body'     => $body,
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'cookies'  => array(),
		'filename' => null,
	);
}

function epay_test_fake_follow_up_request( array $fields ) {
	$requests   = (array) get_option( 'epay_test_fake_follow_up_requests', array() );
	$requests[] = array(
		'reference'   => (string) ( $fields['MerchantReference'] ?? '' ),
		'channel'     => (string) ( $fields['ChannelType'] ?? '' ),
		'acquirer_id' => (string) ( $fields['AcquirerID'] ?? '' ),
		'request_type' => (string) ( $fields['RequestType'] ?? '' ),
	);
	update_option( 'epay_test_fake_follow_up_requests', array_slice( $requests, -50 ), false );
}

function epay_test_fake_epay_wait_for_barrier() {
	$target = (int) get_option( 'epay_test_fake_epay_barrier_target', 0 );
	if ( $target < 2 ) {
		return null;
	}

	global $wpdb;
	$name    = 'epay_test_fake_epay_barrier_count';
	$updated = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
			 VALUES (%s, '1', 'no')
			 ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
			$name
		)
	);
	if ( false === $updated ) {
		return new WP_Error( 'epay_test_epay_barrier_failed', 'The fake ePay barrier could not advance.' );
	}

	$deadline = microtime( true ) + 10;
	do {
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name )
		);
		if ( $count >= $target ) {
			if ( 'retained' === sanitize_key( (string) ( $_SERVER['HTTP_X_EPAY_TEST_BARRIER_ROLE'] ?? '' ) ) ) {
				usleep( 500000 );
			}
			return null;
		}
		usleep( 10000 );
	} while ( microtime( true ) < $deadline );

	return new WP_Error( 'epay_test_epay_barrier_timeout', 'The fake ePay barrier timed out.' );
}

function epay_test_fake_epay_response( $merchant_reference, $result_code ) {
	if ( 'MALFORMED' === $result_code ) {
		return array(
			'headers'  => array( 'content-type' => 'text/xml; charset=utf-8' ),
			'body'     => '<soap:Envelope><broken>',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}
	if ( 'OVERSIZED' === $result_code ) {
		return array(
			'headers'  => array( 'content-type' => 'text/xml; charset=utf-8' ),
			'body'     => str_repeat( 'X', 262145 ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}
	$success     = '0' === (string) $result_code;
	$ticket      = $success ? epay_test_fake_epay_ticket( $merchant_reference ) : '';
	$description = $success ? 'TST fake ticket issued' : 'TST fake ticket failure';
	$namespace   = 'http://piraeusbank.gr/paycenter/redirection';
	$body        = sprintf(
		'<?xml version="1.0" encoding="utf-8"?>'
		. '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
		. '<soap:Body><IssueNewTicketResponse xmlns="%1$s"><IssueNewTicketResult>'
		. '<ResultCode>%2$s</ResultCode><ResultDescription>%3$s</ResultDescription>'
		. '<TranTicket>%4$s</TranTicket><MinutesToExpiration>15</MinutesToExpiration>'
		. '</IssueNewTicketResult></IssueNewTicketResponse></soap:Body></soap:Envelope>',
		esc_url( $namespace ),
		esc_html( $result_code ),
		esc_html( $description ),
		esc_html( $ticket )
	);

	return array(
		'headers'  => array( 'content-type' => 'text/xml; charset=utf-8' ),
		'body'     => $body,
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		$callback_url = add_query_arg( 'wc-api', 'epay_paycenter', home_url( '/' ) );
		if ( $url !== $callback_url || '1' !== ( $args['headers']['X-Epay-Paycenter-Self-Test'] ?? '' ) ) {
			return $preempt;
		}
		if ( ! function_exists( 'epay_test_fixture_is_local' ) || ! epay_test_fixture_is_local() ) {
			return new WP_Error( 'epay_test_waf_fixture_outside_local', 'The callback diagnostic fixture refused to run outside localhost.' );
		}

		$scenario = get_option( 'epay_test_fake_waf_scenario', 'passthrough' );
		if ( 'passthrough' === $scenario ) {
			return $preempt;
		}

		$headers = array(
			'content-type'             => 'text/html; charset=UTF-8',
			'x-epay-paycenter-handler' => '1',
			'location'                => wc_get_checkout_url(),
		);
		if ( 'missing_marker' === $scenario ) {
			unset( $headers['x-epay-paycenter-handler'] );
		} elseif ( 'wrong_location' === $scenario ) {
			$headers['location'] = home_url( '/unrelated-page/' );
		}

		return array(
			'headers'  => $headers,
			'body'     => 'html_200' === $scenario ? '<html><body>Unrelated landing page</body></html>' : '',
			'response' => array(
				'code'    => 'html_200' === $scenario ? 200 : 302,
				'message' => 'html_200' === $scenario ? 'OK' : 'Found',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( 'paycenter.piraeusbank.gr' !== strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
			return $preempt;
		}
		if ( ! function_exists( 'epay_test_fixture_is_local' ) || ! epay_test_fixture_is_local() ) {
			return new WP_Error( 'epay_test_epay_fixture_outside_local', 'The ePay fixture refused to run outside localhost.' );
		}
		if ( EPAY_TEST_FOLLOW_UP_ENDPOINT === $url ) {
			$fields = epay_test_fake_epay_request_fields( $args['body'] ?? '' );
			epay_test_fake_follow_up_request( $fields );

			if ( 'FOLLOW_UP' !== ( $fields['RequestType'] ?? '' )
				|| 'SYNCHRONOUS' !== ( $fields['RequestMethod'] ?? '' )
				|| 'GR014' !== ( $fields['AcquirerID'] ?? '' )
				|| '' === ( $fields['MerchantReference'] ?? '' ) ) {
				return new WP_Error( 'epay_test_invalid_follow_up_request', 'The fake received an invalid follow-up request.' );
			}
			if ( ! hash_equals( md5( EPAY_TEST_LOCAL_PASSWORD ), (string) ( $fields['Password'] ?? '' ) ) ) {
				return new WP_Error( 'epay_test_invalid_follow_up_password', 'The fake follow-up request used the wrong password digest.' );
			}

			if ( 'transport_error' === get_option( 'epay_test_fake_follow_up_scenario', 'paid' ) ) {
				return new WP_Error( 'epay_test_fake_follow_up_transport', 'TST fake transport failure.' );
			}

			$accepted_channel = (string) get_option( 'epay_test_fake_follow_up_channel', 'eCommerce' );
			$scenario         = (string) get_option( 'epay_test_fake_follow_up_scenario', 'paid' );
			if ( $accepted_channel !== (string) ( $fields['ChannelType'] ?? '' ) ) {
				$scenario = 'not_found';
			}

			return epay_test_fake_follow_up_response( $fields, $scenario );
		}

		if ( EPAY_TEST_TICKETING_ENDPOINT !== $url ) {
			return new WP_Error( 'epay_test_real_paycenter_blocked', 'Real Paycenter traffic is blocked in the local clone.' );
		}

		$fields = epay_test_fake_epay_request_fields( $args['body'] ?? '' );
		if ( '' === ( $fields['MerchantReference'] ?? '' ) ) {
			return new WP_Error( 'epay_test_invalid_epay_request', 'MerchantReference was missing from the fake ticket request.' );
		}
		if ( ! hash_equals( md5( EPAY_TEST_LOCAL_PASSWORD ), (string) ( $fields['Password'] ?? '' ) ) ) {
			return new WP_Error( 'epay_test_invalid_epay_password', 'The fake ticket request used the wrong password digest.' );
		}
		if ( '02' !== ( $fields['RequestType'] ?? '' ) || '0' !== ( $fields['ExpirePreauth'] ?? '' ) ) {
			return new WP_Error( 'epay_test_invalid_epay_sale_request', 'The fake ticket request was not an immediate sale.' );
		}

		$barrier_error = epay_test_fake_epay_wait_for_barrier();
		if ( is_wp_error( $barrier_error ) ) {
			return $barrier_error;
		}

		$result_code = (string) get_option( 'epay_test_fake_epay_result_code', '0' );
		if ( 'THROW' === $result_code ) {
			throw new RuntimeException( 'TST fake ticketing transport exception with secret test value.' );
		}
		return epay_test_fake_epay_response(
			$fields['MerchantReference'],
			$result_code
		);
	},
	10,
	3
);
