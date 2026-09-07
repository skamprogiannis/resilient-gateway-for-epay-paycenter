<?php
/**
 * ISO 4217 currency code mapping.
 *
 * Lists only currencies that ePay Paycenter supports (Annex 5 of the
 * Redirection v2.9 manual). Values are the numeric ISO 4217 codes used by
 * the Paycenter Ticketing Web Service.
 *
 * @package EpayPaycenter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Currency alpha → numeric lookup helper.
 */
class Epay_Paycenter_Currencies {

	/**
	 * Alpha 3 to numeric ISO 4217 code map.
	 *
	 * @var array<string,int>
	 */
	private static $map = array(
		'ALL' => 8,
		'ARS' => 32,
		'AUD' => 36,
		'CAD' => 124,
		'CLP' => 152,
		'CNY' => 156,
		'COP' => 170,
		'HRK' => 191,
		'CZK' => 203,
		'DKK' => 208,
		'HKD' => 344,
		'HUF' => 348,
		'INR' => 356,
		'IDR' => 360,
		'ILS' => 376,
		'JPY' => 392,
		'KZT' => 398,
		'KRW' => 410,
		'KWD' => 414,
		'LTL' => 440,
		'MOP' => 446,
		'MYR' => 458,
		'MXN' => 484,
		'MAD' => 504,
		'NZD' => 554,
		'NOK' => 578,
		'PEN' => 604,
		'PHP' => 608,
		'RUB' => 643,
		'SAR' => 682,
		'SGD' => 702,
		'ZAR' => 710,
		'SEK' => 752,
		'CHF' => 756,
		'THB' => 764,
		'AED' => 784,
		'EGP' => 818,
		'GBP' => 826,
		'USD' => 840,
		'BYN' => 933,
		'VEF' => 937,
		'RSD' => 941,
		'RON' => 946,
		'TRY' => 949,
		'BGN' => 975,
		'BAM' => 977,
		'EUR' => 978,
		'UAH' => 980,
		'PLN' => 985,
		'BRL' => 986,
	);

	/**
	 * Returns the numeric ISO 4217 currency code for an alpha 3 code,
	 * or null when the currency is not supported.
	 *
	 * @param string $alpha ISO 4217 alpha 3 code (case insensitive).
	 * @return int|null
	 */
	public static function to_numeric( $alpha ) {
		$alpha = strtoupper( (string) $alpha );
		return isset( self::$map[ $alpha ] ) ? self::$map[ $alpha ] : null;
	}

	/**
	 * Returns true when the alpha code is supported.
	 *
	 * @param string $alpha Alpha 3 currency code.
	 * @return bool
	 */
	public static function is_supported( $alpha ) {
		return null !== self::to_numeric( $alpha );
	}
}
