<?php
/**
 * Paycenter Transaction Web Service FOLLOW_UP client.
 *
 * Implements Web Service Manual v2.4, section 5. The request is sent only
 * from WordPress to the fixed Paycenter HTTPS endpoint. Responses are parsed
 * without entity expansion and are accepted only when the merchant identity,
 * channel and MerchantReference echo the values that were requested.
 *
 * @package EpayPaycenter
 *
 * Recovers transactions later resolved through DIAS without an e-shop
 * callback.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Small, side-effect-free client for one bank follow-up query.
 *
 * @phpstan-type FollowUpResult array{state:'query_error'|'pending'|'paid'|'declined', result_code:string, result_description:string, support_reference_id:string, status_flag:string, response_code:string, response_description:string, transaction_id:string, transaction_at:string, approval_code:string, payment_method:string, iris_transaction_id:string, iris_status:string, error:string}
 * @phpstan-type Credentials array{merchant_id:string, pos_id:string, user:string, password_digest:string}
 */
final class Epay_Paycenter_Follow_Up {

	const ENDPOINT          = 'https://paycenter.piraeusbank.gr/services/paymentgateway.asmx';
	const WRAPPER_NAMESPACE = 'http://piraeusbank.gr/paycenter';
	const DATA_NAMESPACE    = 'http://piraeusbank.gr/paycenter/1.0';
	const SOAP_ACTION       = 'http://piraeusbank.gr/paycenter/ProcessTransaction';
	const ACQUIRER_ID       = 'GR014';
	const MAX_RESPONSE_SIZE = 262144;
	const REQUEST_TIMEOUT   = 20;
	const PASSWORD_PREFIX   = Epay_Paycenter_Credentials::PASSWORD_DIGEST_PREFIX;

	/**
	 * Result codes that represent an approved transaction per Manual v2.4.
	 *
	 * @var string[]
	 */
	private static $approved_response_codes = array( '00', '08', '10', '16' );

	/**
	 * Normalised merchant credentials.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Bind the normalized merchant identity used to authenticate responses.
	 *
	 * @param array $credentials Merchant ID, POS ID, user and MD5 password.
	 * @phpstan-param Credentials $credentials
	 */
	public function __construct( array $credentials ) {
		$this->credentials = array(
			'merchant_id'     => trim( $credentials['merchant_id'] ),
			'pos_id'          => trim( $credentials['pos_id'] ),
			'user'            => trim( $credentials['user'] ),
			'password_digest' => strtolower( trim( $credentials['password_digest'] ) ),
		);
	}

	/**
	 * Create a client from the existing gateway option without exposing the
	 * password digest to callers.
	 *
	 * @return self
	 */
	public static function from_settings() {
		return new self( self::credentials_from_settings() );
	}

	/**
	 * Read and normalise the credential fields shared with Ticketing.
	 *
	 * Legacy plaintext values are supported exactly as the gateway supports
	 * them: they are hashed in memory and are not persisted here.
	 *
	 * @return array{merchant_id:string, pos_id:string, user:string, password_digest:string, mode:string}
	 */
	public static function credentials_from_settings() {
		$settings = (array) get_option( 'woocommerce_' . EPAY_PAYCENTER_GATEWAY_ID . '_settings', array() );
		$stored   = isset( $settings['password'] ) ? (string) $settings['password'] : '';
		$digest   = Epay_Paycenter_Credentials::digest( $stored );

		return array(
			'merchant_id'     => isset( $settings['merchant_id'] ) ? trim( (string) $settings['merchant_id'] ) : '',
			'pos_id'          => isset( $settings['pos_id'] ) ? trim( (string) $settings['pos_id'] ) : '',
			'user'            => isset( $settings['username'] ) ? trim( (string) $settings['username'] ) : '',
			'password_digest' => strtolower( trim( $digest ) ),
			'mode'            => isset( $settings['mode'] ) ? sanitize_key( (string) $settings['mode'] ) : '',
		);
	}

	/**
	 * Whether every credential required by the Transaction Web Service exists.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return preg_match( '/^\d+$/', $this->credentials['merchant_id'] )
			&& preg_match( '/^\d+$/', $this->credentials['pos_id'] )
			&& '' !== $this->credentials['user']
			&& 50 >= strlen( $this->credentials['user'] )
			&& 1 === preg_match( '/^[a-f0-9]{32}$/', $this->credentials['password_digest'] );
	}

	/**
	 * Ask Paycenter for the final state of one MerchantReference.
	 *
	 * @param string $merchant_reference Exact issued MerchantReference.
	 * @param string $channel            eCommerce or 3DSecure.
	 * @return FollowUpResult Normalised, non-sensitive result.
	 */
	public function query( $merchant_reference, $channel ) {
		$merchant_reference = trim( (string) $merchant_reference );
		$channel            = (string) $channel;

		if ( ! $this->is_configured() ) {
			return $this->error_result( 'The follow-up Web Service credentials are incomplete.' );
		}
		if ( ! in_array( $channel, array( 'eCommerce', '3DSecure' ), true ) ) {
			return $this->error_result( 'The follow-up ChannelType is not verified.' );
		}
		if ( 1 !== preg_match( '/^[\p{L}\p{N} \/:_().,+\-]{1,50}$/u', $merchant_reference ) ) {
			return $this->error_result( 'The MerchantReference is invalid.' );
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'             => self::REQUEST_TIMEOUT,
				'redirection'         => 0,
				'httpversion'         => '1.1',
				'sslverify'           => true,
				'limit_response_size' => self::MAX_RESPONSE_SIZE,
				'headers'             => array(
					'Content-Type' => 'text/xml; charset=utf-8',
					'SOAPAction'   => '"' . self::SOAP_ACTION . '"',
					'Accept'       => 'text/xml',
				),
				'body'                => $this->build_envelope( $merchant_reference, $channel ),
			)
		);

		if ( is_wp_error( $response ) ) {
			Epay_Paycenter_Logger::error(
				'Paycenter follow-up HTTP error',
				array(
					'reference' => $merchant_reference,
					'error'     => $response->get_error_message(),
				)
			);
			return $this->error_result( $response->get_error_message() );
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = (string) wp_remote_retrieve_body( $response );
		if ( 200 !== $http_code ) {
			Epay_Paycenter_Logger::error(
				'Paycenter follow-up unexpected HTTP status',
				array(
					'reference' => $merchant_reference,
					'status'    => $http_code,
				)
			);
			return $this->error_result( 'Unexpected HTTP status ' . $http_code );
		}
		if ( '' === $body || strlen( $body ) > self::MAX_RESPONSE_SIZE ) {
			Epay_Paycenter_Logger::error( 'Paycenter follow-up response was empty or too large', array( 'reference' => $merchant_reference ) );
			return $this->error_result( 'The follow-up response was empty or too large.' );
		}

		$result = $this->parse_response( $body, $merchant_reference, $channel );
		Epay_Paycenter_Logger::info(
			'Paycenter follow-up result',
			array(
				'reference'      => $merchant_reference,
				'channel'        => $channel,
				'state'          => $result['state'],
				'result_code'    => $result['result_code'],
				'status_flag'    => $result['status_flag'],
				'response_code'  => $result['response_code'],
				'transaction_id' => $result['transaction_id'],
				'transaction_at' => $result['transaction_at'],
				'payment_method' => $result['payment_method'],
				'iris_status'    => $result['iris_status'],
			)
		);
		return $result;
	}

	/**
	 * Build a FOLLOW_UP request for the exact issued reference and channel.
	 *
	 * @param string $merchant_reference MerchantReference.
	 * @param string $channel            ChannelType.
	 * @return string
	 */
	private function build_envelope( $merchant_reference, $channel ) {
		$xml  = '<?xml version="1.0" encoding="utf-8"?>';
		$xml .= '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
			. 'xmlns:xsd="http://www.w3.org/2001/XMLSchema" '
			. 'xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">';
		$xml .= '<soap:Body><ProcessTransaction xmlns="' . self::WRAPPER_NAMESPACE . '">';
		$xml .= '<TransactionRequest xmlns="' . self::DATA_NAMESPACE . '"><Header>';
		$xml .= '<RequestType>FOLLOW_UP</RequestType><RequestMethod>SYNCHRONOUS</RequestMethod><MerchantInfo>';
		$xml .= '<AcquirerID>' . self::ACQUIRER_ID . '</AcquirerID>';
		$xml .= '<MerchantID>' . $this->xml_text( $this->credentials['merchant_id'] ) . '</MerchantID>';
		$xml .= '<PosID>' . $this->xml_text( $this->credentials['pos_id'] ) . '</PosID>';
		$xml .= '<ChannelType>' . $this->xml_text( $channel ) . '</ChannelType>';
		$xml .= '<User>' . $this->xml_text( $this->credentials['user'] ) . '</User>';
		$xml .= '<Password>' . $this->xml_text( $this->credentials['password_digest'] ) . '</Password>';
		$xml .= '</MerchantInfo></Header><Body><TransactionInfo>';
		$xml .= '<MerchantReference>' . $this->xml_text( $merchant_reference ) . '</MerchantReference>';
		$xml .= '</TransactionInfo></Body></TransactionRequest></ProcessTransaction></soap:Body></soap:Envelope>';
		return $xml;
	}

	/**
	 * Accept only responses matching the requested merchant identity.
	 *
	 * @param string $body               SOAP document.
	 * @param string $merchant_reference Requested reference.
	 * @param string $channel            Requested channel.
	 * @return FollowUpResult
	 */
	private function parse_response( $body, $merchant_reference, $channel ) {
		$previous = libxml_use_internal_errors( true );
		$document = new DOMDocument();
		$loaded   = $document->loadXML( $body, LIBXML_NONET );
		if ( $loaded && $document->doctype ) {
			$loaded = false;
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return $this->error_result( 'Could not parse the follow-up SOAP response.' );
		}

		$xpath = new DOMXPath( $document );
		$fault = $this->node_text( $xpath, "//*[local-name()='Fault']/*[local-name()='faultstring']" );
		if ( '' !== $fault ) {
			return $this->error_result( $this->limited( $fault, 200 ) );
		}

		$base   = "//*[local-name()='TransactionResponse']";
		$header = $base . "/*[local-name()='Header']";
		$info   = $base . "/*[local-name()='Body']/*[local-name()='TransactionInfo']";

		$request_type  = $this->node_text( $xpath, $header . "/*[local-name()='RequestType']" );
		$result_code   = $this->node_text( $xpath, $header . "/*[local-name()='ResultCode']" );
		$result_desc   = $this->node_text( $xpath, $header . "/*[local-name()='ResultDescription']" );
		$support       = $this->node_text( $xpath, $header . "/*[local-name()='SupportReferenceID']" );
		$response_mid  = $this->node_text( $xpath, $header . "/*[local-name()='MerchantInfo']/*[local-name()='MerchantID']" );
		$response_pos  = $this->node_text( $xpath, $header . "/*[local-name()='MerchantInfo']/*[local-name()='PosID']" );
		$response_chan = $this->node_text( $xpath, $header . "/*[local-name()='MerchantInfo']/*[local-name()='ChannelType']" );
		$response_user = $this->node_text( $xpath, $header . "/*[local-name()='MerchantInfo']/*[local-name()='User']" );

		if ( 'FOLLOW_UP' !== $request_type
			|| ! $this->same_integer( $this->credentials['merchant_id'], $response_mid )
			|| ! $this->same_integer( $this->credentials['pos_id'], $response_pos )
			|| ! hash_equals( $channel, $response_chan )
			|| ! hash_equals( $this->credentials['user'], $response_user ) ) {
			return $this->error_result( 'The follow-up response identity did not match the request.' );
		}

		$result                         = $this->base_result();
		$result['result_code']          = $this->limited( $result_code, 20 );
		$result['result_description']   = $this->limited( $result_desc, 255 );
		$result['support_reference_id'] = $this->limited( $support, 64 );

		if ( '0' !== $result_code ) {
			$result['state'] = '1010' === $result_code ? 'pending' : 'query_error';
			$result['error'] = '1010' === $result_code ? '' : $this->limited( $result_desc, 200 );
			return $result;
		}

		$response_reference = $this->node_text( $xpath, $info . "/*[local-name()='MerchantReference']" );
		$status_flag        = $this->node_text( $xpath, $info . "/*[local-name()='StatusFlag']" );
		$response_code      = $this->node_text( $xpath, $info . "/*[local-name()='ResponseCode']" );
		if ( ! hash_equals( $merchant_reference, $response_reference ) ) {
			return $this->error_result( 'The follow-up MerchantReference did not match the request.' );
		}
		if ( ! in_array( $status_flag, array( 'Success', 'Failure', 'Pending' ), true ) ) {
			return $this->error_result( 'The follow-up response contained an invalid StatusFlag.' );
		}

		$result['status_flag']          = $status_flag;
		$result['response_code']        = $this->limited( $response_code, 20 );
		$result['response_description'] = $this->limited( $this->node_text( $xpath, $info . "/*[local-name()='ResponseDescription']" ), 255 );
		$result['transaction_id']       = $this->limited( $this->node_text( $xpath, $info . "/*[local-name()='TransactionID']" ), 64 );
		$result['transaction_at']       = $this->limited( $this->node_text( $xpath, $info . "/*[local-name()='TransactionDateTime']" ), 40 );
		$result['approval_code']        = $this->limited( $this->node_text( $xpath, $info . "/*[local-name()='ApprovalCode']" ), 20 );
		$result['payment_method']       = $this->limited( $this->node_text( $xpath, $info . "/*[local-name()='PaymentMethod']" ), 32 );
		$result['iris_transaction_id']  = $this->limited( $this->node_text( $xpath, $info . "/*[local-name()='IRISTransactionID']" ), 100 );
		$result['iris_status']          = $this->limited( $this->node_text( $xpath, $info . "/*[local-name()='IRISStatus']" ), 32 );

		if ( 'Success' === $status_flag ) {
			if ( ! in_array( $response_code, self::$approved_response_codes, true ) ) {
				$result['state'] = 'query_error';
				$result['error'] = 'Paycenter returned Success with a non-approved ResponseCode.';
				return $result;
			}
			if ( 1 !== preg_match( '/^\d+$/', (string) $result['transaction_id'] )
				|| 1 !== preg_match( '/^\d+$/', (string) $result['support_reference_id'] )
				|| '' === (string) $result['transaction_at']
				|| false === strtotime( (string) $result['transaction_at'] ) ) {
				$result['state'] = 'query_error';
				$result['error'] = 'Paycenter returned an incomplete approved transaction.';
				return $result;
			}
			$result['state'] = 'paid';
			return $result;
		}

		// IRIS 09 can precede the final bank result even with a Failure flag.
		// Missing method information must not turn that ambiguity into a decline.
		$card_decline    = 'card' === strtolower( $result['payment_method'] )
			&& '' === $result['iris_transaction_id'] && '' === $result['iris_status'];
		$pending_09      = '09' === $response_code && ! $card_decline;
		$result['state'] = 'Failure' === $status_flag && ! $pending_09 ? 'declined' : 'pending';
		return $result;
	}

	/**
	 * Create the complete result contract with an unconfirmed default state.
	 *
	 * @return FollowUpResult
	 */
	private function base_result() {
		return array(
			'state'                => 'query_error',
			'result_code'          => '',
			'result_description'   => '',
			'support_reference_id' => '',
			'status_flag'          => '',
			'response_code'        => '',
			'response_description' => '',
			'transaction_id'       => '',
			'transaction_at'       => '',
			'approval_code'        => '',
			'payment_method'       => '',
			'iris_transaction_id'  => '',
			'iris_status'          => '',
			'error'                => '',
		);
	}

	/**
	 * Return a bounded operational error without confirming payment.
	 *
	 * @param string $message Safe operational error.
	 * @return FollowUpResult
	 */
	private function error_result( $message ) {
		$result          = $this->base_result();
		$result['error'] = $this->limited( $message, 200 );
		return $result;
	}

	/**
	 * Read text from an expected response element.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @param string   $query XPath query.
	 * @return string
	 */
	private function node_text( DOMXPath $xpath, $query ) {
		$nodes = $xpath->query( $query );
		$node  = $nodes && $nodes->length > 0 ? $nodes->item( 0 ) : null;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM API property.
		return $node instanceof DOMElement ? trim( $node->textContent ) : '';
	}

	/**
	 * Compare positive decimal identifiers without integer-size coercion.
	 *
	 * @param string $left  Identifier.
	 * @param string $right Identifier.
	 * @return bool
	 */
	private function same_integer( $left, $right ) {
		if ( 1 !== preg_match( '/^\d+$/', $left ) || 1 !== preg_match( '/^\d+$/', $right ) ) {
			return false;
		}
		$left  = ltrim( $left, '0' );
		$right = ltrim( $right, '0' );
		return hash_equals( '' === $left ? '0' : $left, '' === $right ? '0' : $right );
	}

	/**
	 * Escape a value for XML element content.
	 *
	 * @param string $value XML text value.
	 * @return string
	 */
	private function xml_text( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * Bound sanitized bank text before exposing or persisting it.
	 *
	 * @param string $value Raw value.
	 * @param int    $limit Maximum bytes.
	 * @return string
	 */
	private function limited( $value, $limit ) {
		$value = sanitize_text_field( (string) $value );
		return substr( $value, 0, (int) $limit );
	}
}
