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
	 * @return array {
	 *     @type bool   $success      Whether the call succeeded and ResultCode is 0.
	 *     @type string $tran_ticket  TranTicket if success.
	 *     @type string $result_code  Result code (string, as in manual).
	 *     @type string $description  ResultDescription from the API.
	 *     @type int    $minutes      MinutesToExpiration if success.
	 *     @type string $error        Technical error message when success=false.
	 * }
	 */
	public function issue_ticket( array $request ) {
		$filtered = array();
		foreach ( self::$supported_fields as $field ) {
			if ( isset( $request[ $field ] ) && '' !== $request[ $field ] ) {
				$filtered[ $field ] = $request[ $field ];
			}
		}

		$envelope = $this->build_envelope( $filtered );

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

		if ( is_wp_error( $response ) ) {
			Epay_Paycenter_Logger::error( 'Ticketing HTTP error', array( 'error' => $response->get_error_message() ) );
			return array(
				'success'     => false,
				'result_code' => '',
				'description' => '',
				'error'       => $response->get_error_message(),
			);
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $http_code ) {
			Epay_Paycenter_Logger::error(
				'Ticketing unexpected HTTP status',
				array( 'status' => $http_code )
			);
			return array(
				'success'     => false,
				'result_code' => (string) $http_code,
				'description' => 'Unexpected HTTP status ' . $http_code,
				'error'       => 'HTTP ' . $http_code,
			);
		}

		return $this->parse_response( $body );
	}

	/**
	 * Build the SOAP 1.1 envelope. All user input is passed through
	 * htmlspecialchars() with UTF-8 to neutralise XML control characters.
	 *
	 * @param array $fields Filtered fields.
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
	 * @param mixed $value Value.
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
	 * @return array
	 */
	private function parse_response( $body ) {
		$prev_internal = libxml_use_internal_errors( true );

		// XXE defence: this plugin requires PHP 7.4 or later. Since libxml
		// 2.9.0 (which predates PHP 7.4) external entities are disabled by
		// default, and PHP 8.0 deprecated `libxml_disable_entity_loader()`
		// entirely - it is a no-op on every supported runtime. We rely on:
		//
		//   * LIBXML_NONET     - blocks any network-based entity / DTD load.
		//   * The default libxml policy of NOT substituting external entities
		//     (LIBXML_NOENT is deliberately NOT passed; setting it would
		//     enable entity substitution, the exact opposite of what we
		//     want for hardening against XXE).
		//   * The post-parse loop below, which rejects any document that
		//     declares a DTD even when the parser accepted it.
		//
		// This combination satisfies the workspace XXE-prevention rule
		// (DTDs disabled, external entities not resolved, network entity
		// resolution blocked) without invoking the removed/deprecated
		// `libxml_disable_entity_loader()` function flagged by the
		// WordPress Plugin Review Team.
		$dom                     = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		$options                 = LIBXML_NONET;
		$loaded                  = $dom->loadXML( $body, $options );

		// Post-parse XXE / DTD defence: reject any document declaring a DTD.
		if ( $loaded ) {
			foreach ( $dom->childNodes as $child ) {
				if ( XML_DOCUMENT_TYPE_NODE === $child->nodeType ) {
					$loaded = false;
					break;
				}
			}
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $prev_internal );

		if ( ! $loaded ) {
			return array(
				'success'     => false,
				'result_code' => '',
				'description' => 'Invalid SOAP response',
				'error'       => 'Could not parse SOAP response',
			);
		}

		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'soap', 'http://schemas.xmlsoap.org/soap/envelope/' );
		$xpath->registerNamespace( 'pb', self::NAMESPACE_URI );

		$fault = $xpath->query( '//soap:Fault/faultstring' );
		if ( $fault && $fault->length > 0 ) {
			$message = trim( $fault->item( 0 )->textContent );
			return array(
				'success'     => false,
				'result_code' => '',
				'description' => $message,
				'error'       => $message,
			);
		}

		$result = $this->node_text( $xpath, '//pb:IssueNewTicketResult/pb:ResultCode' );
		if ( '' === $result ) {
			$result = $this->node_text( $xpath, "//*[local-name()='IssueNewTicketResult']/*[local-name()='ResultCode']" );
		}
		$description = $this->node_text( $xpath, '//pb:IssueNewTicketResult/pb:ResultDescription' );
		if ( '' === $description ) {
			$description = $this->node_text( $xpath, "//*[local-name()='IssueNewTicketResult']/*[local-name()='ResultDescription']" );
		}
		$ticket = $this->node_text( $xpath, '//pb:IssueNewTicketResult/pb:TranTicket' );
		if ( '' === $ticket ) {
			$ticket = $this->node_text( $xpath, "//*[local-name()='IssueNewTicketResult']/*[local-name()='TranTicket']" );
		}
		$minutes = $this->node_text( $xpath, '//pb:IssueNewTicketResult/pb:MinutesToExpiration' );
		if ( '' === $minutes ) {
			$minutes = $this->node_text( $xpath, "//*[local-name()='IssueNewTicketResult']/*[local-name()='MinutesToExpiration']" );
		}

		$success = ( '0' === $result && '' !== $ticket );

		return array(
			'success'     => $success,
			'tran_ticket' => $success ? $ticket : '',
			'result_code' => $result,
			'description' => $description,
			'minutes'     => (int) $minutes,
			'error'       => $success ? '' : $description,
		);
	}

	/**
	 * Get the text contents of the first node matching an XPath expression.
	 *
	 * @param DOMXPath $xpath   XPath helper.
	 * @param string   $query  XPath query.
	 * @return string
	 */
	private function node_text( DOMXPath $xpath, $query ) {
		$nodes = $xpath->query( $query );
		if ( ! $nodes || 0 === $nodes->length ) {
			return '';
		}
		return trim( (string) $nodes->item( 0 )->textContent );
	}
}
