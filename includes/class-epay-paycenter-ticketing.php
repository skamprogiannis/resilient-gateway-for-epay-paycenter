<?php
/**
 * Paycenter Ticketing SOAP Web Service client.
 *
 * Implements Section 4 of the Redirection v2.9 specification. Builds a SOAP
 * 1.1 envelope manually and posts it over HTTPS using the WordPress HTTP API
 * ({@see wp_remote_post()}) so we avoid external entity loading risks that
 * come with parsing untrusted XML through PHP's native SoapClient.
 *
 * The endpoint URL has no user-provided component (it is a fixed constant
 * from the official manual), so SSRF rules do not apply.
 *
 * @package EpayPaycenter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ticketing Web Service client.
 *
 * @phpstan-type TicketRequest array{Username:string, Password:string, MerchantId:string, PosId:string, AcquirerId:string, MerchantReference:string, RequestType:string, ExpirePreauth:string, Amount:string, Installments:string, CurrencyCode:string, Bnpl:string, Parameters:string, BillAddrCity?:string, BillAddrCountry?:string, BillAddrLine1?:string, BillAddrLine2?:string, BillAddrLine3?:string, BillAddrPostCode?:string, BillAddrState?:string, ShipAddrCity?:string, ShipAddrCountry?:string, ShipAddrLine1?:string, ShipAddrLine2?:string, ShipAddrLine3?:string, ShipAddrPostCode?:string, ShipAddrState?:string, CardholderName?:string, Email?:string, HomePhone?:string, MobilePhone?:string, WorkPhone?:string}
 * @phpstan-type TicketResult array{success:bool, tran_ticket:string, result_code:string, description:string, minutes:int, error:string}
 */
class Epay_Paycenter_Ticketing {

	/**
	 * Production Ticketing Web Service endpoint.
	 * Value sourced from the official manual (Section 4).
	 */
	const ENDPOINT = 'https://paycenter.piraeusbank.gr/services/tickets/issuer.asmx';

	/**
	 * SOAP action namespace for IssueNewTicket.
	 */
	const NAMESPACE_URI = 'http://piraeusbank.gr/paycenter/redirection';

	/**
	 * SOAP action header value expected by ASMX services.
	 */
	const SOAP_ACTION = 'http://piraeusbank.gr/paycenter/redirection/IssueNewTicket';

	/** Maximum SOAP response accepted from the external service. */
	const MAX_RESPONSE_BYTES = 262144;

	/**
	 * Parameter keys that are transmitted through the SOAP request.
	 *
	 * The order mirrors the manual so the generated XML is readable and easy
	 * to diff against the documentation.
	 *
	 * @var string[]
	 */
	public static $supported_fields = array(
		'Username',
		'Password',
		'MerchantId',
		'PosId',
		'AcquirerId',
		'MerchantReference',
		'RequestType',
		'ExpirePreauth',
		'Amount',
		'Installments',
		'CurrencyCode',
		'Bnpl',
		'Parameters',
		'BillAddrCity',
		'BillAddrCountry',
		'BillAddrLine1',
		'BillAddrLine2',
		'BillAddrLine3',
		'BillAddrPostCode',
		'BillAddrState',
		'ShipAddrCity',
		'ShipAddrCountry',
		'ShipAddrLine1',
		'ShipAddrLine2',
		'ShipAddrLine3',
		'ShipAddrPostCode',
		'ShipAddrState',
		'CardholderName',
		'Email',
		'HomePhone',
		'MobilePhone',
		'WorkPhone',
	);

	/**
	 * Issue a new ticket for a transaction.
	 *
	 * The Password value MUST be MD5-hashed by the caller prior to invocation,
	 * as required by the manual.
	 *
	 * @param array $request Associative array of request parameters.
	 * @phpstan-param TicketRequest $request
	 * @return TicketResult
	 */
	public function issue_ticket( array $request ) {
		$filtered = array();
		foreach ( self::$supported_fields as $field ) {
			if ( isset( $request[ $field ] ) && '' !== $request[ $field ] ) {
				$filtered[ $field ] = $request[ $field ];
			}
		}

		$envelope = $this->build_envelope( $filtered );

		try {
			$response = wp_remote_post(
				self::ENDPOINT,
				array(
					'timeout'     => 30,
					'redirection' => 0,
					'httpversion' => '1.1',
					'sslverify'   => true,
					'headers'     => array(
						'Content-Type' => 'text/xml; charset=utf-8',
						'SOAPAction'   => '"' . self::SOAP_ACTION . '"',
						'Accept'       => 'text/xml',
					),
					'body'        => $envelope,
				)
			);
		} catch ( Throwable $error ) {
			Epay_Paycenter_Logger::error( 'Ticketing transport raised an unexpected error.' );
			return $this->error_result( 'Ticketing service request failed' );
		}

		if ( is_wp_error( $response ) ) {
			Epay_Paycenter_Logger::error( 'Ticketing HTTP request failed.' );
			return $this->error_result( 'Ticketing service request failed' );
		}

		try {
			$http_code = (int) wp_remote_retrieve_response_code( $response );
			$body      = (string) wp_remote_retrieve_body( $response );
		} catch ( Throwable $error ) {
			Epay_Paycenter_Logger::error( 'Ticketing response could not be read.' );
			return $this->error_result( 'Invalid ticketing service response' );
		}

		if ( 200 !== $http_code ) {
			Epay_Paycenter_Logger::error(
				'Ticketing unexpected HTTP status',
				array( 'status' => $http_code )
			);
			return $this->error_result( 'Ticketing service returned an unexpected HTTP status', (string) $http_code );
		}
		if ( '' === $body || strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			Epay_Paycenter_Logger::error( 'Ticketing response was empty or exceeded the size limit.' );
			return $this->error_result( 'Invalid SOAP response' );
		}

		return $this->parse_response( $body );
	}

	/**
	 * Build the SOAP 1.1 envelope. All user input is passed through
	 * htmlspecialchars() with UTF-8 to neutralise XML control characters.
	 *
	 * @param array<string,string> $fields Filtered fields.
	 * @return string
	 */
	private function build_envelope( array $fields ) {
		$ns = self::NAMESPACE_URI;

		$xml  = '<?xml version="1.0" encoding="utf-8"?>';
		$xml .= '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
				. 'xmlns:xsd="http://www.w3.org/2001/XMLSchema" '
				. 'xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">';
		$xml .= '<soap:Body>';
		$xml .= '<IssueNewTicket xmlns="' . $this->xml_attr( $ns ) . '">';
		$xml .= '<Request>';
		foreach ( $fields as $name => $value ) {
			$xml .= '<' . $name . '>' . $this->xml_text( $value ) . '</' . $name . '>';
		}
		$xml .= '</Request>';
		$xml .= '</IssueNewTicket>';
		$xml .= '</soap:Body>';
		$xml .= '</soap:Envelope>';

		return $xml;
	}

	/**
	 * Escape a string for use as element text content.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function xml_text( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * Escape a string for use as an attribute value.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function xml_attr( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * Parse a SOAP response body into the normalised result array.
	 *
	 * Parsing is done with DOMDocument configured explicitly to reject DTDs
	 * and external entities, protecting against XXE attacks on a
	 * potentially compromised / spoofed response.
	 *
	 * @param string $body Response body.
	 * @return TicketResult
	 */
	private function parse_response( $body ) {
		$prev_internal = libxml_use_internal_errors( true );

		// Disable network/entity expansion and reject DTD-bearing responses.
		$dom = new DOMDocument();
		try {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM API property.
			$dom->preserveWhiteSpace = false;
			$loaded                  = $dom->loadXML( $body, LIBXML_NONET );

			// Reject every document that declares a DTD, even if libxml parsed it.
			if ( $loaded ) {
				$loaded = null === $dom->doctype;
			}
		} catch ( Throwable $error ) {
			$loaded = false;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $prev_internal );
		}

		if ( ! $loaded ) {
			return $this->error_result( 'Could not parse SOAP response' );
		}

		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'soap', 'http://schemas.xmlsoap.org/soap/envelope/' );
		$xpath->registerNamespace( 'pb', self::NAMESPACE_URI );

		$fault = $xpath->query( '//soap:Fault/faultstring' );
		if ( $fault && $fault->length > 0 ) {
			Epay_Paycenter_Logger::error( 'Ticketing service returned a SOAP fault.' );
			return $this->error_result( 'Ticketing service returned a SOAP fault' );
		}

		$result_nodes = $xpath->query( "//*[local-name()='IssueNewTicketResult']" );
		if ( ! $result_nodes || 1 !== $result_nodes->length ) {
			return $this->error_result( 'Invalid SOAP response structure' );
		}
		$result_node = $result_nodes->item( 0 );
		if ( ! $result_node instanceof DOMElement ) {
			return $this->error_result( 'Invalid SOAP response structure' );
		}
		$result      = $this->child_text( $xpath, $result_node, 'ResultCode' );
		$description = $this->child_text( $xpath, $result_node, 'ResultDescription' );
		$ticket      = $this->child_text( $xpath, $result_node, 'TranTicket' );
		$minutes     = $this->child_text( $xpath, $result_node, 'MinutesToExpiration' );
		if ( ! ctype_digit( $result ) || strlen( $ticket ) > 128 || ( '' !== $minutes && ! ctype_digit( $minutes ) ) ) {
			return $this->error_result( 'Invalid SOAP response fields' );
		}

		$success          = ( '0' === $result && '' !== $ticket );
		$safe_description = substr( sanitize_text_field( $description ), 0, 255 );

		return array(
			'success'     => $success,
			'tran_ticket' => $success ? $ticket : '',
			'result_code' => $result,
			'description' => $safe_description,
			'minutes'     => (int) $minutes,
			'error'       => $success ? '' : $safe_description,
		);
	}

	/**
	 * Read one direct child of the unique response result element.
	 *
	 * @param DOMXPath $xpath XPath instance.
	 * @param DOMNode  $element Result element.
	 * @param string   $name Child element name.
	 * @return string
	 */
	private function child_text( DOMXPath $xpath, DOMNode $element, $name ) {
		$nodes = $xpath->query( "./*[local-name()='" . $name . "']", $element );
		if ( ! $nodes || 1 !== $nodes->length ) {
			return '';
		}
		$node = $nodes->item( 0 );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM API property.
		return $node instanceof DOMElement ? trim( $node->textContent ) : '';
	}

	/**
	 * Build a consistent failure result without exposing transport internals.
	 *
	 * @param string $message Operational error.
	 * @param string $result_code Bank result code, when available.
	 * @return TicketResult
	 */
	private function error_result( $message, $result_code = '' ) {
		$message = substr( sanitize_text_field( (string) $message ), 0, 255 );
		return array(
			'success'     => false,
			'tran_ticket' => '',
			'result_code' => (string) $result_code,
			'description' => $message,
			'minutes'     => 0,
			'error'       => $message,
		);
	}
}
