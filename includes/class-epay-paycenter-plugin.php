<?php
/**
 * Plugin bootstrapper.
 *
 * @package EpayPaycenter
 *
 * Modified by the fork contributors on 2026-08-09, 2026-09-04,
 * and 2026-09-07. See NOTICE.md for attribution.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin loader. Handles activation, lifecycle hooks and class loading.
 */
final class Epay_Paycenter_Plugin {

	/**
	 * Bootstrap the plugin on plugins_loaded.
	 */
	public static function bootstrap(): void {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 0 );
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_requires_woocommerce' ) );
			return;
		}

		self::maybe_upgrade_db();
		self::autoload();
		add_action(
			'woocommerce_order_status_cancelled',
			array( 'Epay_Paycenter_Ticket_Audit', 'expire_pending_for_cancelled_order' )
		);

		// Initialization also restores missing cron events after plugin updates.
		Epay_Paycenter_Reconciliation::init();

		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
		add_filter( 'plugin_action_links_' . EPAY_PAYCENTER_PLUGIN_BASENAME, array( __CLASS__, 'plugin_action_links' ) );

		add_action( 'woocommerce_blocks_payment_method_type_registration', array( __CLASS__, 'register_blocks_support' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_settings_assets' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );

		// Invalid Ticketing credentials prevent every ePay payment from starting.
		add_action( 'admin_notices', array( 'Epay_Paycenter_Credential_Notice', 'render' ) );

		// The diagnostic enforces its own capability and nonce checks.
		add_action( 'wp_ajax_epay_paycenter_waf_test', array( 'Epay_Paycenter_Gateway', 'ajax_run_waf_test' ) );

		// Register before WooCommerce instantiates gateways so callbacks always route.
		add_action(
			'woocommerce_api_' . EPAY_PAYCENTER_GATEWAY_ID,
			array( __CLASS__, 'dispatch_api_callback' )
		);

		// Normalize duplicated callback paths before WooCommerce dispatches at priority 0.
		add_action( 'parse_request', array( __CLASS__, 'normalize_malformed_callback_path' ), -10 );

		// Drain order-scoped callback notices before WooCommerce prints its
		// pay-page notice stack at the default priority 10.
		add_action( 'before_woocommerce_pay', array( 'Epay_Paycenter_Order_Notices', 'render_payment_page' ), 5 );
		add_action( 'template_redirect', array( 'Epay_Paycenter_Order_Notices', 'checkout_return' ), 5 );

		// Paid-order recharge notices land on the thank-you page instead.
		add_action( 'woocommerce_before_thankyou', array( 'Epay_Paycenter_Order_Notices', 'render_thankyou_page' ) );
	}

	/**
	 * Register bundled catalogs for installations outside WordPress.org.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain(
			'resilient-gateway-for-epay-paycenter',
			false,
			dirname( EPAY_PAYCENTER_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Option key that stores the installed schema and data-migration version.
	 */
	const DB_VERSION_OPTION = 'epay_paycenter_db_version';

	/**
	 * Current schema and data-migration version. This is intentionally
	 * independent from the public plugin version.
	 */
	const DB_VERSION = '2.2';

	/**
	 * Normalize callbacks whose gateway path is repeated by the bank portal.
	 *
	 * Only segmented values containing this gateway ID are changed. The request
	 * still passes through ordinary payment authentication after routing.
	 *
	 * @param WP $wp WordPress environment instance.
	 */
	public static function normalize_malformed_callback_path( $wp ): void {
		if ( ! isset( $wp->query_vars['wc-api'] ) || ! is_string( $wp->query_vars['wc-api'] ) ) {
			return;
		}
		$api = (string) $wp->query_vars['wc-api'];
		if ( '' === $api || EPAY_PAYCENTER_GATEWAY_ID === $api ) {
			return;
		}
		$segments = explode( '/', strtolower( $api ) );
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
	 * Resolves the gateway on demand so a request-scoped handler has access to
	 * the merchant's settings for HashKey verification, then delegates to
	 * Epay_Paycenter_Handler::handle(). All branches of the handler
	 * terminate with `exit`, which prevents execution from ever reaching
	 * WooCommerce's `die( '-1' )` fallback at the tail of
	 * WC_API::handle_api_requests().
	 *
	 * A static re-entry guard protects against the (unlikely) case where
	 * the same hook fires twice in the same request lifecycle.
	 */
	public static function dispatch_api_callback(): void {
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
			// Reuse WooCommerce's instance to avoid registering its hooks twice.
			$gateway = null;
			if ( function_exists( 'WC' ) ) {
				$gateways = WC()->payment_gateways()->payment_gateways();
				if ( isset( $gateways[ EPAY_PAYCENTER_GATEWAY_ID ] ) && $gateways[ EPAY_PAYCENTER_GATEWAY_ID ] instanceof Epay_Paycenter_Gateway ) {
					$gateway = $gateways[ EPAY_PAYCENTER_GATEWAY_ID ];
				}
			}
			if ( ! $gateway instanceof Epay_Paycenter_Gateway ) {
				$gateway = new Epay_Paycenter_Gateway();
			}

			$handler = new Epay_Paycenter_Handler( $gateway );
			$handler->handle();
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
	public static function enqueue_admin_settings_assets( $hook_suffix ): void {
		if ( 'woocommerce_page_wc-settings' !== $hook_suffix ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Read-only asset selection needs no nonce; capability is checked above.
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
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'wafTestNonce'  => wp_create_nonce( 'epay_paycenter_waf_test' ),
				'followUpNonce' => wp_create_nonce( 'epay_paycenter_follow_up_test' ),
				'strings'       => array(
					'copy'            => __( 'Copy', 'resilient-gateway-for-epay-paycenter' ),
					'copied'          => __( 'Copied', 'resilient-gateway-for-epay-paycenter' ),
					'failed'          => __( 'Press Ctrl+C to copy', 'resilient-gateway-for-epay-paycenter' ),
					'wafTesting'      => __( 'Testing callback URL…', 'resilient-gateway-for-epay-paycenter' ),
					'wafPass'         => __( 'Callback handler reached. This does not verify delivery from the bank.', 'resilient-gateway-for-epay-paycenter' ),
					'wafUnexpected'   => __( 'The callback handler could not be verified.', 'resilient-gateway-for-epay-paycenter' ),
					'wafBlocked'      => __( 'Callback URL blocked by a host WAF / firewall. Real Paycenter callbacks will fail until this URL is whitelisted.', 'resilient-gateway-for-epay-paycenter' ),
					'wafServerError'  => __( 'Callback URL returned a server error. Inspect the WooCommerce → Status → Logs panel and your webserver error log.', 'resilient-gateway-for-epay-paycenter' ),
					'wafNoResponse'   => __( 'No response received from the callback URL. Check DNS / loopback connectivity from this host.', 'resilient-gateway-for-epay-paycenter' ),
					'wafTransport'    => __( 'The self-test could not reach the callback URL from this server.', 'resilient-gateway-for-epay-paycenter' ),
					'wafHttpStatus'   => __( 'HTTP status:', 'resilient-gateway-for-epay-paycenter' ),
					'wafHeaders'      => __( 'Response headers (filtered):', 'resilient-gateway-for-epay-paycenter' ),
					'wafBody'         => __( 'Response body snippet:', 'resilient-gateway-for-epay-paycenter' ),
					'wafTarget'       => __( 'Target URL:', 'resilient-gateway-for-epay-paycenter' ),
					'wafNetworkErr'   => __( 'Network error. The browser could not reach admin-ajax.php.', 'resilient-gateway-for-epay-paycenter' ),
					'followUpTesting' => __( 'Checking the bank with both supported channels…', 'resilient-gateway-for-epay-paycenter' ),
					'followUpOrder'   => __( 'Enter a successful ePay order ID first.', 'resilient-gateway-for-epay-paycenter' ),
					'followUpChannel' => __( 'Verified channel:', 'resilient-gateway-for-epay-paycenter' ),
					'followUpMethod'  => __( 'Payment method:', 'resilient-gateway-for-epay-paycenter' ),
					'followUpCode'    => __( 'Response code:', 'resilient-gateway-for-epay-paycenter' ),
					'followUpTime'    => __( 'Transaction time:', 'resilient-gateway-for-epay-paycenter' ),
				),
			)
		);
	}

	/**
	 * Enqueue the front-end stylesheet on checkout pages so the payment
	 * icon renders at the correct responsive size in both classic and
	 * block checkout.
	 */
	public static function enqueue_frontend_assets(): void {
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
	private static function autoload(): void {
		$files = array(
			'class-epay-paycenter-credentials.php',
			'class-epay-paycenter-credential-notice.php',
			'class-epay-paycenter-order-notices.php',
			'class-epay-paycenter-review.php',
			'class-epay-paycenter-open-tickets.php',
			'class-epay-paycenter-logger.php',
			'class-epay-paycenter-ticket-audit.php',
			'class-epay-paycenter-countries.php',
			'class-epay-paycenter-currencies.php',
			'class-epay-paycenter-hash.php',
			'class-epay-paycenter-ticketing.php',
			'class-epay-paycenter-follow-up.php',
			'class-epay-paycenter-gateway.php',
			'class-epay-paycenter-handler.php',
			'class-epay-paycenter-reconciliation.php',
		);
		foreach ( $files as $file ) {
			require_once EPAY_PAYCENTER_PLUGIN_DIR . 'includes/' . $file;
		}
	}

	/**
	 * Register the gateway with WooCommerce.
	 *
	 * @param array<WC_Payment_Gateway|class-string<WC_Payment_Gateway>> $methods Existing payment methods.
	 * @return array<WC_Payment_Gateway|class-string<WC_Payment_Gateway>>
	 */
	public static function register_gateway( $methods ) {
		$methods[] = 'Epay_Paycenter_Gateway';
		return $methods;
	}

	/**
	 * Register when Blocks initializes its payment registry, after plugins load.
	 *
	 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Blocks payment registry.
	 */
	public static function register_blocks_support( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ): void {
		require_once EPAY_PAYCENTER_PLUGIN_DIR . 'includes/class-epay-paycenter-blocks-support.php';
		$registry->register( new Epay_Paycenter_Blocks_Support() );
	}

	/**
	 * Declare HPOS (Custom Order Tables) and Checkout Blocks compatibility.
	 */
	public static function declare_compatibility(): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', EPAY_PAYCENTER_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', EPAY_PAYCENTER_PLUGIN_FILE, true );
		}
	}

	/**
	 * Add quick settings link on the Plugins screen.
	 *
	 * @param array<string> $links Existing action links.
	 * @return array<string>
	 */
	public static function plugin_action_links( $links ) {
		$settings_url  = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . EPAY_PAYCENTER_GATEWAY_ID );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'resilient-gateway-for-epay-paycenter' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Activation handler. Runs the same ordered migrations used by normal updates.
	 */
	public static function on_activate(): void {
		require_once EPAY_PAYCENTER_PLUGIN_DIR . 'includes/class-epay-paycenter-credentials.php';
		self::maybe_upgrade_db();

		require_once EPAY_PAYCENTER_PLUGIN_DIR . 'includes/class-epay-paycenter-follow-up.php';
		require_once EPAY_PAYCENTER_PLUGIN_DIR . 'includes/class-epay-paycenter-reconciliation.php';
		if ( Epay_Paycenter_Reconciliation::is_enabled() ) {
			Epay_Paycenter_Reconciliation::schedule();
		}
	}

	/**
	 * Run ordered schema and data migrations for direct or skipped-version updates.
	 * Called from bootstrap() because WordPress updates do not run activation hooks.
	 */
	private static function maybe_upgrade_db(): void {
		$installed_version = (string) get_option( self::DB_VERSION_OPTION, '' );
		if ( '' !== $installed_version && version_compare( $installed_version, self::DB_VERSION, '>=' ) ) {
			return;
		}

		self::create_tables();
		if ( version_compare( '' === $installed_version ? '0' : $installed_version, '2.1', '<' )
			&& ! self::migrate_to_2_1() ) {
			return;
		}

		if ( ! self::reopen_ambiguous_follow_up() ) {
			return;
		}
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/** Recheck premature FOLLOW_UP declines without changing any order or bank result. */
	private static function reopen_ambiguous_follow_up(): bool {
		global $wpdb;
		// Retain the original response fields as evidence; the ordinary worker
		// rechecks once even beyond 48h, then reports any continuing uncertainty.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET follow_up_state = 'pending', resolved_at = NULL,
				 next_check_at = %s, resolution_source = ''
				 WHERE follow_up_state = 'declined' AND resolution_source = 'follow_up'
				 AND follow_up_result_code = '0' AND follow_up_status_flag = 'Failure'
				 AND follow_up_response_code = '09'
				 AND (LOWER(follow_up_payment_method) <> 'card'
				 OR follow_up_iris_transaction_id <> '' OR follow_up_iris_status <> '')",
				$wpdb->prefix . 'epay_paycenter_tickets',
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		return false !== $result;
	}

	/**
	 * Convert legacy plaintext credentials and warning-only reconciliation state.
	 *
	 * @return bool Whether every persistence step completed.
	 */
	private static function migrate_to_2_1() {
		require_once EPAY_PAYCENTER_PLUGIN_DIR . 'includes/class-epay-paycenter-credentials.php';
		require_once EPAY_PAYCENTER_PLUGIN_DIR . 'includes/class-epay-paycenter-reconciliation.php';

		Epay_Paycenter_Reconciliation::migrate_from_warning_only_version();

		$option   = 'woocommerce_' . EPAY_PAYCENTER_GATEWAY_ID . '_settings';
		$settings = get_option( $option, array() );
		if ( ! is_array( $settings ) ) {
			return true;
		}
		$stored   = isset( $settings['password'] ) && is_string( $settings['password'] ) ? $settings['password'] : '';
		$migrated = Epay_Paycenter_Credentials::for_storage( $stored );
		if ( $migrated === $stored ) {
			return true;
		}

		$settings['password'] = $migrated;
		return update_option( $option, $settings, false );
	}

	/**
	 * Deactivation handler. No destructive operations; uninstall.php handles cleanup.
	 */
	public static function on_deactivate(): void {
		// No destructive operations; uninstall.php handles data cleanup.
		// The cron event must go, though: leaving it scheduled would keep
		// firing a hook whose class is no longer loaded.
		require_once EPAY_PAYCENTER_PLUGIN_DIR . 'includes/class-epay-paycenter-reconciliation.php';
		Epay_Paycenter_Reconciliation::unschedule();
	}

	/**
	 * Creates the plugin's transactions log table. All writes use $wpdb->prepare.
	 */
	private static function create_tables(): void {
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
			follow_up_state VARCHAR(20) NOT NULL DEFAULT '',
			follow_up_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			last_checked_at DATETIME NULL DEFAULT NULL,
			next_check_at DATETIME NULL DEFAULT NULL,
			follow_up_result_code VARCHAR(20) NOT NULL DEFAULT '',
			follow_up_response_code VARCHAR(20) NOT NULL DEFAULT '',
			follow_up_status_flag VARCHAR(20) NOT NULL DEFAULT '',
			follow_up_support_reference_id VARCHAR(64) NOT NULL DEFAULT '',
			follow_up_transaction_id VARCHAR(64) NOT NULL DEFAULT '',
			follow_up_transaction_at DATETIME NULL DEFAULT NULL,
			follow_up_payment_method VARCHAR(32) NOT NULL DEFAULT '',
			follow_up_iris_transaction_id VARCHAR(100) NOT NULL DEFAULT '',
			follow_up_iris_status VARCHAR(32) NOT NULL DEFAULT '',
			resolution_source VARCHAR(20) NOT NULL DEFAULT '',
			resolved_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY merchant_reference (merchant_reference),
			KEY follow_up_due (resolved_at, next_check_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Admin notice shown when WooCommerce is missing.
	 */
	public static function notice_requires_woocommerce(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo esc_html__(
			'ePay Paycenter for WooCommerce requires WooCommerce to be installed and active.',
			'resilient-gateway-for-epay-paycenter'
		);
		echo '</p></div>';
	}
}
