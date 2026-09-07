<?php
/**
 * HashKey generation and verification.
 *
 * Implements the algorithm described in Section 5 - "Hash Key Verification"
 * of the ePay Paycenter Redirection v2.9 specification.
 *
 * Fields (in order, joined by ";"):
 *   TranTicket ; PosId ; AcquirerId ; MerchantReference ; ApprovalCode ;
 *   Parameters ; ResponseCode ; SupportReferenceID ; AuthStatus ;
 *   PackageNo ; StatusFlag
 *
 * The resulting string is HMAC-SHA256 signed with TranTicket as the secret
 * key, hex-encoded, and compared in UPPERCASE.
 *
 * @package EpayPaycenter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hash Key calculation and verification helper.
 */
class Epay_Paycenter_Hash {

	/**
	 * The ordered list of fields involved in the hash.
	 *
	 * @var string[]
	 */
	public static $fields = array(
		'TranTicket',
		'PosId',
		'AcquirerId',
		'MerchantReference',
		'ApprovalCode',
		'Parameters',
		'ResponseCode',
		'SupportReferenceID',
		'AuthStatus',
		'PackageNo',
		'StatusFlag',
	);

	/**
	 * Calculate the expected HashKey for a response.
	 *
	 * @param array<string, string> $fields Associative array of response fields.
	 *                                      Missing entries are treated as empty strings.
	 * @return string 64-character uppercase hexadecimal HMAC-SHA256 digest.
	 */
	public static function calculate( array $fields ) {
		$tran_ticket = isset( $fields['TranTicket'] ) ? (string) $fields['TranTicket'] : '';

		$ordered = array();
		foreach ( self::$fields as $name ) {
			$ordered[] = isset( $fields[ $name ] ) ? (string) $fields[ $name ] : '';
		}

		$payload = implode( ';', $ordered );

		return strtoupper( hash_hmac( 'sha256', $payload, $tran_ticket ) );
	}

	/**
	 * Verify a received HashKey against expected values in timing-safe fashion.
	 *
	 * @param string                $received The HashKey returned by Paycenter.
	 * @param array<string, string> $fields   Field values used to recompute the hash locally.
	 * @return bool True when both hashes match.
	 */
	public static function verify( $received, array $fields ) {
		$received = strtoupper( $received );
		if ( '' === $received || strlen( $received ) !== 64 ) {
			return false;
		}
		$expected = self::calculate( $fields );
		return hash_equals( $expected, $received );
	}
}
