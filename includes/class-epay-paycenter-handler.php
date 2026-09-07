<?php
/**
 * Callback (success / failure / cancel) response handler.
 *
 * Processes the POST or GET response returned by Paycenter to the merchant
 * site. Verifies HashKey, updates the WooCommerce order, and redirects the
 * customer to the appropriate thank-you or checkout page.
 *
 * @package EpayPaycenter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Response handler.
 */
class Epay_Paycenter_Handler {

	/**
	 * Maximum "Callback envelope" lines written per hour.
	 *
	 * The callback URL is public and unauthenticated, so every byte logged
	 * before a request is bound to a real order is attacker-controlled and
	 * attacker-triggered. Without a ceiling, a trivial request flood appends
	 * unbounded data to wp-content/uploads/wc-logs until the volume fills -
	 * which takes down WordPress and MySQL together, not just this plugin.
	 *
	 * The envelope is the WAF-vs-plugin diagnostic (see the FAQ), so its
	 * budget is deliberately generous: a busy store settles a few hundred
	 * orders a day, well under this ceiling, while an attack is bounded.
	 *
	 * @var int
	 */
	const LOG_BUDGET_ENVELOPE = 240;

	/**
	 * Maximum anomaly lines (malformed payload, unknown order, reference
	 * mismatch) written per hour. Tighter than the envelope budget because
	 * these paths indicate probing rather than trade.
	 *
	 * @var int
	 */
	const LOG_BUDGET_ANOMALY = 20;

	/**
	 * Reference to the gateway instance so we can reuse its configuration.
	 *
	 * @var Epay_Paycenter_Gateway
	 */
	private $gateway;

	/**
	 * Constructor.
	 *
	 * @param Epay_Paycenter_Gateway $gateway Gateway.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Entry point for the WC-API endpoint. Dispatches to the correct handler
	 * based on the `epp_action` query var.
	 *
	 * This method is the registered callback for the
	 * `woocommerce_api_epay_paycenter` action, so reaching it means the URL
	 * `/wc-api/epay_paycenter/` was hit from a trusted context (the Paycenter
	 * callback or the cancel link).
	 */
	public function handle() {
		// Optional source-IP allowlist. When the filter returns a non-empty
		// array, only requests whose remote IP is in the list are processed.
		// Merchants who know their Paycenter IP ranges (available from
		// Euronet Merchant Services) should configure this filter to add a
		// first-line network guard before any application logic runs.
		//
		// Proxy / CDN awareness: if the site sits behind Cloudflare the
		// real client IP is in CF-Connecting-IP, not REMOTE_ADDR. The
		// snippet below checks CF-Connecting-IP first, then falls back to
		// REMOTE_ADDR, so the filter works correctly in both topologies.
		//
		// Example (add to your theme's functions.php or a mu-plugin):
		//   add_filter( 'epay_paycenter_allowed_callback_ips', function() {
		//       return array( '1.2.3.4', '5.6.7.8' ); // Euronet source IPs
		//   } );
		$allowed_ips = apply_filters( 'epay_paycenter_allowed_callback_ips', array() );
		if ( ! empty( $allowed_ips ) && is_array( $allowed_ips ) ) {
			$remote_ip = '';
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			// SECURITY: CF-Connecting-IP is only consulted when the request
			// actually arrived through Cloudflare. On a site that is NOT
			// proxied, that header is attacker-supplied and trusting it would
			// let anyone bypass the allowlist with a single spoofed header.
			// REMOTE_ADDR is the only value the web server itself guarantees.
			//
			// Even behind Cloudflare this is a defence-in-depth layer, not a
			// primary control: it is only meaningful if the origin also
			// refuses connections that do not come from Cloudflare's ranges.
			// All order state changes remain gated on the HashKey and the
			// per-order MerchantReference secret.
			if (
				$this->gateway->is_cloudflare_proxied()
				&& ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] )
				&& is_string( $_SERVER['HTTP_CF_CONNECTING_IP'] )
			) {
				$remote_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ) {
				$remote_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			}
			// phpcs:enable
			if ( ! in_array( $remote_ip, $allowed_ips, true ) ) {
				Epay_Paycenter_Logger::debug(
					'Callback from IP not in allowlist; ignored.',
					array( 'remote_ip' => $remote_ip )
				);
				wp_safe_redirect( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' ) );
				exit;
			}
		}

		// Accept both POST and GET per manual Section 5. Authenticity of
		// the response payload is enforced via HashKey (HMAC-SHA256) in
		// handle_response(); the cancel flow is authenticated by a
		// one-time token stored in order meta (see handle_cancel()). No
		// nonce is applicable because the request originates from the
		// Paycenter back-end, not a logged-in browser session.
		$action = 'response';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST['epp_action'] ) && is_scalar( $_REQUEST['epp_action'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
			$action = sanitize_key( wp_unslash( (string) $_REQUEST['epp_action'] ) );
		}

		if ( 'cancel' === $action ) {
			$this->handle_cancel();
			return;
		}

		$this->handle_response();
	}

	/**
	 * Handle a success or failure response from Paycenter.
	 */
	private function handle_response() {
		$params = $this->collect_response_params();

		// Log a compact "envelope" record early so that callbacks which bail
		// out later (unknown order, reference mismatch, HashKey mismatch)
		// still leave a forensic trail that can be correlated against the
		// webserver access log and the host WAF / ModSecurity audit log. The
		// envelope carries field NAMES only - never values - to keep the log
		// noise-free and to avoid inadvertently writing the HashKey or any
		// cardholder data a third-party proxy may have appended to the POST.
		//
		// Two guards bound the cost of this on a public, unauthenticated URL:
		// requests carrying no MerchantReference at all (scanners, crawlers,
		// someone opening the URL in a browser) are not worth an envelope
		// line, and the rest draw from an hourly budget. See
		// consume_log_budget().
		if ( '' !== (string) $params['MerchantReference'] && $this->consume_log_budget( 'envelope', self::LOG_BUDGET_ENVELOPE ) ) {
			$this->log_callback_envelope( $params );
		}

		if ( empty( $params['MerchantReference'] ) ) {
			// Distinguish between:
			//  (a) an empty request - bot / scanner / crawler / direct
			//      browser visit to the public WC-API callback URL. No
			//      Paycenter response fields present at all. This is noise
			//      and must not spam the WooCommerce error log.
			//  (b) a real anomaly - some Paycenter response fields present
			//      but MerchantReference missing. Indicates a transport or
			//      configuration issue on the Paycenter side and is worth
			//      surfacing at error level with a redacted snapshot.
			$has_any_payload = false;
			foreach ( $params as $field_value ) {
				if ( '' !== $field_value ) {
					$has_any_payload = true;
					break;
				}
			}

			if ( $has_any_payload ) {
				if ( $this->consume_log_budget( 'anomaly', self::LOG_BUDGET_ANOMALY ) ) {
					Epay_Paycenter_Logger::error(
						'Callback missing MerchantReference',
						array( 'params' => $params )
					);
				}
			} else {
				Epay_Paycenter_Logger::debug( 'WC-API callback endpoint hit without payload; ignored.' );
			}
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$order_id = $this->resolve_order_id_from_params( $params );
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || $order->get_payment_method() !== EPAY_PAYCENTER_GATEWAY_ID ) {
			if ( $this->consume_log_budget( 'anomaly', self::LOG_BUDGET_ANOMALY ) ) {
				Epay_Paycenter_Logger::error(
					'Callback for unknown / mismatched order',
					array( 'order_id' => $order_id )
				);
			}
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		// Verify the MerchantReference belongs to this order (defence against
		// attacker-supplied merchant references pointing to another order) and
		// resolve the per-attempt TranTicket (HMAC key) it was issued with.
		//
		// The reference is matched against the order's SET of OPEN attempts
		// rather than only the most-recent one: each pay-for-order render
		// issues a fresh reference + TranTicket, so a callback that completes
		// an EARLIER attempt (a second tab, or a Back-button re-issue) must
		// still validate against its own pair. Matching only the latest value
		// is what left a charged order stuck on "MerchantReference mismatch".
		//
		// Security note: the guard is unconditional — callbacks arriving
		// before ANY ticket issuance (empty set) are rejected outright. Real
		// Paycenter callbacks can only arrive after the customer has been
		// redirected to the payment page, which requires a successful
		// IssueNewTicket call that records at least one attempt first.
		// Accepting callbacks against an order with no issued attempt would
		// allow any unauthenticated client to set a pending order to "failed"
		// by simply guessing its sequential order ID.
		$open_tickets = Epay_Paycenter_Gateway::get_open_tickets( $order );
		if ( empty( $open_tickets ) ) {
			Epay_Paycenter_Logger::debug(
				'Callback received before ticket issuance; ignored to prevent unauthenticated status change.',
				array( 'order_id' => $order_id )
			);
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
		$received_reference = (string) $params['MerchantReference'];
		$tran_ticket        = '';
		$reference_matched  = false;
		// Scan the whole set (no early break) so lookup time does not reveal
		// which attempt matched; each comparison is timing-safe.
		foreach ( $open_tickets as $open_reference => $open_data ) {
			if ( hash_equals( (string) $open_reference, $received_reference ) ) {
				$reference_matched = true;
				$tran_ticket       = isset( $open_data['ticket'] ) ? (string) $open_data['ticket'] : '';
			}
		}
		if ( ! $reference_matched ) {
			if ( $this->consume_log_budget( 'anomaly', self::LOG_BUDGET_ANOMALY ) ) {
				Epay_Paycenter_Logger::error(
					'MerchantReference mismatch on callback',
					array( 'order_id' => $order_id )
				);
			}
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		// Idempotency: once a successful callback has been processed for
		// this order, its status and payment audit meta must never change
		// again - replayed callbacks could otherwise flip a paid order
		// into failed/on-hold after the TranTicket was cleared from order
		// meta. Two sub-cases:
		//  (a) replayed / duplicate SUCCESS callback - pure noise; log
		//      and redirect to the thank-you page.
		//  (b) non-success callback - §7 Test Case 3 "RECHARGE ATTEMPT"
		//      (ResultCode 1048): the customer re-submitted the payment
		//      form after the transaction was approved (browser Back
		//      re-runs the cached auto-submit form) and the bank rejected
		//      the reused MerchantReference. The order state is correct
		//      and stays untouched, but the spec still requires the
		//      merchant application to record the attempt and display a
		//      message on the user page - handled by
		//      record_recharge_attempt().
		if ( $order->is_paid() ) {
			if ( '0' === $params['ResultCode'] && 'Success' === $params['StatusFlag'] ) {
				Epay_Paycenter_Logger::info(
					'Duplicate callback ignored - order already paid',
					array( 'order_id' => $order_id )
				);
			} else {
				$this->record_recharge_attempt( $order, $params, $tran_ticket );
			}
			wp_safe_redirect( $this->gateway->get_return_url( $order ) );
			exit;
		}

		Epay_Paycenter_Logger::info(
			'Paycenter callback received',
			array_merge( $params, array( 'order_id' => $order_id ) )
		);

		// Persist every Paycenter response parameter that Redirection
		// Manual v2.9 §5 identifies as a merchant-side storage
		// requirement for declined transactions:
		//   - SupportReferenceID
		//   - MerchantReference (refreshed from the live callback even
		//     though we already wrote it at ticket creation, so the
		//     value echoed back by the bank is what remains on the
		//     order - audit parity with the Paycenter back-office)
		//   - ResultCode / ResultDescription
		//   - ResponseCode / ResponseDescription
		// The remaining fields (StatusFlag / TransactionId / AuthStatus)
		// are stored for operational traceability.
		$order->update_meta_data( '_epay_support_reference_id', $params['SupportReferenceID'] );
		$order->update_meta_data( '_epay_merchant_reference', $params['MerchantReference'] );
		$order->update_meta_data( '_epay_result_code', $params['ResultCode'] );
		$order->update_meta_data( '_epay_result_description', $params['ResultDescription'] );
		$order->update_meta_data( '_epay_response_code', $params['ResponseCode'] );
		$order->update_meta_data( '_epay_response_description', $params['ResponseDescription'] );
		$order->update_meta_data( '_epay_status_flag', $params['StatusFlag'] );
		$order->update_meta_data( '_epay_transaction_id', $params['TransactionId'] );
		$order->update_meta_data( '_epay_auth_status', $params['AuthStatus'] );
		// CardType / PaymentMethod identify the channel selected by the
		// customer on the bank's payment page (card vs IRIS). Per
		// Redirection Manual v2.9 §5 they are HMAC-protected fields, so
		// these stored values can be trusted for downstream branching
		// (e.g. blocking refund attempts on IRIS-paid orders in future
		// releases since Piraeus Bank returns ResponseCode 9167 for
		// refund requests on IRIS transactions).
		$order->update_meta_data( '_epay_card_type', $params['CardType'] );
		$order->update_meta_data( '_epay_payment_method', $params['PaymentMethod'] );
		// PanCardType (Redirection Manual v3.1 §5) is populated ONLY for
		// Google Pay transactions: FPAN = the real card number, DPAN = a
		// device token. Its presence is what distinguishes a Google Pay
		// payment from a manually keyed card - PaymentMethod is "Card" for
		// both. Stored for merchant visibility; empty for every non-wallet
		// payment. Google Pay itself needs no special handling: the bank
		// applies 3-D Secure (FPAN) or relies on Google's prior device
		// authentication (DPAN) and returns the same card success payload
		// the plugin already verifies against the (unchanged) HashKey.
		$order->update_meta_data( '_epay_pan_card_type', $params['PanCardType'] );
		$order->update_meta_data( '_epay_last_callback_at', current_time( 'mysql', true ) );

		$result_code = $params['ResultCode'];
		$status_flag = $params['StatusFlag'];

		if ( '0' !== $result_code || 'Success' !== $status_flag ) {
			// Authenticate the failure callback before mutating order
			// state. Redirection Manual v2.9 §5 only mandates HashKey
			// verification on the success response, and Paycenter returns
			// an EMPTY HashKey on declined transactions (see the "Callback
			// diagnostics" note on the settings screen). We therefore
			// verify opportunistically and fail CLOSED only when a HashKey
			// is actually present but does not match: that signals a
			// forged / tampered payload, so the order must be left
			// untouched. When no HashKey is present (the documented
			// decline case) we rely on the MerchantReference secret
			// (order id + per-order CSPRNG suffix) already verified above
			// in handle_response(), which is what gates this branch today.
			if ( false === $this->verify_nonsuccess_signature( $order, $params, $tran_ticket ) ) {
				Epay_Paycenter_Logger::error(
					'Non-success callback carried an invalid HashKey; order state left unchanged.',
					array(
						'order_id' => $order_id,
						'support'  => $params['SupportReferenceID'],
					)
				);
				wp_safe_redirect( $order->get_checkout_payment_url() );
				exit;
			}

			$messages = $this->describe_failure( $params );

			// Not every non-success callback is a failure. IRIS ResponseCode
			// 09 means the transfer was initiated and may still settle, so
			// describe_failure() returns 'on-hold' for it and 'failed' for
			// everything else. Defaulting here keeps any future scenario that
			// forgets to set a status on the safe, unchanged-behaviour path.
			$new_status  = isset( $messages['status'] ) ? (string) $messages['status'] : 'failed';
			$notice_type = isset( $messages['notice_type'] ) ? (string) $messages['notice_type'] : 'error';
			$is_pending  = ( 'failed' !== $new_status );

			$order->update_status( $new_status, $messages['order_note'] );
			$order->save();

			// Surface the decline message via two redundant channels
			// because the Paycenter callback is a cross-origin POST
			// from the bank page: on SameSite=Lax browsers the
			// existing WooCommerce session cookie is stripped, so a
			// plain `wc_add_notice()` call here can land in an
			// orphan session that the customer's subsequent GET to
			// the pay-for-order URL never sees.
			//   1. Legacy session-bound notice (may or may not make
			//      it through, depending on browser / host cookie
			//      behaviour).
			//   2. Order-scoped transient drained by
			//      `Epay_Paycenter_Plugin::surface_pending_order_notice()`
			//      during the next render of this order's
			//      pay-for-order page - session-independent, which
			//      is what Redirection Manual v2.9 §5 "Display of
			//      transaction decline message received from Issuer
			//      on the user page" effectively requires.
			// `surface_pending_order_notice()` de-duplicates so the
			// customer never sees the same line twice.
			wc_add_notice( $messages['user_notice'], $notice_type );
			Epay_Paycenter_Plugin::queue_order_notice( $order_id, $messages['user_notice'], $notice_type );

			// Explicit "handler ran to completion" marker. If this line
			// is visible in WooCommerce -> Status -> Logs for a given
			// transaction but the customer saw "-1" or a blank page,
			// the issue lies downstream (e.g. the pay-for-order URL is
			// intercepted, or the Blocks checkout suppresses legacy
			// notices). If this line is ABSENT, the callback POST
			// never reached PHP at all (WAF / security plugin / wrong
			// URL in Euronet portal).
			Epay_Paycenter_Logger::info(
				'Paycenter non-success callback handled',
				array(
					'order_id'      => $order_id,
					'new_status'    => $new_status,
					'result_code'   => $params['ResultCode'],
					'response_code' => $params['ResponseCode'],
					'support'       => $params['SupportReferenceID'],
				)
			);

			// A genuine decline goes to the order's pay-for-order URL rather
			// than the generic /checkout/ page: it restores the order context
			// (cart contents, amounts, customer data) and gives the shopper a
			// "try again" affordance next to the decline notice, matching the
			// bank's "merchant application update for the declined
			// transaction" requirement.
			//
			// A PENDING payment must not land there. The pay-for-order page is
			// an invitation to pay again, and an IRIS transfer that is still in
			// flight may well settle, so a second attempt charges the customer
			// twice for one order - with no refund path, since the bank rejects
			// refunds on IRIS transactions (ResponseCode 9167). Send those to
			// the order-received page, which states the order is on hold.
			if ( $is_pending ) {
				wp_safe_redirect( $this->gateway->get_return_url( $order ) );
			} else {
				wp_safe_redirect( $order->get_checkout_payment_url() );
			}
			exit;
		}

		// $tran_ticket was resolved above from the OPEN attempt whose
		// MerchantReference matched this callback. An empty value here means
		// the matched attempt carries no ticket (e.g. it was already cleared
		// by a prior settlement), so success cannot be cryptographically
		// verified and the order is parked for manual review.
		if ( '' === $tran_ticket ) {
			Epay_Paycenter_Logger::error(
				'No TranTicket stored for order',
				array( 'order_id' => $order_id )
			);
			$order->update_status( 'on-hold', __( 'Paycenter reported success but no TranTicket was stored for verification. Manual review required.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ) );
			$order->save();
			wp_safe_redirect( $this->gateway->get_return_url( $order ) );
			exit;
		}

		$verify_fields = $this->build_hash_verify_fields( $tran_ticket, $params );

		if ( ! Epay_Paycenter_Hash::verify( $params['HashKey'], $verify_fields ) ) {
			Epay_Paycenter_Logger::error(
				'HashKey verification failed',
				array(
					'order_id' => $order_id,
					'support'  => $params['SupportReferenceID'],
				)
			);
			$order->update_status(
				'on-hold',
				__( 'Payment reported successful by Paycenter but HashKey verification failed. Manual review required before fulfilling the order.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' )
			);
			$order->save();
			wc_add_notice(
				__( 'Payment could not be verified. Our team has been notified. Please contact support before retrying the payment.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'error'
			);
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		// Verified success.
		//
		// Per Redirection Manual v2.9 §5, IRIS responses populate
		// ApprovalCode and SupportReferenceID but DO NOT populate
		// PackageNo / TraceID (those are card-network artefacts that
		// have no analogue in the IRIS / DIAS instant-payment flow).
		// We emit a payment-method-specific order note so the operator
		// can tell card vs IRIS settlements apart at a glance without
		// having to dig into the post-meta block, and so the empty
		// Package field on IRIS rows does not look like a bug.
		$is_iris = Epay_Paycenter_Gateway::is_iris_payment_method( $params['CardType'], $params['PaymentMethod'] );

		if ( $is_iris ) {
			$note = sprintf(
				/* translators: 1: approval code, 2: support reference id. */
				__( 'Paycenter IRIS payment approved. Approval code: %1$s, Support Reference ID: %2$s.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'' !== $params['ApprovalCode'] ? $params['ApprovalCode'] : '-',
				'' !== $params['SupportReferenceID'] ? $params['SupportReferenceID'] : '-'
			);
		} else {
			$note = sprintf(
				/* translators: 1: approval code, 2: package no, 3: support reference */
				__( 'Paycenter card payment approved. Approval code: %1$s, Package: %2$s, Support Reference ID: %3$s.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'' !== $params['ApprovalCode'] ? $params['ApprovalCode'] : '-',
				'' !== $params['PackageNo'] ? $params['PackageNo'] : '-',
				'' !== $params['SupportReferenceID'] ? $params['SupportReferenceID'] : '-'
			);

			// A non-empty PanCardType on a card payment means the customer
			// paid through Google Pay (Redirection Manual v3.1 §5). Flag it
			// on the note so operators can tell wallet settlements apart;
			// FPAN was 3-D Secure authenticated by the bank, DPAN was
			// authenticated inside Google's environment before the token
			// reached epay eCommerce.
			$pan_card_type = strtoupper( (string) $params['PanCardType'] );
			if ( 'FPAN' === $pan_card_type || 'DPAN' === $pan_card_type ) {
				$note .= ' ' . sprintf(
					/* translators: %s: Google Pay PAN type - FPAN (real card number) or DPAN (device token). */
					__( 'Paid via Google Pay (%s).', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					$pan_card_type
				);
			}
		}

		$order->update_meta_data( '_epay_approval_code', $params['ApprovalCode'] );
		$order->update_meta_data( '_epay_package_no', $params['PackageNo'] );
		$order->update_meta_data( '_epay_trace_id', $params['TraceID'] );
		$order->payment_complete( $params['TransactionId'] );
		$order->add_order_note( $note );
		// Remove all one-time secrets after successful verification: the
		// entire open-attempt set plus the legacy single-value TranTicket.
		// Any later (replayed) callback for a now-open reference is caught by
		// the is_paid() idempotency guard before it can reach verification.
		Epay_Paycenter_Gateway::clear_open_tickets( $order );
		$order->save();

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		wp_safe_redirect( $this->gateway->get_return_url( $order ) );
		exit;
	}

	/**
	 * Build the ordered field map used to recompute the HMAC-SHA256
	 * HashKey for a Paycenter callback, per Redirection Manual v2.9 §5.
	 *
	 * Single-sourced here so the success path and the non-success
	 * verifier cannot drift out of sync on the field set / ordering.
	 *
	 * @param string               $tran_ticket The order's stored TranTicket (HMAC key).
	 * @param array<string,string> $params      Sanitized callback parameters.
	 * @return array<string,string>
	 */
	private function build_hash_verify_fields( $tran_ticket, array $params ) {
		return array(
			'TranTicket'         => (string) $tran_ticket,
			'PosId'              => (string) $this->gateway->get_pos_id(),
			'AcquirerId'         => (string) $this->gateway->get_acquirer_id(),
			'MerchantReference'  => $params['MerchantReference'],
			'ApprovalCode'       => $params['ApprovalCode'],
			'Parameters'         => $params['Parameters'],
			'ResponseCode'       => $params['ResponseCode'],
			'SupportReferenceID' => $params['SupportReferenceID'],
			'AuthStatus'         => $params['AuthStatus'],
			'PackageNo'          => $params['PackageNo'],
			'StatusFlag'         => $params['StatusFlag'],
		);
	}

	/**
	 * Verify the HMAC-SHA256 HashKey on a NON-SUCCESS callback.
	 *
	 * Redirection Manual v2.9 §5 only requires HashKey verification on the
	 * success response, and Paycenter returns an EMPTY HashKey on declined
	 * / failed transactions (documented in the "Callback diagnostics" note
	 * on the settings screen). Verification is therefore opportunistic:
	 *
	 *   - true  : a HashKey was present AND matches the locally recomputed
	 *             digest - the callback is cryptographically authentic.
	 *   - false : a HashKey was present but does NOT match - the payload was
	 *             forged or tampered with; the caller MUST refuse to act.
	 *   - null  : no HashKey present (the documented decline case), or no
	 *             stored TranTicket to verify against (e.g. it was already
	 *             cleared after a prior successful settlement). Cryptographic
	 *             verification is impossible, so the caller falls back to the
	 *             MerchantReference secret (order id + per-order CSPRNG
	 *             suffix) that handle_response() already enforced upstream.
	 *
	 * @param WC_Order             $order       Order the callback maps to.
	 * @param array<string,string> $params      Sanitized callback parameters.
	 * @param string               $tran_ticket TranTicket of the attempt this callback
	 *                                          belongs to, resolved from the open-ticket
	 *                                          set by handle_response(). Falls back to the
	 *                                          legacy single-value order meta when empty.
	 * @return bool|null
	 */
	private function verify_nonsuccess_signature( $order, array $params, $tran_ticket = '' ) {
		$received = isset( $params['HashKey'] ) ? (string) $params['HashKey'] : '';
		if ( '' === $received ) {
			return null;
		}

		// Prefer the per-attempt ticket. An order may carry several open
		// attempts (each render of the pay-for-order page issues a fresh
		// ticket), and the HMAC key is the ticket belonging to THIS callback -
		// not whichever one happens to be in the legacy `_epay_tran_ticket`
		// meta. Verifying against the wrong key on a multi-attempt order would
		// fail closed and strand the order.
		$tran_ticket = (string) $tran_ticket;
		if ( '' === $tran_ticket ) {
			$tran_ticket = (string) $order->get_meta( '_epay_tran_ticket', true );
		}
		if ( '' === $tran_ticket ) {
			return null;
		}

		$verify_fields = $this->build_hash_verify_fields( $tran_ticket, $params );

		return Epay_Paycenter_Hash::verify( $received, $verify_fields ) ? true : false;
	}

	/**
	 * Record a non-success callback received for an already-paid order.
	 *
	 * Covers Redirection Manual v2.9 §7 Test Case 3 "RECHARGE ATTEMPT"
	 * (ResultCode 1048 - the MerchantReference was already used for an
	 * approved transaction) plus any other failure code the bank may
	 * return after the order was paid. The spec mandates storing
	 * SupportReferenceID / MerchantReference / ResultCode /
	 * ResultDescription and displaying a failure message on the user
	 * page. The values are stored under dedicated
	 * `_epay_recharge_attempt_*` keys so the approved transaction's
	 * audit meta stays intact, and the customer notice is queued for the
	 * thank-you page (the redirect target for paid orders) via the same
	 * session-independent transient channel used by the decline flow.
	 * No `wc_add_notice()` here: the thank-you template never renders
	 * the session notice stack, so a session copy could only surface as
	 * a stray message on some later page.
	 *
	 * @param WC_Order             $order       Paid order.
	 * @param array<string,string> $params      Sanitized callback parameters.
	 * @param string               $tran_ticket TranTicket of the matched attempt, if the
	 *                                          open-ticket set still holds one.
	 */
	private function record_recharge_attempt( $order, array $params, $tran_ticket = '' ) {
		// Same authentication rule as the failure branch: refuse to write
		// anything to the order when a HashKey is present but invalid. On a
		// paid order the one-time TranTicket has already been cleared after
		// the original settlement, so verify_nonsuccess_signature() usually
		// returns null (unverifiable) here and we proceed on the
		// MerchantReference match enforced upstream - the note/meta below
		// only annotate the already-correct paid order and never change its
		// status.
		if ( false === $this->verify_nonsuccess_signature( $order, $params, $tran_ticket ) ) {
			Epay_Paycenter_Logger::error(
				'Recharge-attempt callback on a paid order carried an invalid HashKey; ignored.',
				array( 'order_id' => $order->get_id() )
			);
			return;
		}

		$is_recharge = ( '1048' === (string) $params['ResultCode'] );

		$label = $is_recharge
			? __( 'Paycenter §7 Test Case 3: Recharge attempt on an already-paid order (ResultCode 1048). Order status unchanged.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' )
			: __( 'Paycenter: non-success callback received for an already-paid order. Order status unchanged.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );

		$order->add_order_note(
			sprintf(
				/* translators: 1: scenario label, 2: merchant reference, 3: result code, 4: result description, 5: response code, 6: support reference id. */
				__( '%1$s MerchantReference: %2$s, ResultCode: %3$s, ResultDescription: %4$s, ResponseCode: %5$s, SupportReferenceID: %6$s.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				$label,
				'' !== $params['MerchantReference'] ? $params['MerchantReference'] : '-',
				'' !== $params['ResultCode'] ? $params['ResultCode'] : '-',
				'' !== trim( (string) $params['ResultDescription'] ) ? $params['ResultDescription'] : '-',
				'' !== $params['ResponseCode'] ? $params['ResponseCode'] : '-',
				'' !== $params['SupportReferenceID'] ? $params['SupportReferenceID'] : '-'
			)
		);

		$order->update_meta_data( '_epay_recharge_attempt_at', current_time( 'mysql', true ) );
		$order->update_meta_data( '_epay_recharge_attempt_result_code', $params['ResultCode'] );
		$order->update_meta_data( '_epay_recharge_attempt_result_description', $params['ResultDescription'] );
		$order->update_meta_data( '_epay_recharge_attempt_support_reference_id', $params['SupportReferenceID'] );
		$order->save();

		Epay_Paycenter_Plugin::queue_order_notice(
			$order->get_id(),
			__( 'This order has already been paid. Your new payment attempt was not processed and no additional charge was made.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			'notice'
		);

		Epay_Paycenter_Logger::info(
			'Recharge attempt on paid order recorded',
			array(
				'order_id'    => $order->get_id(),
				'result_code' => $params['ResultCode'],
				'support'     => $params['SupportReferenceID'],
			)
		);
	}

	/**
	 * Handle cancel backlink. The customer is returned here when pressing
	 * Cancel on the Paycenter payment page.
	 */
	private function handle_cancel() {
		// Cancel is authenticated by a one-time token stored in order meta
		// and compared with hash_equals() further down. No nonce applies
		// because the link is followed from the bank payment page.
		$order_id = 0;
		$token    = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST['order_id'] ) && is_scalar( $_REQUEST['order_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_id = absint( $_REQUEST['order_id'] );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST['token'] ) && is_scalar( $_REQUEST['token'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$token = sanitize_text_field( wp_unslash( (string) $_REQUEST['token'] ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		// Validate the token against EVERY still-open payment attempt, not
		// only the most recent one. Each render of the pay-for-order page
		// issues a fresh cancel token and overwrites the legacy
		// `_epay_cancel_token` meta, so a customer who returns to an EARLIER
		// bank page (Back/Forward, or a second tab) and presses Cancel sends
		// the token of that earlier attempt. Comparing against the latest
		// value alone made the click fail closed: the order stayed
		// uncancelled and the shopper was bounced to the generic checkout
		// page with no explanation - the same multi-attempt strand the
		// open-ticket set was introduced to remove on the callback path.
		//
		// verify_cancel_token() scans the whole open-attempt set (the legacy
		// single value folded in) without an early return, so lookup time
		// does not reveal which attempt matched, and each comparison is
		// itself timing-safe via hash_equals(). It also rejects an empty
		// submitted token, so the previous '' === $expected guard is subsumed.
		if ( ! Epay_Paycenter_Gateway::verify_cancel_token( $order, $token ) ) {
			Epay_Paycenter_Logger::error( 'Cancel token mismatch', array( 'order_id' => $order_id ) );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		if ( ! $order->has_status( array( 'cancelled', 'failed', 'processing', 'completed' ) ) ) {
			$order->update_status( 'cancelled', __( 'Customer cancelled the Paycenter payment.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ) );
			$order->save();
		}

		// See the decline branch in handle_response() for the rationale:
		// the cancel link is also followed from the cross-origin bank
		// page, so the session-bound notice alone is not reliable.
		// Queue the same text via the order-scoped transient so the
		// pay-for-order page can re-surface it on the next render.
		$cancel_notice = __( 'Payment was cancelled. Your cart is preserved if you wish to try again.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );
		wc_add_notice( $cancel_notice, 'notice' );
		Epay_Paycenter_Plugin::queue_order_notice( $order_id, $cancel_notice, 'notice' );
		wp_safe_redirect( $order->get_checkout_payment_url() );
		exit;
	}

	/**
	 * Resolve a WooCommerce order id from callback parameters.
	 *
	 * Prefers the `wc_order_id=...` token we embed in `Parameters` at ticket
	 * creation time (robust against third-party plugins overriding the
	 * display order number) and falls back to parsing the leading numeric
	 * component of the MerchantReference.
	 *
	 * @param array $params Sanitized callback parameters.
	 * @return int
	 */
	private function resolve_order_id_from_params( array $params ) {
		$parameters = isset( $params['Parameters'] ) ? (string) $params['Parameters'] : '';
		if ( '' !== $parameters && preg_match( '/wc_order_id=(\d+)/', $parameters, $matches ) ) {
			return (int) $matches[1];
		}
		$reference = isset( $params['MerchantReference'] ) ? (string) $params['MerchantReference'] : '';
		if ( preg_match( '/^(\d+)/', $reference, $matches ) ) {
			return (int) $matches[1];
		}
		return 0;
	}

	/**
	 * Collect and sanitize all response parameters from POST / GET input.
	 *
	 * @return array<string,string>
	 */
	private function collect_response_params() {
		$fields = array(
			'SupportReferenceID',
			'ResultCode',
			'ResultDescription',
			'StatusFlag',
			'ResponseCode',
			'ResponseDescription',
			'LanguageCode',
			'MerchantReference',
			'TransactionDateTime',
			'TransactionId',
			'CardType',
			'PackageNo',
			'ApprovalCode',
			'RetrievalRef',
			'AuthStatus',
			'Parameters',
			'HashKey',
			'PaymentMethod',
			'TraceID',
			// Redirection Manual v3.1 §5: returned ONLY for Google Pay
			// (digital-wallet) transactions - FPAN (real card number) or
			// DPAN (device token). Empty for every other payment. Not part
			// of the HashKey field set, so collecting it is verification-neutral.
			'PanCardType',
		);

		$params = array();
		foreach ( $fields as $field ) {
			// Nonce verification is not applicable: these callbacks arrive
			// from the Paycenter back-end (not a browser session), and
			// authenticity is enforced by the HashKey HMAC-SHA256 check
			// further down the pipeline.
			// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
			if ( isset( $_POST[ $field ] ) && is_scalar( $_POST[ $field ] ) ) {
				$params[ $field ] = sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) );
			} elseif ( isset( $_GET[ $field ] ) && is_scalar( $_GET[ $field ] ) ) {
				$params[ $field ] = sanitize_text_field( wp_unslash( (string) $_GET[ $field ] ) );
			} else {
				$params[ $field ] = '';
			}
			// phpcs:enable
		}
		return $params;
	}

	/**
	 * Log a compact record of the incoming callback request.
	 *
	 * Written at INFO level on every callback reach - including ones that
	 * are subsequently discarded as noise - so that host WAF / ModSecurity
	 * incidents (which block the request before this handler runs) can be
	 * distinguished from plugin-side rejects simply by observing whether
	 * an envelope line was written for a given attempt.
	 *
	 * Field VALUES are intentionally omitted; only field names that were
	 * transmitted are listed. The actual values are logged later, after
	 * MerchantReference validation and HashKey-sensitive redaction.
	 *
	 * @param array<string,string> $params Sanitized callback parameters.
	 */
	/**
	 * Consume one unit of an hourly logging budget.
	 *
	 * Bounds how much attacker-controlled data an unauthenticated client can
	 * append to the WooCommerce log (see LOG_BUDGET_ENVELOPE). Counters are
	 * global rather than per-IP on purpose: a per-IP key would let a
	 * distributed flood create unbounded transient rows in wp_options, moving
	 * the exhaustion target from the disk to the database.
	 *
	 * Exhausting a budget cannot suppress the record of a real payment. Every
	 * caller of this method sits on a path that ends in a redirect without
	 * touching order state; verified callbacks log unconditionally further
	 * down, and order notes / order meta remain the authoritative audit trail.
	 *
	 * The read-then-write is not atomic. That is acceptable for a rate limit -
	 * concurrent requests may each consume the same slot, which errs slightly
	 * toward permitting rather than dropping.
	 *
	 * @param string $bucket Budget name ('envelope' or 'anomaly').
	 * @param int    $limit  Lines permitted per hour.
	 * @return bool True when the caller may log.
	 */
	private function consume_log_budget( $bucket, $limit ) {
		$key   = 'epay_paycenter_log_budget_' . $bucket;
		$count = (int) get_transient( $key );

		if ( $count > $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		if ( $count === $limit ) {
			// Emit exactly once per window so the operator can see that
			// suppression is active - itself a probing signal worth alerting on.
			Epay_Paycenter_Logger::error(
				'Callback logging budget exhausted for this hour; further lines of this kind are suppressed. The public callback URL is most likely being probed or flooded.',
				array(
					'bucket' => $bucket,
					'limit'  => $limit,
				)
			);
			return false;
		}

		return true;
	}

	private function log_callback_envelope( array $params ) {
		$method = 'UNKNOWN';
		// REMOTE_ADDR / HTTP_USER_AGENT / HTTP_REFERER are read for
		// diagnostic logging only; they are never used for authorisation.
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ) {
			$method = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
		}

		$remote_ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ) {
			$remote_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$user_agent = '';
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$user_agent = substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 200 );
		}

		$referer = '';
		if ( isset( $_SERVER['HTTP_REFERER'] ) && is_string( $_SERVER['HTTP_REFERER'] ) ) {
			$referer = substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 200 );
		}
		// phpcs:enable

		$fields_present = array();
		foreach ( $params as $key => $value ) {
			if ( '' !== $value ) {
				$fields_present[] = $key;
			}
		}

		Epay_Paycenter_Logger::info(
			'Callback envelope',
			array(
				'method'         => $method,
				'remote_ip'      => $remote_ip,
				'user_agent'     => $user_agent,
				'referer'        => $referer,
				'fields_present' => $fields_present,
				'field_count'    => count( $fields_present ),
			)
		);
	}

	/**
	 * Build the admin-facing order note and the customer-facing notice
	 * for a non-successful callback.
	 *
	 * Driven by the scenario table defined in Redirection Manual v2.9
	 * §5. Each non-success ResultCode maps to:
	 *   - a scenario label that is prepended to the admin order note
	 *     so operators can cite the exact §5 row when reconciling
	 *     with the bank, and
	 *   - a customer-facing notice whose wording matches the
	 *     "display a ... message on the user page" instruction for
	 *     that row.
	 *
	 * Issuer declines (ResultCode == 0 && StatusFlag != Success) are
	 * handled separately by describe_issuer_decline(): they do not
	 * carry a ResultDescription but DO carry a ResponseDescription
	 * that §5 requires us to surface verbatim.
	 *
	 * §9 "Anti-fraud" (ResultCode 7001) retains a redacted generic
	 * message regardless of what the bank returned, as disclosure of
	 * anti-fraud involvement to the customer is explicitly forbidden.
	 *
	 * @param array<string,string> $params Sanitized callback parameters.
	 * @return array{order_note:string,user_notice:string,status:string,notice_type:string}
	 */
	private function describe_failure( array $params ) {
		$result_code = (string) $params['ResultCode'];

		if ( '0' === $result_code ) {
			return $this->describe_issuer_decline( $params );
		}

		$scenario = $this->resolve_failure_scenario( $result_code );

		$order_note = sprintf(
			/* translators: 1: scenario label, 2: merchant reference, 3: result code, 4: result description, 5: response code, 6: support reference id. */
			__( '%1$s MerchantReference: %2$s, ResultCode: %3$s, ResultDescription: %4$s, ResponseCode: %5$s, SupportReferenceID: %6$s.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			$scenario['label'],
			'' !== $params['MerchantReference'] ? $params['MerchantReference'] : '-',
			$result_code,
			'' !== trim( (string) $params['ResultDescription'] ) ? $params['ResultDescription'] : '-',
			'' !== $params['ResponseCode'] ? $params['ResponseCode'] : '-',
			'' !== $params['SupportReferenceID'] ? $params['SupportReferenceID'] : '-'
		);

		return array(
			'order_note'  => $order_note,
			'user_notice' => $scenario['message'],
			'status'      => 'failed',
			'notice_type' => 'error',
		);
	}

	/**
	 * Resolve a non-zero ResultCode to its Redirection Manual v2.9 §5
	 * scenario row. Returns an array with keys:
	 *   - label:   short operator-facing scenario tag, prepended to
	 *              the admin order note.
	 *   - message: customer-facing notice rendered on the
	 *              pay-for-order page.
	 *
	 * Ordered so the 50x regex runs before the exact-value switch
	 * (500/501/502/... all share one row per spec "ResultCode = 50x").
	 *
	 * @param string $result_code Non-zero ResultCode from the callback.
	 * @return array{label:string,message:string}
	 */
	private function resolve_failure_scenario( $result_code ) {
		// §5 "Failure to execute a transaction due to (technical)
		// communication problem with the transaction processing
		// system". Spec wording: "ResultCode = 50x (i.e. 500, 501
		// etc.)". Regex covers the full 500-599 family without having
		// to enumerate each code. preg_match is safer than intval()
		// because ResultCode arrives as a string and may contain
		// non-numeric payloads on unexpected responses.
		if ( preg_match( '/^5\d{2}$/', (string) $result_code ) ) {
			return array(
				'label'   => __( 'Paycenter §5: Communication problem with the transaction processing system.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'message' => __( 'A temporary technical issue prevented the payment. Please try again in a few minutes.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			);
		}

		switch ( (string) $result_code ) {
			// §5 "Failure to execute a transaction due to incorrect
			// card details or a card not supported by the system."
			case '981':
				return array(
					'label'   => __( 'Paycenter §5: Incorrect card details or unsupported card.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'message' => __( 'We could not validate your card details. Please check the card number, expiry date, and CVV, or try a different card.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				);

			// §5 "Attempt to send a transaction with the same
			// MerchantReference as that of the transaction currently
			// processed by epay eCommerce (... transaction is
			// 'stalled')". Spec mandates prompting the merchant to
			// investigate via the epay eCommerce AdminTool, which we
			// embed verbatim in the operator-facing label.
			case '1045':
				return array(
					'label'   => __( 'Paycenter §5: Stalled transaction - please verify the transaction status in the epay eCommerce AdminTool before retrying.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'message' => __( 'A previous payment attempt for this order is still being processed. Please wait a few seconds and try again.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				);

			// §5 "Attempt to recharge a transaction (the request sent
			// had a MerchantReference value already used for an
			// approved transaction)". Customer wording rewritten: the
			// old "This order has already been paid" text was
			// misleading because our WC order is explicitly NOT paid
			// on this branch (the is_paid() idempotency guard earlier
			// in handle_response() short-circuits paid orders).
			case '1048':
				return array(
					'label'   => __( 'Paycenter §5: Recharge attempt (MerchantReference reused).', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'message' => __( 'The payment could not be processed because this payment reference has already been used. Please refresh the page and start a new payment. If you have already paid for this order, please contact us before paying again.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				);

			// §5 "Failure to execute a transaction because the current
			// transaction batch is being settled (batch closing)".
			case '1072':
				return array(
					'label'   => __( 'Paycenter §5: Batch closing.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'message' => __( 'The payment provider is finalising today\'s transactions. Please try again in a few minutes.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				);

			// §5 "Failure to execute a transaction due to a temporary
			// technical problem".
			case '1':
				return array(
					'label'   => __( 'Paycenter §5: Temporary technical problem.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'message' => __( 'A temporary issue prevented the payment. Please try again in a few minutes.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				);

			// §9 "Anti-fraud review". Disclosure of anti-fraud
			// involvement to the customer is explicitly forbidden, so
			// the customer message is deliberately generic regardless
			// of what the bank returned in ResultDescription.
			case '7001':
				return array(
					'label'   => __( 'Paycenter §9: Anti-fraud review - detail withheld from customer by spec.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'message' => __( 'Payment could not be authorised. Please try a different payment method.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				);

			// Any other non-zero ResultCode. Stored with the same
			// storage set §5 mandates for the named scenarios, shown
			// to the customer as a generic retry prompt, and
			// labelled so the operator knows this row did NOT match
			// any named §5 scenario (which itself is useful signal
			// during bank-side certification).
			default:
				return array(
					'label'   => __( 'Paycenter §5: Unclassified failure.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'message' => __( 'Payment could not be completed. Please try again or use a different payment method.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				);
		}
	}

	/**
	 * Build the admin-facing order note and the customer-facing notice
	 * for the §5 "Decline of a transaction" scenario (ResultCode == 0
	 * with StatusFlag != Success).
	 *
	 * §5 requires the merchant to "Display of transaction decline
	 * message received from Issuer on the user page". We therefore
	 * surface the Paycenter-supplied ResponseDescription verbatim when
	 * present, prefixed with a localised lead so the language of the
	 * surrounding UI stays consistent regardless of which language the
	 * issuer returned the description in. When the bank does not
	 * populate ResponseDescription, we fall back to well-known ISO 8583
	 * ResponseCode mappings and finally to a neutral "card was
	 * declined" prompt.
	 *
	 * @param array<string,string> $params Sanitized callback parameters.
	 * @return array{order_note:string,user_notice:string,status:string,notice_type:string}
	 */
	private function describe_issuer_decline( array $params ) {
		$response_code        = (string) $params['ResponseCode'];
		$response_description = trim( (string) $params['ResponseDescription'] );
		$merchant_ref         = (string) $params['MerchantReference'];
		$support              = (string) $params['SupportReferenceID'];
		$is_iris              = Epay_Paycenter_Gateway::is_iris_payment_method( $params['CardType'], $params['PaymentMethod'] );

		// IRIS ResponseCode 09 is NOT a decline. Redirection Manual §5 defines
		// it as "an IRIS payment has been initiated but not completed": the
		// customer started the transfer in their bank app and it may still
		// settle through DIAS afterwards. Treating it as `failed` is what
		// produced charged-but-failed orders - the money arrives, the order
		// says the payment failed, and nothing reconciles the two. It belongs
		// on-hold: stock stays reserved, the order stays open, and an operator
		// (or, later, the reconciliation job) can settle it against the bank.
		$is_iris_pending = ( $is_iris && '09' === strtoupper( $response_code ) );

		if ( $is_iris_pending ) {
			$scenario_label = __( 'Paycenter §5: IRIS payment initiated but not completed (ResponseCode 09). Order placed ON HOLD, not failed - it may still settle. Reconcile against the bank before cancelling or refunding.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );
		} elseif ( $is_iris ) {
			$scenario_label = __( 'Paycenter §5: IRIS payment decline.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );
		} else {
			$scenario_label = __( 'Paycenter §5: Issuer decline.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );
		}

		// Everything except the IRIS-pending case is a genuine decline.
		$status      = $is_iris_pending ? 'on-hold' : 'failed';
		$notice_type = $is_iris_pending ? 'notice' : 'error';

		$order_note = sprintf(
			/* translators: 1: scenario label, 2: merchant reference, 3: response code, 4: response description, 5: support reference id. */
			__( '%1$s MerchantReference: %2$s, ResponseCode: %3$s, ResponseDescription: %4$s, SupportReferenceID: %5$s.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			$scenario_label,
			'' !== $merchant_ref ? $merchant_ref : '-',
			'' !== $response_code ? $response_code : '-',
			'' !== $response_description ? $response_description : '-',
			'' !== $support ? $support : '-'
		);

		// IRIS-specific ResponseCodes per Redirection Manual v2.9 §5
		// (table "ResponseCode FREQUENT VALUES"). Same numeric code can
		// carry a different semantic for IRIS vs card transactions —
		// e.g. ResponseCode 05 is "Declined by Issuer" for cards but
		// "AuthorisingPartyAborded" (user cancelled in their bank's
		// e-banking app) for IRIS. The customer-facing copy below is
		// rewritten for each IRIS case so the shopper sees a message
		// that actually matches what they just experienced.
		if ( $is_iris ) {
			switch ( strtoupper( $response_code ) ) {
				case '05':
					$user_notice = __( 'You cancelled the payment from your bank app. No charge was made. You can try again or choose a different payment method.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );
					break;
				case '06':
					$user_notice = __( 'The IRIS payment could not be completed due to a technical issue. Please try again later or choose a different payment method.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );
					break;
				case '09':
					// §5: "An IRIS payment has been initiated but not
					// completed." The transfer may still settle, so the copy
					// must NOT invite a retry: a customer who pays again
					// while the first transfer is in flight gets charged
					// twice, and IRIS refunds are not available through this
					// integration (the bank returns ResponseCode 9167 for
					// refund requests on IRIS transactions).
					$user_notice = __( 'Your IRIS payment has been started but not yet confirmed. Your order is on hold and will update automatically once your bank confirms the transfer. Please do not pay again, or you may be charged twice. Contact us if you do not hear back.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );
					break;
				case '68':
					// IRIS-specific timeout: customer did not scan / confirm
					// within the 5-minute QR-code window.
					$user_notice = __( 'The IRIS payment timed out. The QR code expires after 5 minutes. Please try again.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );
					break;
				case '70':
					$user_notice = __( 'An unexpected error occurred with the IRIS service. Please try again later or choose a different payment method.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );
					break;
				default:
					// Other ResponseCodes that may apply to IRIS — surface
					// the verbatim description from the bank if present,
					// otherwise fall back to a neutral retry prompt.
					if ( '' !== $response_description ) {
						$user_notice = sprintf(
							/* translators: %s: decline reason as returned by the bank for an IRIS payment, verbatim. */
							__( 'Your IRIS payment was not completed. Reason: %s. Please try again or choose a different payment method.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
							$response_description
						);
					} else {
						$user_notice = __( 'Your IRIS payment was not completed. Please try again or choose a different payment method.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );
					}
			}

			return array(
				'order_note'  => $order_note,
				'user_notice' => $user_notice,
				'status'      => $status,
				'notice_type' => $notice_type,
			);
		}

		if ( '' !== $response_description ) {
			$user_notice = sprintf(
				/* translators: %s: decline reason as returned by the card issuer, verbatim. */
				__( 'Your card was declined by the issuer. Reason: %s. Please try a different card or contact your bank.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				$response_description
			);
		} else {
			switch ( strtoupper( $response_code ) ) {
				case '91':
				case '96':
					$user_notice = __( 'The card network is temporarily unavailable. Please try again in a few minutes.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );
					break;
				case '68':
					$user_notice = __( 'The payment timed out. Please try again.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );
					break;
				case 'BE':
					$user_notice = __( 'This card is not eligible for this type of payment. Please use a different card.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );
					break;
				default:
					$user_notice = __( 'Your card was declined. Please try a different card or contact your bank.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );
			}
		}

		return array(
			'order_note'  => $order_note,
			'user_notice' => $user_notice,
			'status'      => $status,
			'notice_type' => $notice_type,
		);
	}
}
