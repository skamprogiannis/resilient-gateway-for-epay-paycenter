<?php
/**
 * Plugin bootstrapper.
 *
 * @package EpayPaycenter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin loader. Handles activation, lifecycle hooks and class loading.
 */
final class Epay_Paycenter_Plugin {

	/**
	 * Bootstrap the plugin on plugins_loaded.
	 *
	 * Translations are auto-loaded by WordPress core since 4.6 based on
	 * the plugin slug, so no explicit load_plugin_textdomain() call is
	 * needed.
	 */
	public static function bootstrap() {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_requires_woocommerce' ) );
			return;
		}

		self::maybe_upgrade_db();
		self::autoload();

		// Reconciliation for payment attempts that never reached a final
		// state. Registers its own cron hook and re-schedules itself if the
		// event is missing, which the activation hook alone would not cover
		// on a plugin update.
		Epay_Paycenter_Reconciliation::init();

		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
		add_filter( 'plugin_action_links_' . EPAY_PAYCENTER_PLUGIN_BASENAME, array( __CLASS__, 'plugin_action_links' ) );

		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_blocks_support' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_settings_assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );

		// Store-down alert: the Ticketing Web Service rejected the merchant
		// credentials, so no payment can be started at all. Rendered on every
		// admin screen, because a merchant who is losing every card sale
		// should not have to visit the gateway settings to find that out.
		add_action( 'admin_notices', array( __CLASS__, 'notice_credentials_error' ) );

		// Admin-only AJAX: WAF / callback-URL self-test. Capability +
		// nonce checks happen inside the handler itself; we intentionally
		// do NOT register a `wp_ajax_nopriv_` variant so the endpoint is
		// unreachable to unauthenticated callers.
		add_action( 'wp_ajax_epay_paycenter_waf_test', array( 'Epay_Paycenter_Gateway', 'ajax_run_waf_test' ) );

		// Bind the Paycenter WC-API callback at bootstrap time instead
		// of inside the gateway constructor. WooCommerce's legacy
		// WC_API::handle_api_requests() finishes every request with an
		// unconditional `die( '-1' )`: if the gateway had not been
		// instantiated by the dispatcher (e.g. a conflicting plugin
		// short-circuits `WC()->payment_gateways()`), no listener would
		// be attached and the customer would see a literal "-1" body
		// after returning from the bank. Registering here, before
		// dispatch, removes that race entirely.
		add_action(
			'woocommerce_api_' . EPAY_PAYCENTER_GATEWAY_ID,
			array( __CLASS__, 'dispatch_api_callback' )
		);

		// Defensive URL normaliser: some Euronet portal configurations
		// concatenate the merchant-supplied callback path onto an
		// internal base URL that already contains that same path,
		// producing requests like:
		//   /wc-api/epay_paycenter//wc-api/epay_paycenter/
		// After Apache collapses `//` to `/`, WooCommerce's rewrite
		// captures the *entire* remainder as the `wc-api` query var
		// (`epay_paycenter/wc-api/epay_paycenter/`). No listener
		// matches that composite action name, so the dispatcher falls
		// through to `die( '-1' )`. This filter detects the pattern,
		// re-maps the query var to the canonical gateway id so the
		// bank's POST still reaches our handler, and emits an ERROR
		// log entry so the merchant corrects the portal configuration.
		// Priority intentionally lower than 0: WooCommerce's own
		// `WC_API::handle_api_requests()` also binds to `parse_request`
		// at priority 0, so we must rewrite the `wc-api` query var
		// BEFORE that dispatcher reads it. A negative priority keeps
		// us deterministically ahead regardless of plugin load order.
		add_action( 'parse_request', array( __CLASS__, 'normalize_malformed_callback_path' ), -10 );

		// Session-independent customer notice delivery on the
		// pay-for-order page. The Paycenter callback is a cross-origin
		// POST from the bank page; on SameSite=Lax browsers the
		// existing WooCommerce session cookie is stripped from that
		// POST, so any `wc_add_notice()` call made inside the handler
		// lands in an orphan anonymous session that the customer's
		// subsequent GET to the pay-for-order URL never sees. As a
		// result the Issuer's decline message - which Redirection
		// Manual v2.9 §5 REQUIRES the merchant to display on the
		// user page - was silently dropped. The handler now also
		// stores the pending notice as an order-scoped transient;
		// this hook drains that transient into `wc_add_notice()`
		// during the pay-for-order page render itself, where the
		// customer's real session cookie is present and the notice
		// stack is emitted on the same response.
		//
		// Priority 5 (explicitly lower than the default 10) is
		// required so our callback queues the notice BEFORE
		// WooCommerce core's own `woocommerce_output_all_notices`
		// drains the stack. Core registers that printer as:
		//   add_action( 'before_woocommerce_pay',
		//               'woocommerce_output_all_notices', 10 );
		// in `wc-template-hooks.php`. At the same priority our
		// callback would run after core's printer (registration
		// order tie-break), leaving the stack empty when core
		// renders the `.woocommerce-notices-wrapper` block - which
		// is exactly the silent failure observed in the wild. By
		// keeping our priority strictly below 10 we add to the stack
		// first, then core's priority-10 printer renders it through
		// WooCommerce's own `notices/{error,notice,success}.php`
		// templates. That path is theme-agnostic: WC core wires
		// the printer itself, so it works on every theme whether
		// the theme overrides those templates or not.
		add_action( 'before_woocommerce_pay', array( __CLASS__, 'surface_pending_order_notice' ), 5 );

		// Counterpart drain for the thank-you (order-received) page.
		// Recharge attempts on an already-paid order (Redirection Manual
		// v2.9 §7 Test Case 3, ResultCode 1048) redirect the customer to
		// the thank-you page - the order IS paid - not the pay-for-order
		// page, so the queued notice must also be drainable there. The
		// thank-you template never renders the WooCommerce session
		// notice stack, so this drain prints the message inline via
		// wc_print_notice() instead of re-queueing it into the session.
		add_action( 'woocommerce_before_thankyou', array( __CLASS__, 'surface_pending_thankyou_notice' ) );
	}

	/**
	 * Transient key prefix used by queue_order_notice() / surface_pending_order_notice().
	 */
	const NOTICE_TRANSIENT_PREFIX = 'epay_paycenter_notice_';

	/**
	 * Option key that stores the installed DB schema version.
	 */
	const DB_VERSION_OPTION = 'epay_paycenter_db_version';

	/**
	 * Current DB schema version. Bump whenever the table definition changes.
	 */
	const DB_VERSION = '1.0';

	/**
	 * Queue a WooCommerce notice for display on the next render of a
	 * given order's pay-for-order or thank-you page.
	 *
	 * The notice is stored as a short-lived transient keyed on the
	 * order id so it survives the cross-origin POST -> 302 -> GET
	 * redirect chain that the Paycenter callback inevitably goes
	 * through. Consumed (and cleared) by surface_pending_order_notice()
	 * or surface_pending_thankyou_notice() when the customer lands on
	 * the pay-for-order / order-received URL, or expires on its own
	 * after 15 minutes if the customer never arrives.
	 *
	 * @param int    $order_id Order id.
	 * @param string $message  Notice body (already translated).
	 * @param string $type     WooCommerce notice type: error | notice | success.
	 */
	public static function queue_order_notice( $order_id, $message, $type = 'error' ) {
		$order_id = absint( $order_id );
		$message  = (string) $message;
		if ( ! $order_id || '' === $message ) {
			return;
		}
		$allowed_types = array( 'error', 'notice', 'success' );
		if ( ! in_array( $type, $allowed_types, true ) ) {
			$type = 'error';
		}
		set_transient(
			self::NOTICE_TRANSIENT_PREFIX . $order_id,
			array(
				'message' => $message,
				'type'    => $type,
			),
			15 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Drain any pending order-scoped notice into wc_add_notice() on the
	 * pay-for-order page. Runs on `before_woocommerce_pay`, which
	 * executes inside WooCommerce's pay-order shortcode before
	 * `wc_print_notices()` renders the notice stack for that page.
	 *
	 * Dedup guard: if an identical notice has already been added to the
	 * current session (e.g. the legacy `wc_add_notice()` call inside
	 * the handler succeeded because this particular deployment retains
	 * the session cookie across the cross-origin POST), the transient
	 * is consumed without re-adding, preventing a duplicate line.
	 */
	public static function surface_pending_order_notice() {
		global $wp;

		$order_id = 0;
		if ( isset( $wp->query_vars['order-pay'] ) ) {
			$order_id = absint( $wp->query_vars['order-pay'] );
		}
		if ( ! $order_id ) {
			return;
		}

		$key     = self::NOTICE_TRANSIENT_PREFIX . $order_id;
		$pending = get_transient( $key );
		if ( ! is_array( $pending ) || empty( $pending['message'] ) ) {
			return;
		}

		$message = (string) $pending['message'];
		$type    = isset( $pending['type'] ) ? (string) $pending['type'] : 'error';

		// Only re-queue if WooCommerce has not already printed this
		// exact notice from the session. `wc_has_notice` covers the
		// rare case where the cross-origin POST DID carry the session
		// cookie through (older browsers, custom cookie attributes).
		$already_present = function_exists( 'wc_has_notice' ) && wc_has_notice( $message, $type );
		if ( ! $already_present && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $type );
		}

		delete_transient( $key );
	}

	/**
	 * Print any pending order-scoped notice at the top of the thank-you
	 * (order-received) page. Counterpart of surface_pending_order_notice()
	 * for orders that are already paid: the callback handler redirects
	 * recharge attempts (§7 Test Case 3, ResultCode 1048) to the
	 * thank-you page, whose template does not render the WooCommerce
	 * session notice stack, so the message is printed directly through
	 * WooCommerce's own `notices/{error,notice,success}.php` template
	 * via wc_print_notice() - theme-agnostic for every theme whose
	 * thank-you template fires the core `woocommerce_before_thankyou`
	 * action.
	 *
	 * @param int $order_id Order id supplied by woocommerce_before_thankyou.
	 */
	public static function surface_pending_thankyou_notice( $order_id ) {
		$order_id = absint( $order_id );
		if ( ! $order_id ) {
			return;
		}

		$key     = self::NOTICE_TRANSIENT_PREFIX . $order_id;
		$pending = get_transient( $key );
		if ( ! is_array( $pending ) || empty( $pending['message'] ) ) {
			return;
		}

		delete_transient( $key );

		if ( function_exists( 'wc_print_notice' ) ) {
			$type = isset( $pending['type'] ) ? (string) $pending['type'] : 'notice';
			wc_print_notice( (string) $pending['message'], $type );
		}
	}

	/**
	 * Detect and repair a malformed Paycenter callback URL.
	 *
	 * Canonical form: `?wc-api=epay_paycenter` or `/wc-api/epay_paycenter/`.
	 * Malformed variants seen in production originate from the Euronet
	 * portal prepending its own callback prefix to the URL already
	 * entered by the merchant, yielding composite values such as
	 * `epay_paycenter/wc-api/epay_paycenter/` in the `wc-api` query
	 * var. Any segmented value containing our gateway id is re-mapped
	 * to the canonical id so the request dispatches correctly; the
	 * original malformed value is logged so the merchant sees it in
	 * WooCommerce -> Status -> Logs and corrects the portal.
	 *
	 * Only triggers when the wc-api value is non-empty, NOT already
	 * our canonical id, and contains our id as a path segment - so
	 * the behaviour of every other gateway registered under WC-API is
	 * left untouched.
	 *
	 * @param WP $wp WordPress environment instance.
	 */
	public static function normalize_malformed_callback_path( $wp ) {
		if ( ! isset( $wp->query_vars['wc-api'] ) ) {
			return;
		}
		$api = (string) $wp->query_vars['wc-api'];
		if ( '' === $api || EPAY_PAYCENTER_GATEWAY_ID === $api ) {
			return;
		}
		$segments = array_filter( explode( '/', strtolower( $api ) ), 'strlen' );
		if ( ! in_array( EPAY_PAYCENTER_GATEWAY_ID, $segments, true ) ) {
			return;
		}

		if ( class_exists( 'Epay_Paycenter_Logger' ) ) {
			Epay_Paycenter_Logger::error(
				'Paycenter callback received on a malformed URL; re-mapping to the canonical handler. '
				. 'Please correct the Success / Failure URL registered in the Euronet Merchant Services '
				. 'portal so it equals exactly: ' . home_url( '/?wc-api=' . EPAY_PAYCENTER_GATEWAY_ID ),
				array(
					'received_wc_api' => $api,
					'expected_wc_api' => EPAY_PAYCENTER_GATEWAY_ID,
				)
			);
		}

		$wp->query_vars['wc-api'] = EPAY_PAYCENTER_GATEWAY_ID;
	}

	/**
	 * Dispatch a Paycenter WC-API callback.
	 *
	 * Instantiates the gateway on demand (so the handler has access to
	 * the merchant's settings for HashKey verification), then delegates
	 * to Epay_Paycenter_Handler::handle(). All branches of the handler
	 * terminate with `exit`, which prevents execution from ever reaching
	 * WooCommerce's `die( '-1' )` fallback at the tail of
	 * WC_API::handle_api_requests().
	 *
	 * A static re-entry guard protects against the (unlikely) case where
	 * the same hook fires twice in the same request lifecycle.
	 */
	public static function dispatch_api_callback() {
		static $dispatched = false;
		if ( $dispatched ) {
			return;
		}
		$dispatched = true;

		if ( ! class_exists( 'Epay_Paycenter_Gateway' ) ) {
			if ( class_exists( 'Epay_Paycenter_Logger' ) ) {
				Epay_Paycenter_Logger::error( 'Callback dispatched before gateway class autoloaded' );
			}
			wp_safe_redirect( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' ) );
			exit;
		}

		try {
			// Reuse the gateway instance that WooCommerce itself just
			// instantiated during WC_API::handle_api_requests() -> when
			// available - to avoid a second DB read for the same
			// settings and a redundant pass through the gateway
			// constructor's secondary action registrations.
			$gateway = null;
			if ( function_exists( 'WC' ) && isset( WC()->payment_gateways ) && is_object( WC()->payment_gateways ) ) {
				$gateways = WC()->payment_gateways->payment_gateways();
				if ( isset( $gateways[ EPAY_PAYCENTER_GATEWAY_ID ] ) && $gateways[ EPAY_PAYCENTER_GATEWAY_ID ] instanceof Epay_Paycenter_Gateway ) {
					$gateway = $gateways[ EPAY_PAYCENTER_GATEWAY_ID ];
				}
			}
			if ( ! $gateway instanceof Epay_Paycenter_Gateway ) {
				$gateway = new Epay_Paycenter_Gateway();
			}

			$gateway->get_handler()->handle();
		} catch ( \Throwable $e ) {
			if ( class_exists( 'Epay_Paycenter_Logger' ) ) {
				Epay_Paycenter_Logger::error(
					'Fatal while dispatching Paycenter callback',
					array( 'message' => $e->getMessage() )
				);
			}
			wp_safe_redirect( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' ) );
			exit;
		}
	}

	/**
	 * Enqueue the branded admin settings styles and the copy-to-clipboard
	 * helper script, but only on this gateway's WooCommerce settings screen.
	 *
	 * Scoping prevents the plugin from adding any asset weight to unrelated
	 * admin pages and keeps Dashicons usage (bundled with WordPress core)
	 * inside its intended context. No external URLs are loaded.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public static function enqueue_admin_settings_assets( $hook_suffix ) {
		if ( 'woocommerce_page_wc-settings' !== $hook_suffix ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Read tab/section query args to decide whether to enqueue assets.
		// This is a read-only conditional for script loading — no state is
		// changed — so a nonce is not applicable on this GET-based admin
		// page load. The capability check above ensures only authorised
		// users can trigger the enqueue path.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$tab     = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] )
			? sanitize_key( wp_unslash( (string) $_GET['tab'] ) )
			: '';
		$section = isset( $_GET['section'] ) && is_scalar( $_GET['section'] )
			? sanitize_key( wp_unslash( (string) $_GET['section'] ) )
			: '';
		// phpcs:enable

		if ( 'checkout' !== $tab || EPAY_PAYCENTER_GATEWAY_ID !== $section ) {
			return;
		}

		$version = defined( 'EPAY_PAYCENTER_VERSION' ) ? EPAY_PAYCENTER_VERSION : false;

		wp_enqueue_style( 'dashicons' );

		wp_enqueue_style(
			'epay-paycenter-admin',
			EPAY_PAYCENTER_PLUGIN_URL . 'assets/css/epay-paycenter-admin.css',
			array( 'dashicons' ),
			$version
		);

		wp_enqueue_script(
			'epay-paycenter-admin',
			EPAY_PAYCENTER_PLUGIN_URL . 'assets/js/epay-paycenter-admin.js',
			array(),
			$version,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			'epay-paycenter-admin',
			'epayPaycenterAdmin',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'wafTestNonce' => wp_create_nonce( 'epay_paycenter_waf_test' ),
				'strings'      => array(
					'copy'           => __( 'Copy', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'copied'         => __( 'Copied', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'failed'         => __( 'Press Ctrl+C to copy', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'wafTesting'     => __( 'Testing callback URL…', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'wafPass'        => __( 'Callback URL reachable. No host WAF interception detected.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'wafBlocked'     => __( 'Callback URL blocked by a host WAF / firewall. Real Paycenter callbacks will fail until this URL is whitelisted.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'wafServerError' => __( 'Callback URL returned a server error. Inspect the WooCommerce → Status → Logs panel and your webserver error log.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'wafNoResponse'  => __( 'No response received from the callback URL. Check DNS / loopback connectivity from this host.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'wafTransport'   => __( 'The self-test could not reach the callback URL from this server.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'wafHttpStatus'  => __( 'HTTP status:', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'wafHeaders'     => __( 'Response headers (filtered):', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'wafBody'        => __( 'Response body snippet:', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'wafTarget'      => __( 'Target URL:', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'wafNetworkErr'  => __( 'Network error. The browser could not reach admin-ajax.php.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				),
			)
		);
	}

	/**
	 * Enqueue the front-end stylesheet on checkout pages so the payment
	 * icon renders at the correct responsive size in both classic and
	 * block checkout.
	 */
	public static function enqueue_frontend_assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		$version = defined( 'EPAY_PAYCENTER_VERSION' ) ? EPAY_PAYCENTER_VERSION : false;

		wp_enqueue_style(
			'epay-paycenter',
			EPAY_PAYCENTER_PLUGIN_URL . 'assets/css/epay-paycenter.css',
			array(),
			$version
		);
	}

	/**
	 * Register plugin classes via a small explicit loader. Keeps loading
	 * deterministic without depending on Composer.
	 */
	private static function autoload() {
		$files = array(
			'class-epay-paycenter-logger.php',
			'class-epay-paycenter-countries.php',
			'class-epay-paycenter-currencies.php',
			'class-epay-paycenter-hash.php',
			'class-epay-paycenter-ticketing.php',
			'class-epay-paycenter-handler.php',
			'class-epay-paycenter-gateway.php',
			'class-epay-paycenter-blocks.php',
			'class-epay-paycenter-reconciliation.php',
		);
		foreach ( $files as $file ) {
			require_once EPAY_PAYCENTER_PLUGIN_DIR . 'includes/' . $file;
		}
	}

	/**
	 * Register the gateway with WooCommerce.
	 *
	 * @param array $methods Existing payment methods.
	 * @return array
	 */
	public static function register_gateway( $methods ) {
		$methods[] = 'Epay_Paycenter_Gateway';
		return $methods;
	}

	/**
	 * Register support for the WooCommerce Blocks checkout.
	 */
	public static function register_blocks_support() {
		if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				if ( class_exists( 'Epay_Paycenter_Blocks_Support' ) ) {
					$registry->register( new Epay_Paycenter_Blocks_Support() );
				}
			}
		);
	}

	/**
	 * Declare HPOS (Custom Order Tables) and Checkout Blocks compatibility.
	 */
	public static function declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', EPAY_PAYCENTER_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', EPAY_PAYCENTER_PLUGIN_FILE, true );
		}
	}

	/**
	 * Add quick settings link on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function plugin_action_links( $links ) {
		$settings_url   = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . EPAY_PAYCENTER_GATEWAY_ID );
		$settings_link  = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Activation handler. Creates the plugin's transaction table and records the
	 * current schema version so future upgrades can detect whether to run dbDelta.
	 */
	public static function on_activate() {
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

		require_once EPAY_PAYCENTER_PLUGIN_DIR . 'includes/class-epay-paycenter-reconciliation.php';
		Epay_Paycenter_Reconciliation::schedule();
	}

	/**
	 * Run dbDelta when the stored schema version does not match the current one.
	 * Called from bootstrap() on every plugins_loaded so plugin upgrades that do
	 * not trigger the activation hook still receive table migrations.
	 */
	private static function maybe_upgrade_db() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Deactivation handler. No destructive operations; uninstall.php handles cleanup.
	 */
	public static function on_deactivate() {
		// No destructive operations; uninstall.php handles data cleanup.
		// The cron event must go, though: leaving it scheduled would keep
		// firing a hook whose class is no longer loaded.
		require_once EPAY_PAYCENTER_PLUGIN_DIR . 'includes/class-epay-paycenter-reconciliation.php';
		Epay_Paycenter_Reconciliation::unschedule();
	}

	/**
	 * Creates the plugin's transactions log table. All writes use $wpdb->prepare.
	 */
	private static function create_tables() {
		global $wpdb;

		$table           = $wpdb->prefix . 'epay_paycenter_tickets';
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta() does not support $wpdb->prepare(); table name and charset
		// are derived from trusted sources ($wpdb->prefix + static literal,
		// $wpdb->get_charset_collate()), never from user input.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			merchant_reference VARCHAR(50) NOT NULL,
			tran_ticket_hash CHAR(64) NOT NULL,
			acquirer_id INT UNSIGNED NOT NULL,
			merchant_id BIGINT UNSIGNED NOT NULL,
			pos_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(18,2) NOT NULL,
			currency_code SMALLINT UNSIGNED NOT NULL,
			installments TINYINT UNSIGNED NOT NULL DEFAULT 0,
			request_type CHAR(2) NOT NULL DEFAULT '02',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY merchant_reference (merchant_reference)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Option holding an active credentials alert (ResultCode 100).
	 */
	const CREDENTIALS_ALERT_OPTION = 'epay_paycenter_credentials_alert';

	/**
	 * Record that the Ticketing Web Service rejected the stored credentials.
	 *
	 * Annex 6 defines ResultCode 100 as "Authentication Error: wrong value is
	 * used in «Username» / «User» parameter and/or «Password» parameter". It
	 * can only arise at IssueNewTicket, which is the one call that carries the
	 * merchant credentials, and it fails EVERY checkout: no ticket means no
	 * redirect to the bank, so the store cannot take a card payment at all.
	 *
	 * Until now that surfaced only as a per-customer decline plus an order
	 * note. A merchant could lose a full day of card revenue with nothing on
	 * any admin screen to say why, which is precisely the failure mode worth
	 * being loud about.
	 *
	 * @param string $description ResultDescription returned by the bank.
	 */
	public static function flag_credentials_error( $description ) {
		$existing = get_option( self::CREDENTIALS_ALERT_OPTION );

		// Keep the FIRST failure time across a run of failures, so the notice
		// can say how long the store has actually been down rather than
		// resetting to "just now" on every checkout attempt.
		$since = ( is_array( $existing ) && ! empty( $existing['since'] ) )
			? (string) $existing['since']
			: current_time( 'mysql', true );

		update_option(
			self::CREDENTIALS_ALERT_OPTION,
			array(
				'since'       => $since,
				'last_seen'   => current_time( 'mysql', true ),
				'description' => substr( (string) $description, 0, 200 ),
			),
			false
		);
	}

	/**
	 * Clear the credentials alert.
	 *
	 * Called on any successful ticket issuance: a ticket can only be issued
	 * when the credentials authenticated, so success is proof the problem is
	 * over. Self-clearing is what lets the notice be non-dismissible without
	 * becoming permanent noise.
	 */
	public static function clear_credentials_error() {
		if ( false !== get_option( self::CREDENTIALS_ALERT_OPTION ) ) {
			delete_option( self::CREDENTIALS_ALERT_OPTION );
		}
	}

	/**
	 * Render the credentials alert.
	 *
	 * Deliberately not dismissible. Dismissing it would hide a total outage of
	 * card payments, and it removes itself the moment a ticket succeeds.
	 */
	public static function notice_credentials_error() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$alert = get_option( self::CREDENTIALS_ALERT_OPTION );
		if ( ! is_array( $alert ) || empty( $alert['since'] ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . EPAY_PAYCENTER_GATEWAY_ID );

		echo '<div class="notice notice-error">';
		echo '<p><strong>' . esc_html__( 'ePay Paycenter: card payments are failing', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ) . '</strong></p>';

		echo '<p>' . esc_html__(
			'The bank rejected this store\'s Ticketing Web Service credentials (ResultCode 100, Authentication Error). No payment can be started while this lasts: every customer choosing this payment method will see a failure. Check the Username and Password on the gateway settings screen against the values issued by Euronet Merchant Services, and remember that the environment (Test / Live) has its own separate credentials.',
			'secure-card-gateway-for-epay-paycenter-piraeus-bank'
		) . '</p>';

		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: date and time the failures began, in UTC. 2: description returned by the bank. */
				__( 'First seen: %1$s UTC. Bank response: %2$s', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				(string) $alert['since'],
				'' !== (string) $alert['description'] ? (string) $alert['description'] : '-'
			)
		) . '</p>';

		echo '<p><a class="button button-primary" href="' . esc_url( $settings_url ) . '">'
			. esc_html__( 'Check the gateway credentials', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' )
			. '</a></p>';

		echo '<p><em>' . esc_html__( 'This notice disappears on its own as soon as a payment is successfully started, so there is nothing to dismiss once the credentials are corrected.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ) . '</em></p>';
		echo '</div>';
	}

	/**
	 * Admin notice shown when WooCommerce is missing.
	 */
	public static function notice_requires_woocommerce() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo esc_html__(
			'ePay Paycenter for WooCommerce requires WooCommerce to be installed and active.',
			'secure-card-gateway-for-epay-paycenter-piraeus-bank'
		);
		echo '</p></div>';
	}
}
