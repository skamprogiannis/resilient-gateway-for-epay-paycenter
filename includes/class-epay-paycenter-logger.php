<?php
/**
 * Logging helper. Redacts sensitive fields before writing to the WooCommerce log.
 *
 * @package EpayPaycenter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wrapper around wc_get_logger with automatic redaction of secrets.
 */
class Epay_Paycenter_Logger {

	/**
	 * Keys whose values must never be written to logs.
	 *
	 * @var string[]
	 */
	private static $sensitive_keys = array(
		'authorization',
		'cardnumber',
		'cookie',
		'cvc',
		'cvv',
		'hashkey',
		'pan',
		'password',
		'secret',
		'ticket',
		'token',
		'user',
		'username',
	);

	/**
	 * Memoised result of debug_enabled(). Null until first resolved.
	 *
	 * @var bool|null
	 */
	private static $debug_enabled = null;

	/**
	 * Log an informational line.
	 *
	 * @param string $message Message.
	 * @param mixed  $context Optional structured context.
	 * @return void
	 */
	public static function info( $message, $context = null ) {
		self::write( 'info', $message, $context );
	}

	/**
	 * Log an error line.
	 *
	 * @param string $message Message.
	 * @param mixed  $context Optional structured context.
	 * @return void
	 */
	public static function error( $message, $context = null ) {
		self::write( 'error', $message, $context );
	}

	/**
	 * Log a debug line.
	 *
	 * Written when EITHER the gateway's own "Logging" setting is enabled or
	 * WP_DEBUG is on. The setting is what a merchant actually toggles on the
	 * gateway screen, so it has to be sufficient by itself. Gating debug
	 * output on WP_DEBUG alone meant that on a normal production site (where
	 * WP_DEBUG is off) ticking the box produced an empty log, and the
	 * callback-triage lines this plugin writes at debug level - "callback
	 * received before ticket issuance", "endpoint hit without payload",
	 * "callback from IP not in allowlist" - stayed invisible at exactly the
	 * moment they were needed to tell a WAF-blocked callback apart from a
	 * plugin-side reject.
	 *
	 * Context values still pass through redact() before they are written, so
	 * enabling this never exposes the Password, TranTicket or HashKey.
	 *
	 * @param string $message Message.
	 * @param mixed  $context Optional structured context.
	 * @return void
	 */
	public static function debug( $message, $context = null ) {
		if ( ! self::debug_enabled() ) {
			return;
		}
		self::write( 'debug', $message, $context );
	}

	/**
	 * Whether debug-level logging is currently enabled.
	 *
	 * Reads the gateway settings option directly rather than going through
	 * the gateway instance: this logger is loaded first and is used on the
	 * callback path, where the gateway may not have been instantiated at all.
	 * Memoised for the request because it is consulted on every debug call.
	 *
	 * @return bool
	 */
	private static function debug_enabled() {
		if ( null !== self::$debug_enabled ) {
			return self::$debug_enabled;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			self::$debug_enabled = true;
			return true;
		}

		if ( ! function_exists( 'get_option' ) ) {
			self::$debug_enabled = false;
			return false;
		}

		$settings = get_option( 'woocommerce_' . EPAY_PAYCENTER_GATEWAY_ID . '_settings', array() );

		self::$debug_enabled = (
			is_array( $settings )
			&& isset( $settings['debug'] )
			&& 'yes' === $settings['debug']
		);

		return self::$debug_enabled;
	}

	/**
	 * Write a log line through the WooCommerce logger.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @param mixed  $context Context.
	 * @return void
	 */
	private static function write( $level, $message, $context ) {
		try {
			if ( ! function_exists( 'wc_get_logger' ) ) {
				return;
			}
			$logger = wc_get_logger();
			$line   = self::redact_string( (string) $message );
			if ( null !== $context ) {
				$encoded = wp_json_encode( self::redact( $context ) );
				$line   .= false === $encoded ? ' [context unavailable]' : ' ' . $encoded;
			}
			$logger->log( in_array( $level, array( 'debug', 'info', 'error' ), true ) ? $level : 'error', $line, array( 'source' => 'epay-paycenter' ) );
		} catch ( Throwable $error ) {
			// Payment processing must not fail because an optional logger failed.
			// No external values or exception messages are copied to this fallback.
			error_log( 'ePay Paycenter logging failed.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Recursively redact sensitive keys from a structure before logging.
	 *
	 * @param mixed $data Arbitrary input.
	 * @return mixed
	 */
	public static function redact( $data ) {
		if ( is_object( $data ) ) {
			return array( 'type' => get_class( $data ) );
		}
		if ( ! is_array( $data ) ) {
			return is_string( $data ) ? self::redact_string( $data ) : $data;
		}
		$clean = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && self::is_sensitive_key( $key ) ) {
				$clean[ $key ] = '***REDACTED***';
				continue;
			}
			$clean[ $key ] = self::redact( $value );
		}
		return $clean;
	}

	/**
	 * Match sensitive keys case-insensitively across common separators.
	 *
	 * @param string $key Context key.
	 * @return bool
	 */
	private static function is_sensitive_key( $key ) {
		$normalised = preg_replace( '/[^a-z0-9]+/i', '', $key );
		if ( null === $normalised ) {
			return true;
		}
		$normalised = strtolower( $normalised );
		foreach ( self::$sensitive_keys as $sensitive ) {
			if ( false !== strpos( $normalised, $sensitive ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Redact likely card numbers and reusable digests from free-form strings.
	 *
	 * @param string $value Free-form log value.
	 * @return string
	 */
	private static function redact_string( $value ) {
		$value = substr( sanitize_text_field( (string) $value ), 0, 2000 );
		$value = preg_replace( '/\b(?:\d[ -]*?){13,19}\b/', '***REDACTED***', $value );
		if ( null === $value ) {
			return '[redacted]';
		}
		$value = preg_replace( '/\b[a-f0-9]{32,64}\b/i', '***REDACTED***', $value );
		return is_string( $value ) ? $value : '[redacted]';
	}
}
