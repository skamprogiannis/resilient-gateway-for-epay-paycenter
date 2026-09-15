<?php
/**
 * Optional handoff observations. Browser reports are never payment evidence.
 *
 * @package EpayPaycenter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Issues scoped reporting permission and writes bounded WooCommerce diagnostics.
 *
 * @phpstan-type Trace array{order_id:int,reference:string,trace_id:string,expires_at:int}
 * @phpstan-type BrowserContext array{order_id:int,reference:string,trace_id:string,expires_at:int,authorization:string,ajax_url:string}
 */
final class Epay_Paycenter_Diagnostics {

	const ACTION      = 'epay_paycenter_diagnostic';
	const LIFETIME    = 3600;
	const MAX_PAYLOAD = 8192;
	const MAX_REPORTS = 20;

	/** Register the same reporting interface for guests and signed-in shoppers. */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'receive' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'receive' ) );
	}

	/**
	 * Called only after the receipt has issued and stored its payment attempt.
	 *
	 * @param int    $order_id Order id.
	 * @param string $reference Issued merchant reference.
	 * @return BrowserContext|null
	 */
	public static function browser_context( int $order_id, string $reference ): ?array {
		if ( ! Epay_Paycenter_Logger::debug_enabled() ) {
			return null;
		}
		try {
			$trace = array(
				'order_id'   => $order_id,
				'reference'  => $reference,
				'trace_id'   => wp_generate_uuid4(),
				'expires_at' => time() + self::LIFETIME,
			);
			$json  = wp_json_encode( $trace );
			if ( false === $json ) {
				return null;
			}
			$encoded = base64_encode( $json ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Signed, non-secret diagnostic claims.
			return $trace + array(
				'authorization' => $encoded . '.' . self::signature( $encoded ),
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
			);
		} catch ( Throwable $error ) {
			// Diagnostics must not prevent rendering a valid payment form.
			Epay_Paycenter_Logger::error( 'Could not prepare ePay handoff diagnostics.' );
			return null;
		}
	}

	/**
	 * Record a server observation without implying that the browser received it.
	 *
	 * @param string                        $event Observation name.
	 * @param int                           $order_id Order id, when known.
	 * @param string                        $reference Exact reference, when known.
	 * @param array<string,int|string|bool> $details Bounded, server-selected fields.
	 */
	public static function record( string $event, int $order_id, string $reference = '', array $details = array() ): void {
		Epay_Paycenter_Logger::debug(
			'ePay handoff diagnostic',
			array(
				'origin'    => 'server',
				'event'     => $event,
				'order_id'  => $order_id,
				'reference' => $reference,
			) + $details
		);
	}

	/**
	 * Add the actual scheduling failure without changing its severity or handling.
	 *
	 * @param string   $message Existing log message.
	 * @param WP_Error $error WordPress scheduling error.
	 * @param string   $hook Scheduled hook.
	 * @param int      $timestamp Requested Unix timestamp.
	 * @param int      $order_id Order id, when applicable.
	 */
	public static function scheduling_error( string $message, WP_Error $error, string $hook, int $timestamp, int $order_id = 0 ): void {
		Epay_Paycenter_Logger::error(
			$message,
			array(
				'error_code'    => (string) $error->get_error_code(),
				'error_message' => self::clean_text( $error->get_error_message() ),
				'hook'          => $hook,
				'scheduled_at'  => gmdate( 'Y-m-d H:i:s', $timestamp ),
				'order_id'      => $order_id,
			)
		);
	}

	/** Accept diagnostic-only authorization; never read or write payment state. */
	public static function receive(): void {
		if ( ! Epay_Paycenter_Logger::debug_enabled() ) {
			wp_send_json_error( null, 404 );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict literal comparison; the method is never logged.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_send_json_error( null, 405 );
		}
		// This guest interface uses the scoped signature below, not a shared guest nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validate size and every decoded field before logging.
		$raw = isset( $_POST['payload'] ) && is_string( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
		if ( strlen( $raw ) > self::MAX_PAYLOAD ) {
			wp_send_json_error( null, 413 );
		}
		$payload = json_decode( $raw, true, 4 );
		if ( ! is_array( $payload ) || array_diff( array_keys( $payload ), array( 'authorization', 'event', 'sequence', 'elapsed_ms', 'details' ) ) ) {
			wp_send_json_error( null, 400 );
		}
		$trace = isset( $payload['authorization'] ) && is_string( $payload['authorization'] ) ? self::verify( $payload['authorization'] ) : null;
		if ( null === $trace ) {
			wp_send_json_error( null, 403 );
		}
		$events = array( 'script_started', 'form_found', 'form_missing', 'submission_attempted', 'submission_exception', 'javascript_error', 'unhandled_rejection', 'still_visible_after_submit', 'page_hidden', 'page_restored' );
		if ( ! isset( $payload['event'], $payload['sequence'], $payload['elapsed_ms'] )
			|| ! in_array( $payload['event'], $events, true )
			|| ! is_int( $payload['sequence'] ) || $payload['sequence'] < 1 || $payload['sequence'] > self::MAX_REPORTS
			|| ! is_int( $payload['elapsed_ms'] ) || $payload['elapsed_ms'] < 0 || $payload['elapsed_ms'] > self::LIFETIME * 1000 ) {
			wp_send_json_error( null, 400 );
		}
		$details = self::details( $payload['details'] ?? array() );
		if ( null === $details ) {
			wp_send_json_error( null, 400 );
		}
		if ( ! self::reserve_report( $trace, $payload['sequence'] ) ) {
			wp_send_json_error( null, 429 );
		}
		Epay_Paycenter_Logger::debug(
			'ePay handoff diagnostic',
			array(
				'origin'     => 'browser',
				'event'      => $payload['event'],
				'order_id'   => $trace['order_id'],
				'reference'  => $trace['reference'],
				'trace_id'   => $trace['trace_id'],
				'sequence'   => $payload['sequence'],
				'elapsed_ms' => $payload['elapsed_ms'],
				'details'    => $details,
			)
		);
		wp_send_json_success( null, 202 );
	}

	/**
	 * Authenticate immutable, expiring claims minted by this site.
	 *
	 * @param string $authorization Diagnostic-only permission.
	 * @return Trace|null
	 */
	private static function verify( string $authorization ): ?array {
		$parts = explode( '.', $authorization );
		if ( strlen( $authorization ) > 1024 || 2 !== count( $parts ) || ! hash_equals( self::signature( $parts[0] ), $parts[1] ) ) {
			return null;
		}
		$decoded = base64_decode( $parts[0], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Already authenticated claims.
		$trace   = false === $decoded ? null : json_decode( $decoded, true, 3 );
		if ( ! is_array( $trace ) || 4 !== count( $trace )
			|| ! isset( $trace['order_id'], $trace['reference'], $trace['trace_id'], $trace['expires_at'] )
			|| ! is_int( $trace['order_id'] ) || $trace['order_id'] < 1
			|| ! is_string( $trace['reference'] ) || strlen( $trace['reference'] ) > 50
			|| ! is_string( $trace['trace_id'] ) || ! wp_is_uuid( $trace['trace_id'], 4 )
			|| ! is_int( $trace['expires_at'] ) || $trace['expires_at'] <= time() || $trace['expires_at'] > time() + self::LIFETIME ) {
			return null;
		}
		return $trace;
	}

	/**
	 * Domain separation keeps this authorization unrelated to payment authentication.
	 *
	 * @param string $encoded Serialized claims.
	 */
	private static function signature( string $encoded ): string {
		return hash_hmac( 'sha256', 'epay-diagnostics-v1|' . $encoded, wp_salt( 'auth' ) );
	}

	/**
	 * Best-effort transient throttle, bounded by signed traces and their expiry.
	 *
	 * @param array $trace Authenticated trace.
	 * @param int   $sequence Per-document event sequence.
	 * @phpstan-param Trace $trace
	 */
	private static function reserve_report( array $trace, int $sequence ): bool {
		$key  = 'epay_diag_' . $trace['trace_id'];
		$seen = get_transient( $key );
		$seen = is_array( $seen ) ? $seen : array();
		if ( count( $seen ) >= self::MAX_REPORTS || isset( $seen[ $sequence ] ) ) {
			return false;
		}
		$seen[ $sequence ] = true;
		return set_transient( $key, $seen, max( 1, $trace['expires_at'] - time() ) );
	}

	/**
	 * Validate only the browser fields useful for handoff diagnosis.
	 *
	 * @param mixed $input Untrusted JSON field.
	 * @return array<string,string|int|bool>|null
	 */
	private static function details( $input ): ?array {
		if ( ! is_array( $input ) || count( $input ) > 9 ) {
			return null;
		}
		$clean = array();
		foreach ( $input as $key => $value ) {
			if ( in_array( $key, array( 'browser', 'error_name', 'error_message' ), true ) && is_string( $value ) ) {
				$clean[ $key ] = self::clean_text( $value );
			} elseif ( 'script_path' === $key && is_string( $value ) ) {
				$clean[ $key ] = self::clean_text( (string) wp_parse_url( $value, PHP_URL_PATH ) );
			} elseif ( in_array( $key, array( 'line', 'column' ), true ) && is_int( $value ) && $value >= 0 && $value <= 10000000 ) {
				$clean[ $key ] = $value;
			} elseif ( 'visibility' === $key && in_array( $value, array( 'visible', 'hidden' ), true ) ) {
				$clean[ $key ] = $value;
			} elseif ( 'persisted' === $key && is_bool( $value ) ) {
				$clean[ $key ] = $value;
			} else {
				return null;
			}
		}
		return $clean;
	}

	/**
	 * Strip URL query strings and credential-like text before ordinary log redaction.
	 *
	 * @param string $text Bounded diagnostic description.
	 */
	private static function clean_text( string $text ): string {
		$text = mb_substr( sanitize_text_field( $text ), 0, 1000, 'UTF-8' );
		$text = preg_replace( '~[a-z0-9+/]{40,}={0,2}\.[a-f0-9]{64}\b~i', '[diagnostic authorization omitted]', $text ) ?? '';
		$text = preg_replace( '~[?#][^\s<>"\']*~', '[query omitted]', $text ) ?? '';
		$text = preg_replace( '~(https?://)[^\s/@]+@~i', '$1[credentials omitted]@', $text ) ?? '';
		$text = preg_replace( '/\b(?:Bearer|Basic)\s+[^\s,;"\']+/i', '[authorization-omitted]', $text ) ?? '';
		$text = preg_replace( '/\b(?:authorization|password|passwd|username|secret|token|tranticket|ticket|hashkey|cookie|key)["\']?\s*[=:]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;"\']+)/i', '[credential omitted]', $text ) ?? '';
		$text = preg_replace( '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', '[email omitted]', $text ) ?? '';
		return mb_substr( $text, 0, 400, 'UTF-8' );
	}
}
