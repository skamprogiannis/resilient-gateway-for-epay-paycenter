<?php
/**
 * Shared Paycenter credential storage rules.
 *
 * @package EpayPaycenter
 *
 * Added by the fork contributors on 2026-09-06. See NOTICE.md for attribution.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Converts the bank password between its persistent and request forms.
 */
final class Epay_Paycenter_Credentials {

	const PASSWORD_DIGEST_PREFIX = 'md5:';

	/**
	 * Return the value safe to persist in WooCommerce settings.
	 *
	 * Unprefixed input is the plaintext format written by releases through
	 * 1.0.35. Prefix detection deliberately remains permissive so an existing
	 * marked value is never hashed a second time during an upgrade.
	 *
	 * @param string $value Plaintext or an already-prefixed digest.
	 * @return string
	 */
	public static function for_storage( $value ) {
		$value = (string) $value;
		if ( '' === $value || 0 === strpos( $value, self::PASSWORD_DIGEST_PREFIX ) ) {
			return $value;
		}

		return self::PASSWORD_DIGEST_PREFIX . md5( $value );
	}

	/**
	 * Return the digest sent to Paycenter without exposing plaintext to callers.
	 *
	 * The unprefixed branch remains an intentional runtime fallback for direct
	 * upgrades and restored backups that have not run the data migration yet.
	 *
	 * @param string $stored Stored plaintext or prefixed digest.
	 * @return string
	 */
	public static function digest( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, self::PASSWORD_DIGEST_PREFIX ) ) {
			return substr( $stored, strlen( self::PASSWORD_DIGEST_PREFIX ) );
		}

		return md5( $stored );
	}
}
