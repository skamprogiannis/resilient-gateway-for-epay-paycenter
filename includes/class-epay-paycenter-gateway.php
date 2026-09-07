<?php
/**
 * ePay Paycenter WooCommerce gateway.
 *
 * @package EpayPaycenter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Form-based redirect payment gateway for ePay Paycenter.
 */
class Epay_Paycenter_Gateway extends WC_Payment_Gateway {

	const FORM_POST_URL = 'https://paycenter.piraeusbank.gr/redirection/pay.aspx';

	/**
	 * Maximum number of concurrent OPEN payment attempts retained per order.
	 *
	 * Each render of the pay-for-order page issues a fresh TranTicket +
	 * MerchantReference + cancel token (see output_receipt_page()). We keep
	 * the most recent N so a callback that completes an EARLIER attempt still
	 * validates, while bounding order-meta growth from repeated reloads.
	 *
	 * @var int
	 */
	const MAX_OPEN_TICKETS = 5;

	/**
	 * Transaction type sent to the Ticketing Web Service. Always "02" (Sale):
	 * the transaction is settled in the next batch with no further merchant
	 * action.
	 *
	 * Preauthorization ("00") is deliberately NOT offered. Per Redirection
	 * Manual §4 a preauthorization only COMMITS the amount - it "must be
	 * completed by the merchant (via epay eCommerce AdminTool or a Web Service
	 * call) within the days defined via the ExpirePreauth parameter for the
	 * transaction to be settled". That completion call is
	 * `RequestType = "SETTLE"` against a separate Web Service whose technical
	 * specification the manual does not publish (it must be requested from
	 * Euronet Merchant Services).
	 *
	 * Without that specification the plugin cannot capture a preauthorization,
	 * so offering the option would mark orders as paid against funds that are
	 * never collected and silently expire. The setting was removed in 1.0.35;
	 * see the changelog. Do not reintroduce it without implementing capture.
	 *
	 * @var string
	 */
	const REQUEST_TYPE = '02';

	/**
	 * ExpirePreauth value sent to the Ticketing Web Service. The manual
	 * requires 0 for Sale (and digital wallet top up) transactions.
	 *
	 * @var string
	 */
	const EXPIRE_PREAUTH = '0';

	/**
	 * Marker prefix identifying a stored password as an MD5 digest rather
	 * than plaintext.
	 *
	 * The Ticketing Web Service only ever receives md5(password) - the
	 * plaintext has no use after the settings form is submitted, so it is
	 * not retained. Storing only the digest means a database leak, an
	 * options export or a stray debug plugin surrenders a credential scoped
	 * to this one integration instead of a password the merchant may have
	 * reused elsewhere.
	 *
	 * The prefix exists so the digest is unambiguously distinguishable from
	 * a legacy plaintext value: an installation upgraded from <= 1.0.35 still
	 * holds plaintext until the next settings save, and get_password_digest()
	 * transparently handles both.
	 *
	 * MD5 is mandated by the Redirection Manual for this field. It is not a
	 * choice, and the digest must be treated as a bearer credential.
	 *
	 * @var string
	 */
	const PASSWORD_DIGEST_PREFIX = 'md5:';

	/**
	 * Language code sent to Paycenter.
	 *
	 * @var string
	 */
	protected $language_code;

	/**
	 * Whether the gateway is in test / live mode. Purely an operational flag
	 * for UI; the endpoint URL is the same per the manual, but merchant
	 * credentials differ.
	 *
	 * @var string
	 */
	protected $mode;

	/**
	 * Callback / response handler.
	 *
	 * @var Epay_Paycenter_Handler
	 */
	protected $handler;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = EPAY_PAYCENTER_GATEWAY_ID;
		$this->has_fields         = true;
		$this->method_title       = __( 'ePay Paycenter (Piraeus Bank)', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );
		$this->method_description = __( 'Accept credit / debit card payments through the ePay Paycenter Redirection service (Piraeus Bank / Euronet Merchant Services). Customers are redirected to a secure payment page hosted by the bank.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );

		$this->supports = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title          = (string) $this->get_option( 'title' );
		$this->description    = (string) $this->get_option( 'description' );
		$this->mode           = (string) $this->get_option( 'mode', 'test' );
		$this->language_code  = (string) $this->get_option( 'language_code', 'en-US' );

		$this->handler = new Epay_Paycenter_Handler( $this );

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);
		add_action(
			'woocommerce_receipt_' . $this->id,
			array( $this, 'output_receipt_page' )
		);
		// NOTE: the `woocommerce_api_<id>` listener is intentionally NOT
		// registered here. It is bound at `plugins_loaded` time inside
		// Epay_Paycenter_Plugin::dispatch_api_callback() so that the
		// callback endpoint is always answerable even when WooCommerce
		// has not instantiated the gateway yet (which, if it happens,
		// causes WC_API::handle_api_requests() to fall through to its
		// hard-coded `die( '-1' )` tail). Routing the dispatch through
		// the bootstrapper removes that race entirely.
	}

	/**
	 * Expose the Paycenter handler created in the constructor.
	 *
	 * Used by the plugin bootstrapper to dispatch WC-API callbacks
	 * without having to reach into a private property.
	 *
	 * @return Epay_Paycenter_Handler
	 */
	public function get_handler() {
		return $this->handler;
	}

	/**
	 * Render payment fields shown when this method is selected at checkout.
	 *
	 * Outputs the accepted card-brands image followed by the merchant-
	 * configured description. The image is displayed below the payment
	 * method title (inside the content area that opens when the radio
	 * button is selected) rather than inline in the label, so it can
	 * render at its natural width (~400 px) and scale responsively on
	 * mobile viewports.
	 */
	public function payment_fields() {
		$icon_url = apply_filters(
			'epay_paycenter_icon',
			EPAY_PAYCENTER_PLUGIN_URL . 'assets/img/wp-cards.png'
		);

		if ( $icon_url ) {
			echo '<div class="epay-paycenter-icon">'
				. '<img src="' . esc_url( $icon_url ) . '"'
				. ' alt="' . esc_attr__( 'Accepted card brands', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ) . '"'
				. ' class="epay-paycenter-icon__img"'
				. ' />'
				. '</div>';
		}

		if ( $this->description ) {
			echo '<p>' . wp_kses_post( $this->description ) . '</p>';
		}

		$this->render_installments_picker();
	}

	/**
	 * Render the installments selector on the classic WooCommerce
	 * checkout. The select element submits as `epay_installments` in
	 * the checkout POST and is captured server-side in
	 * `process_payment()` where it is *always* re-clamped against the
	 * tier configuration evaluated for the actual order total. The
	 * client-side HTML is therefore strictly cosmetic — tampering
	 * with the dropdown options in DevTools cannot bypass the merchant
	 * policy.
	 *
	 * WooCommerce Blocks checkout: this method is not invoked by the
	 * Blocks framework (Blocks renders the payment method via JS).
	 * Blocks-checkout customers receive the merchant-configured maximum
	 * if reachable via tier rules — see class-epay-paycenter-blocks.php
	 * for the Blocks-side picker (added in a follow-up release).
	 *
	 * @return void
	 */
	private function render_installments_picker() {
		if ( 'yes' !== $this->get_option( 'installments' ) ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return;
		}

		$cart_total = (float) WC()->cart->get_total( 'edit' );
		$max        = $this->compute_max_installments( $cart_total );
		if ( $max < 2 ) {
			return;
		}

		echo '<p class="form-row form-row-wide epay-installments-picker">';
		echo '<label for="epay_installments">' . esc_html__( 'Installments (interest-free)', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ) . '</label>';
		echo '<select name="epay_installments" id="epay_installments" class="epay-installments-picker__select">';
		for ( $i = 1; $i <= $max; $i++ ) {
			if ( 1 === $i ) {
				$label = __( 'One-time payment', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );
			} else {
				$label = sprintf(
					/* translators: %d: number of monthly installments selected by the customer. */
					_n( '%d installment', '%d installments', $i, 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					$i
				);
			}
			printf(
				'<option value="%1$d">%2$s</option>',
				(int) $i,
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '</p>';
	}

	/**
	 * Define gateway settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable / Disable', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable ePay Paycenter payments', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown at checkout.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'default'     => __( 'Credit / Debit Card (Piraeus Bank)', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Description', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'type'        => 'textarea',
				'description' => __( 'Description shown at checkout.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'default'     => __( 'Pay securely with your credit or debit card. You will be redirected to the Piraeus Bank secure payment page to complete your order.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			),
			'mode'           => array(
				'title'       => __( 'Environment', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'type'        => 'select',
				'description' => __( 'Use the test environment when validating test transactions. Credentials for test and live accounts are different and provided by Euronet Merchant Services.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'default'     => 'test',
				'options'     => array(
					'test' => __( 'Test account', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'live' => __( 'Live account', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				),
				'desc_tip'    => true,
			),
			'acquirer_id'    => array(
				'title'       => __( 'Acquirer ID', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'type'        => 'text',
				'description' => __( 'Numeric AcquirerId provided by Euronet Merchant Services.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'desc_tip'    => true,
			),
			'merchant_id'    => array(
				'title'       => __( 'Merchant ID', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'type'        => 'text',
				'description' => __( 'Numeric MerchantId provided by Euronet Merchant Services.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'desc_tip'    => true,
			),
			'pos_id'         => array(
				'title'       => __( 'POS ID', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'type'        => 'text',
				'description' => __( 'Numeric PosId provided by Euronet Merchant Services.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'desc_tip'    => true,
			),
			'username'       => array(
				'title'       => __( 'Username', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'type'        => 'text',
				'description' => __( 'Username for the Ticketing Web Service (max. 50 characters).', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'desc_tip'    => true,
			),
			'password'       => array(
				'title'       => __( 'Password', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'type'        => 'password',
				'description' => __( 'Password for the Ticketing Web Service, as issued by Euronet Merchant Services. Only its MD5 digest is stored — the specification requires the digest to be what is transmitted, so the plain password is never written to the database. The field therefore always displays empty: leave it blank to keep the current credential, or type a new password to replace it.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'desc_tip'    => true,
				'placeholder' => __( 'Leave blank to keep the saved password', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			),
			'language_code'  => array(
				'title'       => __( 'Payment page language', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'type'        => 'select',
				'default'     => 'en-US',
				'options'     => array(
					'el-GR' => __( 'Greek', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'en-US' => __( 'English', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'ru-RU' => __( 'Russian', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'de-DE' => __( 'German', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				),
			),
			'installments'   => array(
				'title'       => __( 'Installments support', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'type'        => 'checkbox',
				'label'       => __( 'Offer installments when allowed by the merchant agreement.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'default'     => 'no',
				'description' => __( 'Requires activation by Euronet Merchant Services. Installments are not supported for IRIS payments.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			),
			'max_installments' => array(
				'title'       => __( 'Maximum installments', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'type'        => 'number',
				'default'     => 0,
				'custom_attributes' => array(
					'min' => 0,
					'max' => 36,
				),
				'description' => __( 'Maximum number of installments offered to the customer. Use 0 or 1 to disable.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			),
			'min_amount_for_installments' => array(
				'title'       => __( 'Minimum order total for installments', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'type'        => 'number',
				'default'     => 0,
				'custom_attributes' => array(
					'min'  => 0,
					'step' => '0.01',
				),
				'description' => __( 'Orders below this amount will not offer installments.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			),
			'installments_tiers' => array(
				'title'       => __( 'Tiered max installments by amount (interest-free)', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'type'        => 'textarea',
				'default'     => '',
				'placeholder' => '50:3, 100:6, 200:12',
				'description' => __( 'Optional tiered configuration. Format: "amount:max,amount:max,...". Example: "50:3, 100:6, 200:12" means orders >= 50 offer up to 3 installments, >= 100 up to 6, >= 200 up to 12. Per Piraeus Bank policy these installments are always interest-free for the customer (the merchant absorbs the bank commission). When this field is filled, it overrides the flat Maximum installments value above. Empty = use the flat maximum.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			),
			'debug'          => array(
				'title'   => __( 'Logging', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable debug logging to WooCommerce → Status → Logs (source: epay-paycenter). Credentials are never logged.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * Render the gateway settings screen with a branded, grouped layout.
	 *
	 * Output lives inside WooCommerce's existing `<form method="post">`
	 * wrapper on the Payments settings tab; save is handled by the default
	 * `process_admin_options()` hook registered in the constructor, so all
	 * POST fields (keyed by `woocommerce_{$id}_{$field}`) continue to be
	 * sanitised and persisted by WooCommerce core.
	 */
	public function admin_options() {
		// Defence-in-depth: WooCommerce already gates this screen behind
		// `manage_woocommerce`, but re-check here so rendering never runs
		// for unprivileged callers if the method is invoked directly.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$sections  = $this->get_field_sections();
		$mode      = (string) $this->get_option( 'mode', 'test' );
		$is_live      = ( 'live' === $mode );
		$version      = defined( 'EPAY_PAYCENTER_VERSION' ) ? EPAY_PAYCENTER_VERSION : '';
		$logo_url     = EPAY_PAYCENTER_PLUGIN_URL . 'assets/img/epay.jpg';
		?>
		<div class="epay-admin">

			<header class="epay-admin__header" role="banner">
				<div class="epay-admin__logo" aria-hidden="true">
					<img src="<?php echo esc_url( $logo_url ); ?>" alt="" width="180" height="60" />
				</div>
				<div class="epay-admin__heading">
					<h2 class="epay-admin__title">
						<?php echo esc_html__( 'ePay Paycenter for WooCommerce', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
						<?php if ( '' !== $version ) : ?>
							<span class="epay-admin__version">v<?php echo esc_html( $version ); ?></span>
						<?php endif; ?>
					</h2>
					<p class="epay-admin__subtitle">
						<?php echo esc_html__( 'Accept credit and debit card payments through the Piraeus Bank / Euronet Merchant Services secure Redirection service.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
					</p>
				</div>
			</header>

			<div class="epay-admin__grid">

				<div class="epay-admin__main">

				<?php foreach ( $sections as $section_key => $section ) : ?>
						<?php
						$section_fields = $this->subset_fields( $section['fields'] );
						if ( empty( $section_fields ) ) {
							continue;
						}
						$section_id = 'epay-section-' . sanitize_html_class( $section_key );
						?>
						<section class="epay-card" aria-labelledby="<?php echo esc_attr( $section_id . '-title' ); ?>">
							<div class="epay-card__header">
								<span class="dashicons dashicons-<?php echo esc_attr( $section['icon'] ); ?>" aria-hidden="true"></span>
								<h3 id="<?php echo esc_attr( $section_id . '-title' ); ?>" class="epay-card__title">
									<?php echo esc_html( $section['label'] ); ?>
								</h3>
								<?php if ( 'credentials' === $section_key ) : ?>
									<span class="epay-env-badge epay-env-badge--<?php echo esc_attr( $is_live ? 'live' : 'test' ); ?>">
										<?php echo esc_html( $is_live ? __( 'Live account', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ) : __( 'Test account', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ) ); ?>
									</span>
								<?php endif; ?>
							</div>
							<div class="epay-card__body">
								<?php if ( ! empty( $section['intro'] ) ) : ?>
									<p class="description"><?php echo esc_html( $section['intro'] ); ?></p>
								<?php endif; ?>
								<table class="form-table" role="presentation">
									<?php
									// generate_settings_html() is a WooCommerce core method that
								// internally escapes every field value with esc_attr() / esc_html().
								// All field definitions come from our hard-coded init_form_fields()
								// map, not from user input. wp_kses_post() cannot be used here
								// because it strips WooCommerce-specific attributes (data-tip, etc.)
								// that break the admin UI.
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								echo $this->generate_settings_html( $section_fields, false );
									?>
								</table>
							</div>
						</section>

						<?php
						// After the merchant-credentials section, render the
						// bank-integration data (collapsed by default to keep
						// the screen compact) followed by the callback
						// diagnostics card. These two cards are tied to the
						// onboarding handshake with Euronet, so they live
						// directly under the credentials they complement.
						if ( 'credentials' === $section_key ) {
							$this->render_bank_integration_card();
							$this->render_callback_diagnostics_card();
						}
						?>
					<?php endforeach; ?>

				</div>

				<aside class="epay-admin__sidebar" aria-label="<?php echo esc_attr__( 'Integration resources', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>">

					<section class="epay-card" aria-labelledby="epay-resources-title">
						<div class="epay-card__header">
							<span class="dashicons dashicons-sos" aria-hidden="true"></span>
							<h3 id="epay-resources-title" class="epay-card__title">
								<?php echo esc_html__( 'Resources', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
							</h3>
						</div>
						<div class="epay-card__body">
							<ul class="epay-links">
								<li>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ); ?>">
										<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
										<?php echo esc_html__( 'WooCommerce → Status → Logs', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
									</a>
								</li>
								<li>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&_payment_method=' . rawurlencode( EPAY_PAYCENTER_GATEWAY_ID ) ) ); ?>">
										<span class="dashicons dashicons-cart" aria-hidden="true"></span>
										<?php echo esc_html__( 'Orders paid via Paycenter', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
									</a>
								</li>
								<li>
									<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">
										<span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span>
										<?php echo esc_html__( 'Installed plugins', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
									</a>
								</li>
							</ul>
						</div>
					</section>

					<section class="epay-card" aria-labelledby="epay-about-title">
						<div class="epay-card__header">
							<span class="dashicons dashicons-info" aria-hidden="true"></span>
							<h3 id="epay-about-title" class="epay-card__title">
								<?php echo esc_html__( 'About this plugin', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
							</h3>
						</div>
						<div class="epay-card__body">
							<p class="epay-footnote">
								<?php echo esc_html__( 'Implements the official ePay Paycenter Redirection v2.9 specification: SOAP ticketing, HMAC-SHA256 response verification, HPOS and Checkout Blocks support.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
							</p>
							<p class="epay-footnote">
								<?php echo esc_html__( 'Published under GPL-2.0-or-later by WebHosting4U. "ePay", "Paycenter" and the Piraeus Bank payment mark are trademarks of Piraeus Bank S.A. / Euronet Merchant Services.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
							</p>
						</div>
					</section>

				</aside>

			</div>
		</div>
		<?php
	}

	/**
	 * Logical grouping of settings fields used by `admin_options()`. The
	 * keys reference entries in `$this->form_fields` so the canonical
	 * schema (and save path via `process_admin_options()`) stays single-
	 * sourced in `init_form_fields()`.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_field_sections() {
		return array(
			'general'      => array(
				'label'  => __( 'General', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'icon'   => 'admin-settings',
				'fields' => array( 'enabled', 'title', 'description' ),
			),
			'credentials'  => array(
				'label'  => __( 'Merchant credentials', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'icon'   => 'lock',
				'intro'  => __( 'Credentials are provided by Euronet Merchant Services. Test and live accounts use separate credential sets.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'fields' => array( 'mode', 'acquirer_id', 'merchant_id', 'pos_id', 'username', 'password' ),
			),
			'payment'      => array(
				'label'  => __( 'Payment behaviour', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'icon'   => 'cart',
				'fields' => array( 'language_code' ),
			),
			'installments' => array(
				'label'  => __( 'Installments', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'icon'   => 'chart-line',
				'intro'  => __( 'Activation by Euronet Merchant Services is required. Installments are interest-free for the customer (the merchant absorbs the bank commission). Installments are not available for IRIS payments.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'fields' => array( 'installments', 'max_installments', 'min_amount_for_installments', 'installments_tiers' ),
			),
			'advanced'     => array(
				'label'  => __( 'Advanced & logging', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'icon'   => 'admin-tools',
				'fields' => array( 'debug' ),
			),
		);
	}

	/**
	 * Return a whitelisted subset of `form_fields` keyed by the supplied
	 * field keys, preserving the order given.
	 *
	 * @param array<int,string> $keys Field keys.
	 * @return array<string,array<string,mixed>>
	 */
	private function subset_fields( array $keys ) {
		$subset = array();
		foreach ( $keys as $key ) {
			if ( is_string( $key ) && isset( $this->form_fields[ $key ] ) ) {
				$subset[ $key ] = $this->form_fields[ $key ];
			}
		}
		return $subset;
	}

	/**
	 * Build the "Bank integration data" block rendered on the settings
	 * screen. These are the exact values Euronet Merchant Services /
	 * Piraeus Bank ask for when issuing / activating a merchant record
	 * (Section 3 of the Redirection v2.9 specification: Website URL,
	 * Referrer URL, Success URL, Failure URL, Backlink URL, IP address
	 * and Response method).
	 *
	 * Values are derived from the live WordPress / WooCommerce
	 * configuration - none come from user input.
	 *
	 * @return array<string,mixed>
	 */
	private function collect_bank_integration_data() {
		$home     = home_url( '/' );
		$callback = add_query_arg( 'wc-api', EPAY_PAYCENTER_GATEWAY_ID, home_url( '/' ) );
		$checkout = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : $home;

		// `$_SERVER['SERVER_ADDR']` is the local address WordPress is bound
		// to. Behind a reverse proxy / load balancer this is NOT necessarily
		// the outbound IP seen by Paycenter, which is why it is rendered
		// with an explicit caveat in the UI. Value is filtered to IPv4 /
		// IPv6 characters before echo.
		$server_addr = '';
		if ( isset( $_SERVER['SERVER_ADDR'] ) && is_scalar( $_SERVER['SERVER_ADDR'] ) ) {
			// Two-stage sanitization: WordPress `sanitize_text_field()` to
			// strip control chars / tags / extra whitespace, then a strict
			// IPv4 / IPv6 character allow-list to guarantee the value is
			// safe for display.
			$raw_addr    = sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_ADDR'] ) );
			$server_addr = (string) preg_replace( '/[^0-9a-fA-F:.]/', '', $raw_addr );
			$server_addr = (string) substr( $server_addr, 0, 45 );
		}

		$urls = array(
			'website'  => array(
				'label' => __( 'Website URL', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'value' => $home,
				'hint'  => __( 'Your site origin. This is the URL from which test or live transactions are initiated.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			),
			'referrer' => array(
				'label' => __( 'Referrer URL', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'value' => $checkout,
				'hint'  => __( 'Page that initiates the payment. Paycenter receives the POST from the auto-submit form rendered on the order pay page that follows checkout.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			),
			'success'  => array(
				'label' => __( 'Success URL', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'value' => $callback,
				'hint'  => __( 'Paycenter posts the successful transaction response to this URL. The plugin verifies it via HMAC-SHA256 HashKey before marking the order paid.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			),
			'failure'  => array(
				'label' => __( 'Failure URL', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'value' => $callback,
				'hint'  => __( 'Paycenter posts a failed-transaction response to this URL. The plugin handler uses ResultCode / StatusFlag to flag the order as failed.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			),
			'backlink' => array(
				'label' => __( 'Backlink URL', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'value' => $callback,
				'hint'  => __( 'URL the customer is sent to when pressing "Cancel" on the Paycenter page. The plugin appends a per-order token automatically via the ParamBackLink field at ticket creation.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			),
		);

		// The address ePay / Euronet must register is the one this server uses
		// on its OUTBOUND call to the Ticketing Web Service. `SERVER_ADDR` above
		// answers a different question - the address the site is SERVED on - and
		// behind a reverse proxy, load balancer, NAT or on a multi-IP host the
		// two differ. Reported from the field: a host serving on x.x.x.150 while
		// egressing from x.x.x.167. Handing the bank the inbound address makes
		// every ticket request fail with no error the merchant can interpret.
		//
		// The plugin deliberately does not guess. Only a request to an outside
		// host can reveal the egress address, and this build makes no such call,
		// so the row is left empty with instructions rather than filled with a
		// confident wrong value - an empty field prompts action, a wrong one
		// gets copied straight into the bank's form. A managed host or agency
		// that knows the value can publish it through the filter below.
		$outbound_ip = apply_filters( 'epay_paycenter_outbound_ip', '' );
		$outbound_ip = is_scalar( $outbound_ip ) ? trim( (string) $outbound_ip ) : '';

		// Reject anything that is not a syntactically valid address outright.
		// The character allow-list applied to SERVER_ADDR above would instead
		// STRIP the offending characters, turning a malformed filter return into
		// a string that still looks like an IP (e.g. '<script>1.2.3.4' becomes
		// 'cae1c1.2.3.4'). This value exists to be copied into the bank's form,
		// so a wrong-but-plausible address is the worst possible output: better
		// to show nothing and let the instructions below take over.
		if ( '' !== $outbound_ip && ! filter_var( $outbound_ip, FILTER_VALIDATE_IP ) ) {
			$outbound_ip = '';
		}

		$server = array(
			'outbound_ip' => array(
				'detected' => ( '' !== $outbound_ip ),
				'label' => __( 'Outbound IP (the value ePay needs)', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'value' => ( '' !== $outbound_ip ) ? $outbound_ip : __( 'Not detected automatically', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'hint'  => __( 'The address this server uses when it calls the Paycenter ticketing service, and the one to register with Euronet Merchant Services. WordPress cannot read it, because only a request to an outside host reveals it. To find it, run  curl -4 https://api.ipify.org  on the server over SSH, or ask your hosting provider for the outbound (egress) IP. Hosts and developers can publish it here with the epay_paycenter_outbound_ip filter.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			),
			'ip_address' => array(
				'label' => __( 'Website IP (not the value for ePay)', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'value' => ( '' !== $server_addr ) ? $server_addr : __( 'Not detected', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'hint'  => __( 'The address this site is served on, as reported by WordPress. Behind a reverse proxy, load balancer, NAT or on a server with more than one IP, this is not the address that reaches Paycenter. Do not give this value to the bank unless your hosting provider has confirmed that the incoming and outgoing addresses are the same.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			),
			'response'   => array(
				'label' => __( 'Response method', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'value' => __( 'POST (recommended). GET is also accepted.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				'hint'  => __( 'Method Paycenter uses to deliver the transaction response to the Success / Failure URLs. The plugin handler accepts both; POST is the recommended choice on the merchant form.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			),
		);

		return array(
			'urls'   => $urls,
			'server' => $server,
		);
	}

	/**
	 * Detect whether the current admin request is served through
	 * Cloudflare. The three checks below are all on request headers that
	 * Cloudflare itself injects at the edge, so an attacker forging them
	 * at the origin cannot cause a false positive that would have any
	 * security impact - the only effect of a positive is that we render
	 * an informational notice on the settings page.
	 *
	 * @return bool
	 */
	public function is_cloudflare_proxied() {
		if ( isset( $_SERVER['HTTP_CF_RAY'] ) && is_scalar( $_SERVER['HTTP_CF_RAY'] ) && '' !== $_SERVER['HTTP_CF_RAY'] ) {
			return true;
		}
		if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && is_scalar( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && '' !== $_SERVER['HTTP_CF_CONNECTING_IP'] ) {
			return true;
		}
		if ( isset( $_SERVER['HTTP_CDN_LOOP'] ) && is_scalar( $_SERVER['HTTP_CDN_LOOP'] ) ) {
			$cdn_loop = strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_CDN_LOOP'] ) ) );
			if ( 'cloudflare' === $cdn_loop ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fetch the current Cloudflare IPv4 ranges from the official URL and
	 * cache them in a transient so we only issue one outbound request
	 * per 12 hours. Failures are negative-cached for 15 minutes.
	 *
	 * Security notes:
	 *   - The URL is a hardcoded HTTPS literal (`https://www.cloudflare.com/ips-v4/`);
	 *     no user input participates in constructing it, which satisfies
	 *     the SSRF prevention rule forbidding user-controlled target URLs.
	 *   - `wp_safe_remote_get()` is used (not `wp_remote_get()`), so the
	 *     WordPress HTTP API rejects requests that resolve to private /
	 *     internal IP ranges via `wp_http_validate_url()`.
	 *   - Each line of the response body is validated against a strict
	 *     IPv4 CIDR regex and anything that does not match is discarded,
	 *     so the cached data cannot contain arbitrary remote content.
	 *
	 * @return string[] List of IPv4 CIDR strings; empty on failure.
	 */
	private function fetch_cloudflare_ipv4_ranges() {
		$cache_key = 'epay_paycenter_cf_ipv4_v1';
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_safe_remote_get(
			'https://www.cloudflare.com/ips-v4/',
			array(
				'timeout'     => 3,
				'redirection' => 2,
				'user-agent'  => 'secure-card-gateway-for-epay-paycenter-piraeus-bank/' . EPAY_PAYCENTER_VERSION,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $cache_key, array(), 15 * MINUTE_IN_SECONDS );
			return array();
		}

		$body  = (string) wp_remote_retrieve_body( $response );
		$lines = preg_split( '/[\r\n]+/', $body );
		$cidrs = array();
		if ( is_array( $lines ) ) {
			foreach ( $lines as $line ) {
				$line = trim( (string) $line );
				if ( '' === $line ) {
					continue;
				}
				// Strict IPv4 CIDR validation - defensive against an
				// unexpected response body (e.g. an HTML error page)
				// being cached as if it were an IP list.
				if ( preg_match( '/^(?:\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $line ) ) {
					$cidrs[] = $line;
				}
			}
		}

		set_transient( $cache_key, $cidrs, 12 * HOUR_IN_SECONDS );
		return $cidrs;
	}

	/**
	 * Whether the gateway is properly configured.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( ! $this->has_credentials() ) {
			return false;
		}
		$currency = get_woocommerce_currency();
		if ( ! Epay_Paycenter_Currencies::is_supported( $currency ) ) {
			return false;
		}
		return parent::is_available();
	}

	/**
	 * Sanitise the password field on save, storing only its MD5 digest.
	 *
	 * WooCommerce resolves `validate_{$key}_field` before `validate_{$type}_field`,
	 * and here both resolve to this method (the field is named `password` and
	 * typed `password`), so every save path goes through it.
	 *
	 * @param string $key   Field key.
	 * @param string $value Raw submitted value.
	 * @return string Prefixed digest to persist.
	 */
	public function validate_password_field( $key, $value ) {
		$value = trim( (string) wp_unslash( $value ) );

		// The field always renders empty because a digest cannot be shown
		// back to the merchant, so an empty submission means "leave the
		// stored credential alone" rather than "clear it". Without this,
		// saving any unrelated setting would wipe the password.
		if ( '' === $value ) {
			return (string) $this->get_option( 'password' );
		}

		// Idempotence: a value that already carries the marker is a digest
		// being round-tripped, not a password to hash a second time.
		if ( 0 === strpos( $value, self::PASSWORD_DIGEST_PREFIX ) ) {
			return $value;
		}

		return self::PASSWORD_DIGEST_PREFIX . md5( $value );
	}

	/**
	 * Return the MD5 digest to send as the Ticketing Web Service `Password`.
	 *
	 * Handles both storage formats: the prefixed digest written since 1.0.36,
	 * and the legacy plaintext left behind by an installation upgraded from
	 * an earlier version, which is hashed on the fly and converted the next
	 * time settings are saved.
	 *
	 * @return string 32-character MD5 digest, or an empty string when unset.
	 */
	private function get_password_digest() {
		$stored = (string) $this->get_option( 'password' );

		if ( '' === $stored ) {
			return '';
		}

		if ( 0 === strpos( $stored, self::PASSWORD_DIGEST_PREFIX ) ) {
			return substr( $stored, strlen( self::PASSWORD_DIGEST_PREFIX ) );
		}

		return md5( $stored );
	}

	/**
	 * Render the password field as write-only.
	 *
	 * Overrides the WooCommerce core renderer purely to force `value=""`.
	 * The stored value is a digest, so echoing it back would put an opaque
	 * `md5:...` string in the input and invite a merchant to "correct" it.
	 *
	 * @param string              $key  Field key.
	 * @param array<string,mixed> $data Field definition.
	 * @return string
	 */
	public function generate_password_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'             => '',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => array(),
		);
		$data      = wp_parse_args( $data, $defaults );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
				<?php
				// get_tooltip_html() / get_description_html() / get_custom_attribute_html()
				// are WooCommerce core helpers that build already-escaped markup.
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $this->get_tooltip_html( $data );
				?>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
					<input
						class="input-text regular-input <?php echo esc_attr( $data['class'] ); ?>"
						type="password"
						name="<?php echo esc_attr( $field_key ); ?>"
						id="<?php echo esc_attr( $field_key ); ?>"
						style="<?php echo esc_attr( $data['css'] ); ?>"
						value=""
						autocomplete="new-password"
						placeholder="<?php echo esc_attr( $data['placeholder'] ); ?>"
						<?php disabled( $data['disabled'], true ); ?>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo $this->get_custom_attribute_html( $data );
						?>
					/>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $this->get_description_html( $data );
					?>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Check that all required API credentials are set.
	 *
	 * @return bool
	 */
	private function has_credentials() {
		return (
			'' !== $this->get_option( 'acquirer_id' )
			&& '' !== $this->get_option( 'merchant_id' )
			&& '' !== $this->get_option( 'pos_id' )
			&& '' !== $this->get_option( 'username' )
			&& '' !== $this->get_option( 'password' )
		);
	}

	/**
	 * Accessor for AcquirerId (used by the handler for hash verification).
	 *
	 * @return int
	 */
	public function get_acquirer_id() {
		return (int) $this->get_option( 'acquirer_id' );
	}

	/**
	 * Accessor for PosId.
	 *
	 * @return int
	 */
	public function get_pos_id() {
		return (int) $this->get_option( 'pos_id' );
	}

	/**
	 * Whether a Paycenter response describes an IRIS transaction.
	 *
	 * Per Redirection Manual v2.9 §5 (response receipt) IRIS is identified
	 * by either of two bank-supplied fields:
	 *   - `CardType` = 15  (numeric channel code)
	 *   - `PaymentMethod` = "IRIS" (string label)
	 * Both come from the HMAC-verified callback payload. Checking both
	 * fields defensively covers the case where Paycenter populates only
	 * one for a given response variant; the values themselves cannot be
	 * spoofed because the callback is authenticated by the HashKey
	 * HMAC-SHA256 check before this helper is consulted.
	 *
	 * @param string $card_type      Value of the `CardType` response field.
	 * @param string $payment_method Value of the `PaymentMethod` response field.
	 * @return bool True when the response represents an IRIS payment.
	 */
	public static function is_iris_payment_method( $card_type, $payment_method ) {
		$card_type      = (string) $card_type;
		$payment_method = strtoupper( (string) $payment_method );
		return ( '15' === $card_type ) || ( 'IRIS' === $payment_method );
	}

	/**
	 * Parse the tiered-installments configuration string into a sorted
	 * list of (min_amount, max_installments) rules.
	 *
	 * Input format is operator-friendly: comma-separated `amount:max`
	 * pairs, optional whitespace, e.g. "50:3, 100:6, 200:12". Each
	 * segment is validated against a strict regex so an admin who
	 * fat-fingers a value (or, in the worst case, an attacker who
	 * compromises a manage_woocommerce account and pastes a hostile
	 * payload) cannot inject anything beyond integers / decimals into
	 * the resulting structure. Malformed segments are silently dropped
	 * instead of throwing — the surrounding callers default to the
	 * flat `max_installments` setting when the tier list is empty.
	 *
	 * Caps:
	 *   - amount  : 0..999_999 (matches the bank's two-decimal-digit Amount field)
	 *   - max     : 1..36       (defensive upper bound; bank docs reference up to ~36)
	 *
	 * @param mixed $tiers_string Raw setting value as stored.
	 * @return array<int,array{min_amount:float,max_installments:int}>
	 */
	public static function parse_installments_tiers( $tiers_string ) {
		$result = array();
		if ( ! is_string( $tiers_string ) || '' === trim( $tiers_string ) ) {
			return $result;
		}
		$segments = explode( ',', $tiers_string );
		foreach ( $segments as $segment ) {
			$segment = trim( $segment );
			if ( '' === $segment ) {
				continue;
			}
			if ( ! preg_match( '/^\s*(\d{1,6}(?:\.\d{1,2})?)\s*:\s*(\d{1,2})\s*$/', $segment, $matches ) ) {
				continue;
			}
			$amount = (float) $matches[1];
			$max    = (int) $matches[2];
			if ( $amount < 0 || $max < 1 || $max > 36 ) {
				continue;
			}
			$result[] = array(
				'min_amount'       => $amount,
				'max_installments' => $max,
			);
		}
		usort(
			$result,
			static function ( $a, $b ) {
				return $a['min_amount'] <=> $b['min_amount'];
			}
		);
		return $result;
	}

	/**
	 * Return the maximum installments allowed for a given order amount,
	 * honouring (in order of precedence) the tier configuration, the
	 * minimum-order-total threshold, and the flat maximum.
	 *
	 * Guaranteed return range: 1..36. A return of 1 means "no
	 * installments offered" (i.e. one-time payment), which is the
	 * value the bank's Ticketing parameter takes for non-installment
	 * transactions per Redirection Manual v2.9 §4.
	 *
	 * Always call this server-side before sending the Ticketing
	 * request — the customer-supplied installments dropdown value
	 * must be clamped to whatever this method returns so the
	 * merchant's policy is enforced even if the dropdown HTML is
	 * tampered with client-side.
	 *
	 * @param float|int|string $amount Order or cart total.
	 * @return int Maximum installments allowed, in the range 1..36.
	 */
	public function compute_max_installments( $amount ) {
		$amount = (float) $amount;
		if ( 'yes' !== $this->get_option( 'installments' ) ) {
			return 1;
		}

		$tiers = self::parse_installments_tiers( (string) $this->get_option( 'installments_tiers' ) );
		if ( ! empty( $tiers ) ) {
			$max = 1;
			foreach ( $tiers as $tier ) {
				if ( $amount >= $tier['min_amount'] ) {
					$max = $tier['max_installments'];
				} else {
					break;
				}
			}
			return max( 1, min( 36, $max ) );
		}

		// Fall back to the flat configuration.
		$min_amount = (float) $this->get_option( 'min_amount_for_installments' );
		if ( $amount < $min_amount ) {
			return 1;
		}
		$flat_max = (int) $this->get_option( 'max_installments' );
		return max( 1, min( 36, $flat_max ) );
	}

	/**
	 * Tells WooCommerce to send the customer to an intermediate 'Pay for order'
	 * page which renders the auto-submitted redirect form.
	 *
	 * @param int $order_id Order.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order could not be loaded.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// Capture the customer-picked installment count (classic checkout
		// dropdown) and clamp it server-side against the merchant's
		// tier policy evaluated for the live order total. The HTML
		// dropdown values are cosmetic — only this server-side clamp
		// is authoritative. WooCommerce verified the checkout nonce
		// upstream before dispatching to process_payment(), so the
		// $_POST read here is from an authenticated checkout submission.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$picked = isset( $_POST['epay_installments'] ) && is_scalar( $_POST['epay_installments'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			? absint( wp_unslash( $_POST['epay_installments'] ) )
			: 1;
		$max_allowed   = $this->compute_max_installments( (float) $order->get_total() );
		$installments  = max( 1, min( $picked, $max_allowed ) );
		$order->update_meta_data( '_epay_installments', $installments );

		$order->update_status( 'pending', __( 'Awaiting Paycenter payment.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ) );

		$redirect_url = $order->get_checkout_payment_url( true );

		return array(
			'result'   => 'success',
			'redirect' => $redirect_url,
		);
	}

	/**
	 * Render the receipt / redirect page. This is where we actually call the
	 * ticketing Web Service and output the auto-submit form.
	 *
	 * @param int $order_id Order.
	 */
	public function output_receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ) . '</p>';
			return;
		}

		$merchant_reference = $this->build_merchant_reference( $order );
		$ticket             = $this->request_ticket( $order, $merchant_reference );

		if ( ! $ticket['success'] ) {
			$this->render_ticket_error( $order, $ticket );
			return;
		}

		$order->update_meta_data( '_epay_tran_ticket', $ticket['tran_ticket'] );
		$order->update_meta_data( '_epay_merchant_reference', $merchant_reference );

		$cancel_token = wp_generate_password( 32, false, false );
		$order->update_meta_data( '_epay_cancel_token', $cancel_token );

		// Record this attempt in the bounded open-ticket set. The single
		// `_epay_*` metas above still hold the latest attempt for audit
		// parity and backward compatibility, but the set is what the callback
		// handler validates against: it lets a callback that completes an
		// EARLIER attempt (a second browser tab, or the Back button issuing a
		// fresh ticket) match its own reference + TranTicket instead of only
		// the most recent one - the case where the card was charged but the
		// order was left `pending` on a MerchantReference mismatch.
		self::store_open_ticket( $order, $merchant_reference, $ticket['tran_ticket'], $cancel_token );

		$order->save();

		$this->record_ticket_log( $order, $ticket['tran_ticket'], $merchant_reference );

		// SECURITY: TranTicket is deliberately NOT included in this form.
		// Redirection Manual v2.9 §4 states the ticket "in no case may [it]
		// be visible to the user (e.g. not to be transferred via hidden
		// parameters to an html form)", and the sample form in Annex 1 omits
		// it. The ticket is the secret HMAC-SHA256 key used to authenticate
		// the callback (see Epay_Paycenter_Hash); exposing it in the browser
		// would let a customer forge a successful-payment callback for their
		// own order. It is kept server-side only, in the `_epay_tran_ticket`
		// order meta written above. The bank identifies the transaction by
		// MerchantReference plus the merchant credentials registered during
		// the IssueNewTicket call, not by a ticket echoed in this form.
		$form_fields = array(
			'AcquirerId'        => (string) $this->get_acquirer_id(),
			'MerchantId'        => (string) $this->get_option( 'merchant_id' ),
			'PosId'              => (string) $this->get_pos_id(),
			'User'               => (string) $this->get_option( 'username' ),
			'LanguageCode'       => $this->language_code,
			'MerchantReference'  => $merchant_reference,
			'ParamBackLink'      => $this->build_param_back_link( $order, $cancel_token ),
		);

		$template = EPAY_PAYCENTER_PLUGIN_DIR . 'templates/redirect-form.php';
		if ( file_exists( $template ) ) {
			$post_url = self::FORM_POST_URL;

			$version = defined( 'EPAY_PAYCENTER_VERSION' ) ? EPAY_PAYCENTER_VERSION : false;
			wp_enqueue_script(
				'epay-paycenter-redirect',
				EPAY_PAYCENTER_PLUGIN_URL . 'assets/js/epay-paycenter-redirect.js',
				array(),
				$version,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);

			include $template; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		}
	}

	/**
	 * Build the merchant reference used across ticketing and form post.
	 *
	 * The manual restricts the character set to Greek / Latin alphanumerics,
	 * space, and the specials /:_().,+-. It must be unique per successful
	 * transaction and up to 50 characters long.
	 *
	 * We intentionally use the numeric WooCommerce order ID (optionally
	 * prefixed with a site-specific attempt token) rather than the display
	 * order number so the merchant reference is always a stable, unambiguous
	 * identifier that can be reversed into an order on the callback, even
	 * when other plugins override `get_order_number()`.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function build_merchant_reference( $order ) {
		$reference = (string) $order->get_id();
		// Include a short random suffix so retried payments on the same
		// order generate a new unique reference (the manual requires unique
		// reference per successful transaction, but retries after a failure
		// should also be traceable).
		// SECURITY: this suffix is the per-order shared secret. It is what
		// stops an unauthenticated client from driving another order's
		// callback handling by guessing a sequential order id, and on the
		// documented decline path (where Paycenter sends an empty HashKey)
		// it is the ONLY secret gating the request.
		//
		// wp_generate_password() draws from a uniform 62-symbol alphabet, but
		// upper-casing collapses each letter pair onto one symbol, cutting the
		// per-character entropy from 5.95 to ~5.12 bits. The fold is kept
		// because the reference is compared byte-for-byte against the value
		// echoed back by the bank and must survive any case normalisation
		// applied in transit; the length is raised instead, which is safe
		// regardless. 12 characters gives ~61 bits post-fold (was ~31 at 6).
		// The manual allows 50 characters; this uses at most 12 plus the
		// order id and separator.
		//
		// Filtering happens before the length is taken so a stripped
		// character cannot silently shorten the secret.
		$suffix = strtoupper( wp_generate_password( 24, false, false ) );
		$suffix = substr( preg_replace( '/[^A-Z0-9]/', '', $suffix ), 0, 12 );
		$reference .= '-' . $suffix;
		return substr( $reference, 0, 50 );
	}

	/**
	 * Build the ParamBackLink query string used for the Paycenter Cancel
	 * button. The merchant's configured Backlink URL will receive these
	 * parameters.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $token Cancel token.
	 * @return string
	 */
	private function build_param_back_link( $order, $token ) {
		$params = array(
			'wc-api'     => EPAY_PAYCENTER_GATEWAY_ID,
			'epp_action' => 'cancel',
			'order_id'   => $order->get_id(),
			'token'      => $token,
		);
		return http_build_query( $params );
	}

	/**
	 * Append a freshly issued payment attempt to the order's bounded set of
	 * OPEN attempts, keyed by MerchantReference.
	 *
	 * Each entry carries the two per-attempt secrets: the TranTicket (the
	 * HMAC-SHA256 key the callback is verified against) and the cancel token.
	 * Keying by reference lets the handler validate a callback / cancel link
	 * that belongs to ANY still-open attempt, not just the most recent one.
	 * The set is capped at MAX_OPEN_TICKETS so repeated pay-page reloads
	 * cannot grow order meta without bound and stale secrets do not linger.
	 *
	 * @param WC_Order $order        Order.
	 * @param string   $reference    MerchantReference for this attempt.
	 * @param string   $tran_ticket  TranTicket (HMAC key) for this attempt.
	 * @param string   $cancel_token Per-attempt cancel token.
	 * @return void
	 */
	public static function store_open_ticket( $order, $reference, $tran_ticket, $cancel_token ) {
		$reference = (string) $reference;
		if ( '' === $reference ) {
			return;
		}
		$map = $order->get_meta( '_epay_open_tickets', true );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		// Re-issue in place so a repeated reference moves to the newest slot
		// instead of creating a duplicate.
		unset( $map[ $reference ] );
		$map[ $reference ] = array(
			'ticket' => (string) $tran_ticket,
			'cancel' => (string) $cancel_token,
		);
		if ( count( $map ) > self::MAX_OPEN_TICKETS ) {
			// Keep the most recent entries; PHP preserves insertion order so
			// slicing from the tail drops the oldest attempts first.
			$map = array_slice( $map, -self::MAX_OPEN_TICKETS, null, true );
		}
		$order->update_meta_data( '_epay_open_tickets', $map );
	}

	/**
	 * Return the order's OPEN payment attempts as a
	 * `reference => array{ticket:string,cancel:string}` map.
	 *
	 * Orders whose ticket was issued before this set existed (or a payment
	 * in flight across the plugin upgrade) carry only the legacy single-value
	 * meta. That pair is folded into the returned map so every caller sees a
	 * single uniform structure and no in-flight payment is stranded by the
	 * upgrade.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,array{ticket:string,cancel:string}>
	 */
	public static function get_open_tickets( $order ) {
		$map = $order->get_meta( '_epay_open_tickets', true );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		$legacy_ref = (string) $order->get_meta( '_epay_merchant_reference', true );
		if ( '' !== $legacy_ref && ! isset( $map[ $legacy_ref ] ) ) {
			$map[ $legacy_ref ] = array(
				'ticket' => (string) $order->get_meta( '_epay_tran_ticket', true ),
				'cancel' => (string) $order->get_meta( '_epay_cancel_token', true ),
			);
		}
		return $map;
	}

	/**
	 * Constant-time membership check for a cancel token against every OPEN
	 * attempt's cancel token (legacy single value included).
	 *
	 * The whole set is scanned without an early return so the lookup time
	 * does not reveal which attempt matched; each comparison is itself
	 * timing-safe via hash_equals().
	 *
	 * @param WC_Order $order Order.
	 * @param string   $token Token supplied on the cancel backlink.
	 * @return bool
	 */
	public static function verify_cancel_token( $order, $token ) {
		$token = (string) $token;
		if ( '' === $token ) {
			return false;
		}
		$matched = false;
		foreach ( self::get_open_tickets( $order ) as $data ) {
			$cancel = isset( $data['cancel'] ) ? (string) $data['cancel'] : '';
			if ( '' !== $cancel && hash_equals( $cancel, $token ) ) {
				$matched = true;
			}
		}
		return $matched;
	}

	/**
	 * Clear every OPEN attempt once the order is settled, removing the
	 * plaintext TranTicket(s) promptly after successful verification. The
	 * legacy single-value `_epay_tran_ticket` is dropped too; the
	 * `_epay_merchant_reference` is deliberately retained for audit parity.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function clear_open_tickets( $order ) {
		$order->delete_meta_data( '_epay_open_tickets' );
		$order->delete_meta_data( '_epay_tran_ticket' );
	}

	/**
	 * Insert a minimal accounting record for the ticket so administrators can
	 * audit ticketing activity without inspecting order meta. Tran ticket
	 * itself is stored as a SHA-256 digest only - the plaintext lives only in
	 * order meta until verification, then it is deleted.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $tran_ticket Ticket.
	 * @param string   $reference   Merchant reference.
	 */
	private function record_ticket_log( $order, $tran_ticket, $reference ) {
		global $wpdb;
		$table = $wpdb->prefix . 'epay_paycenter_tickets';

		$now = gmdate( 'Y-m-d H:i:s' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->insert(
			$table,
			array(
				'order_id'          => (int) $order->get_id(),
				'merchant_reference'=> $reference,
				'tran_ticket_hash'  => hash( 'sha256', $tran_ticket ),
				'acquirer_id'       => $this->get_acquirer_id(),
				'merchant_id'       => (int) $this->get_option( 'merchant_id' ),
				'pos_id'            => $this->get_pos_id(),
				'amount'            => wc_format_decimal( $order->get_total(), 2 ),
				'currency_code'     => (int) Epay_Paycenter_Currencies::to_numeric( $order->get_currency() ),
				'installments'      => (int) max( 1, (int) $order->get_meta( '_epay_installments', true ) ),
				'request_type'      => self::REQUEST_TYPE,
				'status'            => 'pending',
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		// phpcs:enable
	}

	/**
	 * Request a TranTicket from the Paycenter SOAP service for an order.
	 *
	 * @param WC_Order $order              Order.
	 * @param string   $merchant_reference Reference.
	 * @return array Ticket result.
	 */
	private function request_ticket( $order, $merchant_reference ) {
		$currency_numeric = Epay_Paycenter_Currencies::to_numeric( $order->get_currency() );
		if ( null === $currency_numeric ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %s currency code */
					__( 'Currency %s is not supported by Paycenter.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					$order->get_currency()
				),
				'result_code' => '',
				'description' => '',
			);
		}

		// Installments: read from order meta if the customer picked a
		// value during checkout, and re-clamp against the merchant's
		// tier policy for the live order total. Defence-in-depth: even
		// though process_payment() already clamped before storing, the
		// tier setting (or order total) may have changed between the
		// classic-checkout submit and the pay-for-order redirect. The
		// ticket request is the last point before the bank, so any
		// drift gets corrected here too. A value of 1 is the bank's
		// canonical "no installments" per Manual v2.9 §4.
		$picked_installments = (int) $order->get_meta( '_epay_installments', true );
		if ( $picked_installments < 1 ) {
			$picked_installments = 1;
		}
		$max_for_order  = $this->compute_max_installments( (float) $order->get_total() );
		$installments   = max( 1, min( $picked_installments, $max_for_order ) );

		$request = array(
			'Username'          => (string) $this->get_option( 'username' ),
			'Password'          => $this->get_password_digest(),
			'MerchantId'        => (string) $this->get_option( 'merchant_id' ),
			'PosId'              => (string) $this->get_pos_id(),
			'AcquirerId'        => (string) $this->get_acquirer_id(),
			'MerchantReference' => $merchant_reference,
			'RequestType'       => self::REQUEST_TYPE,
			'ExpirePreauth'     => self::EXPIRE_PREAUTH,
			'Amount'            => wc_format_decimal( $order->get_total(), 2 ),
			'Installments'      => (string) $installments,
			'CurrencyCode'      => (string) $currency_numeric,
			'Bnpl'              => '0',
			// Echoed back by Paycenter, signed with HashKey. We put the
			// numeric order id here so callbacks can be reliably mapped to
			// a WooCommerce order even if a third-party plugin overrides
			// the display order number. The manual allows up to 512 chars.
			'Parameters'        => 'wc_order_id=' . (int) $order->get_id(),
		);

		$this->append_address_fields( $request, $order );

		$logger_payload = $request;
		unset( $logger_payload['Password'] );
		if ( 'yes' === $this->get_option( 'debug' ) ) {
			Epay_Paycenter_Logger::debug( 'IssueNewTicket request', $logger_payload );
		}

		$client = new Epay_Paycenter_Ticketing();
		$result = $client->issue_ticket( $request );

		// Ticket issuance is the only call that carries the merchant
		// credentials, so an authentication failure can surface here and
		// nowhere else. Annex 6 ResultCode 100 means the Username and/or
		// Password are wrong, which fails every checkout until it is fixed.
		//
		// A success clears the alert unconditionally: a ticket cannot be
		// issued unless the credentials authenticated, so it is proof the
		// outage is over. Only code 100 raises it - a transport error or an
		// HTTP failure is transient and says nothing about the credentials.
		if ( ! empty( $result['success'] ) ) {
			Epay_Paycenter_Plugin::clear_credentials_error();
		} elseif ( '100' === (string) $result['result_code'] ) {
			Epay_Paycenter_Plugin::flag_credentials_error( (string) $result['description'] );
			Epay_Paycenter_Logger::error(
				'Paycenter rejected the stored credentials (ResultCode 100). Card payments are failing for every customer until the Username / Password on the gateway settings screen are corrected.',
				array( 'description' => (string) $result['description'] )
			);
		}

		if ( 'yes' === $this->get_option( 'debug' ) ) {
			Epay_Paycenter_Logger::debug( 'IssueNewTicket response', Epay_Paycenter_Logger::redact( $result ) );
		}

		return $result;
	}

	/**
	 * Append 3-D Secure billing / shipping / cardholder fields to the
	 * ticketing request.
	 *
	 * @param array    $request Reference to request to modify.
	 * @param WC_Order $order   Order.
	 */
	private function append_address_fields( array &$request, $order ) {
		$email = sanitize_email( (string) $order->get_billing_email() );
		if ( '' !== $email ) {
			$request['Email'] = substr( $email, 0, 254 );
		}

		$cardholder = $this->sanitize_cardholder(
			trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() )
		);
		if ( '' !== $cardholder ) {
			$request['CardholderName'] = $cardholder;
		}

		$billing_country = $order->get_billing_country();
		$billing_numeric = Epay_Paycenter_Countries::to_numeric( $billing_country );
		if ( $billing_numeric ) {
			$request['BillAddrCountry']  = $billing_numeric;
			$request['BillAddrCity']     = $this->sanitize_address( $order->get_billing_city() );
			$request['BillAddrLine1']    = $this->sanitize_address( $order->get_billing_address_1() );
			$request['BillAddrLine2']    = $this->sanitize_address( $order->get_billing_address_2() );
			$request['BillAddrPostCode'] = substr( preg_replace( '/[^A-Za-z0-9 -]/', '', (string) $order->get_billing_postcode() ), 0, 16 );
			$request['BillAddrState']    = Epay_Paycenter_Countries::map_state( $billing_country, (string) $order->get_billing_state() );
		}

		if ( $order->has_shipping_address() ) {
			$shipping_country = $order->get_shipping_country();
			$shipping_numeric = Epay_Paycenter_Countries::to_numeric( $shipping_country );
			if ( $shipping_numeric ) {
				$request['ShipAddrCountry']  = $shipping_numeric;
				$request['ShipAddrCity']     = $this->sanitize_address( $order->get_shipping_city() );
				$request['ShipAddrLine1']    = $this->sanitize_address( $order->get_shipping_address_1() );
				$request['ShipAddrLine2']    = $this->sanitize_address( $order->get_shipping_address_2() );
				$request['ShipAddrPostCode'] = substr( preg_replace( '/[^A-Za-z0-9 -]/', '', (string) $order->get_shipping_postcode() ), 0, 16 );
				$request['ShipAddrState']    = Epay_Paycenter_Countries::map_state( $shipping_country, (string) $order->get_shipping_state() );
			}
		}

		// WooCommerce collects a single billing phone with no indication of
		// what kind of line it is, and every number was previously sent as
		// MobilePhone. The Ticketing service exposes MobilePhone, HomePhone
		// and WorkPhone as distinct 3-D Secure inputs, and the issuer's risk
		// engine reads them as distinct facts: a landline filed as a mobile is
		// not enrichment, it is a wrong answer, and wrong answers are what
		// push a transaction out of the frictionless flow. Route it instead.
		$phone = $this->format_phone( $order->get_billing_phone(), $billing_country );
		if ( '' !== $phone ) {
			$request[ $this->phone_field_for( $phone, $billing_country ) ] = $phone;
		}

		// BillAddrLine3, ShipAddrLine3 and WorkPhone have no WooCommerce
		// source at all: core has exactly two address lines and one billing
		// phone. That absence, not an oversight, is why they were never
		// populated - and inventing values for fields a fraud engine scores
		// would be worse than omitting them.
		//
		// A store whose checkout genuinely collects that data (a custom third
		// address line, a second phone) can supply it here. Everything the
		// filter returns is re-validated below against the same rules applied
		// to native data, so a filter cannot put spec-violating characters or
		// an over-long value into a request bound for the bank.
		$extra = apply_filters( 'epay_paycenter_3ds_fields', array(), $order );
		if ( is_array( $extra ) && ! empty( $extra ) ) {
			$this->merge_3ds_fields( $request, $extra, $billing_country );
		}
	}

	/**
	 * Decide which Ticketing phone field a formatted number belongs in.
	 *
	 * Only Greek numbering is classified. The Greek national plan reserves
	 * 69x for mobiles and 2xx for fixed lines, so the distinction is reliable.
	 * No such guarantee exists for the other calling codes this plugin maps,
	 * and a wrong guess is worse than the status quo, so everything else stays
	 * in MobilePhone exactly as before.
	 *
	 * @param string $formatted      Phone already in "CC-Number" form.
	 * @param string $country_alpha2 Billing country.
	 * @return string 'MobilePhone' or 'HomePhone'.
	 */
	private function phone_field_for( $formatted, $country_alpha2 ) {
		if ( 'GR' !== strtoupper( (string) $country_alpha2 ) ) {
			return 'MobilePhone';
		}

		$parts      = explode( '-', (string) $formatted, 2 );
		$subscriber = isset( $parts[1] ) ? $parts[1] : $parts[0];

		if ( 0 === strpos( $subscriber, '2' ) ) {
			return 'HomePhone';
		}

		return 'MobilePhone';
	}

	/**
	 * Merge filtered 3-D Secure fields into the request after validating them.
	 *
	 * Only the fields listed here are accepted, and each is put through the
	 * same sanitiser its native counterpart uses. Anything else the filter
	 * returns is discarded rather than forwarded: this request goes to the
	 * bank, and the manual constrains both the character set and the length of
	 * every one of these parameters.
	 *
	 * @param array  $request        Request being built, by reference.
	 * @param array  $extra          Raw values returned by the filter.
	 * @param string $country_alpha2 Billing country, for phone formatting.
	 */
	private function merge_3ds_fields( array &$request, array $extra, $country_alpha2 ) {
		$address_fields = array( 'BillAddrLine3', 'ShipAddrLine3' );
		$phone_fields   = array( 'HomePhone', 'MobilePhone', 'WorkPhone' );

		foreach ( $extra as $field => $value ) {
			if ( ! is_string( $field ) || ! is_scalar( $value ) ) {
				continue;
			}

			if ( in_array( $field, $address_fields, true ) ) {
				$clean = $this->sanitize_address( (string) $value );
			} elseif ( in_array( $field, $phone_fields, true ) ) {
				$clean = $this->format_phone( (string) $value, $country_alpha2 );
			} else {
				continue;
			}

			if ( '' !== $clean ) {
				$request[ $field ] = $clean;
			}
		}
	}

	/**
	 * Sanitize cardholder name per the manual (Latin + certain specials).
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private function sanitize_cardholder( $name ) {
		$name  = remove_accents( (string) $name );
		$name  = preg_replace( '/["\'`;<>]/', '', $name );
		$name  = preg_replace( '/\s+/', ' ', $name );
		$name  = trim( (string) $name );
		if ( '' === $name ) {
			return '';
		}
		$name = substr( $name, 0, 45 );
		if ( strlen( $name ) < 2 ) {
			return '';
		}
		return $name;
	}

	/**
	 * Sanitize free-form address text to the character set allowed by
	 * Paycenter address fields.
	 *
	 * @param string $text Address part.
	 * @return string
	 */
	private function sanitize_address( $text ) {
		$text = (string) $text;
		$text = preg_replace( '#[^\p{L}\p{N} /:_().,+\-]#u', '', $text );
		$text = preg_replace( '/\s+/', ' ', (string) $text );
		$text = trim( (string) $text );
		return substr( (string) $text, 0, 50 );
	}

	/**
	 * Format a phone number as "CC-Number" expected by Paycenter for 3DS.
	 *
	 * @param string $phone          Phone.
	 * @param string $country_alpha2 Country code.
	 * @return string
	 */
	private function format_phone( $phone, $country_alpha2 ) {
		$phone = preg_replace( '/[^\d+]/', '', (string) $phone );
		if ( '' === $phone ) {
			return '';
		}

		// WooCommerce country code to common calling codes (subset).
		$calling_codes = array(
			'GR' => '30', 'CY' => '357', 'GB' => '44', 'DE' => '49', 'FR' => '33',
			'IT' => '39', 'ES' => '34', 'US' => '1', 'CA' => '1', 'BG' => '359',
			'RO' => '40', 'AT' => '43', 'BE' => '32', 'NL' => '31', 'PL' => '48',
			'PT' => '351', 'IE' => '353', 'SE' => '46', 'NO' => '47', 'DK' => '45',
			'FI' => '358', 'CH' => '41', 'CZ' => '420', 'HU' => '36', 'SK' => '421',
		);

		$cc = isset( $calling_codes[ strtoupper( $country_alpha2 ) ] ) ? $calling_codes[ strtoupper( $country_alpha2 ) ] : '';

		if ( 0 === strpos( $phone, '+' ) ) {
			$phone = substr( $phone, 1 );
			// If the number starts with the detected cc, separate with dash.
			if ( '' !== $cc && 0 === strpos( $phone, $cc ) ) {
				$subscriber = substr( $phone, strlen( $cc ) );
				return substr( $cc, 0, 3 ) . '-' . substr( (string) $subscriber, 0, 15 );
			}
			// Fallback: emit as-is with no separator, but limit total length.
			return substr( $phone, 0, 19 );
		}

		if ( '' === $cc ) {
			return substr( $phone, 0, 19 );
		}

		$subscriber = ltrim( $phone, '0' );
		return substr( $cc, 0, 3 ) . '-' . substr( $subscriber, 0, 15 );
	}

	/**
	 * Output a user-facing error when ticketing fails.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $result Ticket result.
	 */
	private function render_ticket_error( $order, array $result ) {
		$note = sprintf(
			/* translators: 1: result code, 2: description */
			__( 'Paycenter ticketing failed. Result code: %1$s. Description: %2$s.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
			$result['result_code'],
			$result['description']
		);
		$order->add_order_note( $note );
		$order->update_status( 'failed' );
		$order->save();

		echo '<div class="woocommerce-error" role="alert"><p>';
		echo esc_html__( 'We could not start a secure card payment at the moment. Please try again later or choose a different payment method.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );
		echo '</p></div>';

		echo '<p><a class="button" href="' . esc_url( wc_get_checkout_url() ) . '">';
		echo esc_html__( 'Return to checkout', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' );
		echo '</a></p>';
	}

	/**
	 * AJAX: self-test the WC-API callback URL for host-WAF interception.
	 *
	 * Fired from the "Test callback URL" button on the settings screen.
	 * Performs an authenticated, same-origin POST from the WordPress server
	 * to the plugin's own `?wc-api=epay_paycenter` endpoint using a
	 * synthetic Paycenter "declined transaction" payload. The payload is
	 * made up entirely of static / generated values - it carries no
	 * credentials and cannot modify any order state (MerchantReference is a
	 * synthetic WAFTEST-... string that will not match any order, so the
	 * handler short-circuits to a 302 redirect back to the checkout page).
	 *
	 * Expected healthy outcome: HTTP 302 from our own handler (or 200 with
	 * empty body). WAF interception typically manifests as HTTP 403 / 406 /
	 * 501 before the request reaches PHP. The response body / interesting
	 * headers (Server, CF-Ray, X-Sucuri-ID, X-Imunify360-WAF, etc.) are
	 * passed back to the browser so the merchant can identify which WAF is
	 * blocking and craft a narrow exclusion.
	 *
	 * Security:
	 *  - Capability-gated on `manage_woocommerce`.
	 *  - Nonce-gated via `check_ajax_referer()`.
	 *  - No user input participates in the target URL (built from
	 *    `home_url()` + a static WC-API query arg), so this is not an SSRF
	 *    vector: the request can only ever loop back to this same site.
	 *  - No sensitive data (credentials, TranTicket, HashKey, cardholder
	 *    data) is transmitted in the synthetic payload.
	 *  - Response headers echoed back to the browser are filtered through
	 *    a hard-coded allow-list (Server / CF-Ray / X-Sucuri-ID / ...) so
	 *    arbitrary headers cannot be reflected.
	 *  - Response body is truncated to 500 chars, HTML-stripped, and
	 *    sanitized before being returned to the admin JS.
	 */
	public static function ajax_run_waf_test() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'forbidden',
					'message' => __( 'You do not have permission to run this diagnostic.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
				),
				403
			);
		}

		check_ajax_referer( 'epay_paycenter_waf_test', 'nonce' );

		// Target: plugin's own wc-api callback endpoint. Built entirely
		// from `home_url()` with a static query arg - no user input, so
		// this loopback POST cannot be pointed at any other host.
		$target = add_query_arg( 'wc-api', EPAY_PAYCENTER_GATEWAY_ID, home_url( '/' ) );

		// Synthetic payload that mirrors the shape of a Paycenter
		// "declined transaction" response (test case 2 in the manual).
		// Uses a generated WAFTEST-... MerchantReference so the handler
		// will cleanly fail the order lookup and short-circuit to a
		// redirect, without touching any real order state.
		$payload = array(
			'SupportReferenceID'  => 'WAFTEST-' . wp_generate_password( 8, false, false ),
			'ResultCode'          => '981',
			'ResultDescription'   => 'Invalid Card number/Exp Month/Exp Year',
			'StatusFlag'          => 'Failure',
			'ResponseCode'        => '0',
			'ResponseDescription' => 'Invalid Card number/Exp Month/Exp Year',
			'LanguageCode'        => 'en',
			'MerchantReference'   => 'WAFTEST-' . wp_generate_password( 12, false, false ),
			'TransactionDateTime' => '-',
			'Parameters'          => 'wc_order_id=0',
		);

		$args = array(
			'method'      => 'POST',
			'timeout'     => 10,
			'redirection' => 0,
			'httpversion' => '1.1',
			// Mirrors the stock wp_remote_post loopback behaviour: allow
			// self-signed certs on dev / staging environments the same
			// way core does for cron / REST loopbacks. Production sites
			// with a valid certificate will still verify normally.
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
			'blocking'    => true,
			'headers'     => array(
				'Content-Type'               => 'application/x-www-form-urlencoded',
				'User-Agent'                 => 'EpayPaycenter-WAFSelfTest/' . ( defined( 'EPAY_PAYCENTER_VERSION' ) ? EPAY_PAYCENTER_VERSION : '0' ),
				'X-Epay-Paycenter-Self-Test' => '1',
			),
			'body'        => http_build_query( $payload, '', '&', PHP_QUERY_RFC1738 ),
			'cookies'     => array(),
		);

		$response = wp_remote_post( $target, $args );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array(
					'code'       => 'transport',
					'message'    => __( 'The self-test POST could not be issued from this server. This usually means the WordPress host cannot reach its own public URL (DNS / loopback / TLS issue). Paycenter callbacks from the bank are a separate ingress path and may still work, but this self-test cannot validate them without loopback connectivity.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
					'detail'     => $response->get_error_message(),
					'target_url' => $target,
				),
				500
			);
		}

		$status      = (int) wp_remote_retrieve_response_code( $response );
		$headers_obj = wp_remote_retrieve_headers( $response );
		$all_headers = array();
		if ( is_object( $headers_obj ) && method_exists( $headers_obj, 'getAll' ) ) {
			$all_headers = (array) $headers_obj->getAll();
		} elseif ( is_array( $headers_obj ) ) {
			$all_headers = $headers_obj;
		}

		// Hard-coded allow-list: only headers that are useful for
		// identifying the intercepting WAF / CDN are reflected back to
		// the browser. Any other response header is silently dropped.
		$header_allow_list = array(
			'server',
			'x-powered-by',
			'cf-ray',
			'cf-mitigated',
			'x-firewall',
			'x-sucuri-id',
			'x-sucuri-cache',
			'x-bitninja-status',
			'x-imunify360-waf',
			'x-imunify360-captcha',
			'x-lsadc-cache',
			'x-litespeed-cache',
			'x-mod-security',
			'x-xss-protection',
			'content-type',
			'location',
		);
		$safe_headers = array();
		foreach ( $header_allow_list as $name ) {
			if ( isset( $all_headers[ $name ] ) ) {
				$value              = is_array( $all_headers[ $name ] )
					? implode( ', ', array_map( 'strval', $all_headers[ $name ] ) )
					: (string) $all_headers[ $name ];
				$safe_headers[ $name ] = sanitize_text_field( $value );
			}
		}

		$body_raw     = (string) wp_remote_retrieve_body( $response );
		$body_snippet = function_exists( 'mb_substr' )
			? mb_substr( $body_raw, 0, 500, 'UTF-8' )
			: substr( $body_raw, 0, 500 );
		$body_snippet = wp_strip_all_tags( $body_snippet );
		$body_snippet = sanitize_text_field( $body_snippet );

		$verdict = 'pass';
		$blocked = false;
		if ( 403 === $status || 406 === $status || 501 === $status ) {
			$verdict = 'blocked';
			$blocked = true;
		} elseif ( $status >= 500 && $status <= 599 ) {
			$verdict = 'server_error';
		} elseif ( 0 === $status ) {
			$verdict = 'no_response';
		}

		wp_send_json_success(
			array(
				'verdict'      => $verdict,
				'blocked'      => $blocked,
				'status'       => $status,
				'target_url'   => $target,
				'headers'      => $safe_headers,
				'body_snippet' => $body_snippet,
			)
		);
	}

	/**
	 * Render the "Bank integration data" card. Standard card layout
	 * (matches the credentials / payment / installments cards) — the
	 * Cloudflare detection sub-block inside it remains collapsible
	 * because it is the heaviest and most conditional piece.
	 *
	 * @return void
	 */
	private function render_bank_integration_card() {
		$bank_data = $this->collect_bank_integration_data();
		?>
		<section class="epay-card" aria-labelledby="epay-bankdata-title">
			<div class="epay-card__header">
				<span class="dashicons dashicons-bank" aria-hidden="true"></span>
				<h3 id="epay-bankdata-title" class="epay-card__title">
					<?php echo esc_html__( 'Bank integration data', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
				</h3>
				<span class="epay-card__subtitle">
					<?php echo esc_html__( 'Values to submit to Euronet Merchant Services', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
				</span>
			</div>
			<div class="epay-card__body">
					<div class="epay-techdata">

						<p class="epay-techdata__notice">
							<?php echo esc_html__( 'Copy the following values into the technical data form provided by Euronet Merchant Services / Piraeus Bank when requesting test or live credentials. Values are generated from this WordPress installation and will change if the site URL moves.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
						</p>

						<?php
						// Combine all three callback URLs into a single grey
						// textarea with one Copy button. Labels come from
						// collect_bank_integration_data() so the existing
						// Greek translations are reused verbatim (no new
						// translatable strings introduced for the URL labels
						// themselves). The Copy button reuses the existing
						// .epay-copy__btn handler which copies the target
						// element's value/innerText — same wiring as the
						// Cloudflare CIDR list below.
						$url_lines = array();
						foreach ( $bank_data['urls'] as $row ) {
							// $row['label'] and $row['value'] come from the
							// trusted collect_bank_integration_data() builder
							// (server-side strings + esc_url'd values). They
							// are re-escaped at output time via esc_textarea.
							$url_lines[] = $row['label'] . ': ' . $row['value'];
						}
						$url_rows = max( 3, count( $bank_data['urls'] ) );
						?>
						<div class="epay-techdata__group epay-techdata__group--urls">
							<div class="epay-copy epay-copy--block">
								<textarea id="epay-td-urls" class="epay-copy__textarea" readonly rows="<?php echo (int) $url_rows; ?>"><?php echo esc_textarea( implode( "\n", $url_lines ) ); ?></textarea>
								<button type="button" class="epay-copy__btn" data-target="epay-td-urls" aria-label="<?php echo esc_attr__( 'Copy callback URLs to clipboard', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>">
									<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
									<span class="epay-copy__btn-label"><?php echo esc_html__( 'Copy', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?></span>
								</button>
							</div>
						</div>

						<div class="epay-techdata__group epay-techdata__group--split">

							<?php
							// Outbound IP first: it is the value the bank actually needs.
							// The Copy button is rendered ONLY when the value is real (i.e.
							// supplied via the epay_paycenter_outbound_ip filter). Offering
							// one-click copy next to a placeholder - or, as before, next to
							// the inbound address - is what led merchants to paste the wrong
							// IP into the Euronet portal and then see every ticket request
							// fail with nothing to diagnose.
							$out_row      = $bank_data['server']['outbound_ip'];
							$has_outbound = ! empty( $out_row['detected'] );
							?>
							<div class="epay-techdata__row">
								<?php if ( $has_outbound ) : ?>
									<label class="epay-techdata__label" for="epay-td-outip">
										<span class="dashicons dashicons-networking" aria-hidden="true"></span>
										<?php echo esc_html( $out_row['label'] ); ?>
									</label>
									<div class="epay-copy">
										<input id="epay-td-outip" class="epay-copy__input" type="text" readonly value="<?php echo esc_attr( $out_row['value'] ); ?>" />
										<button type="button" class="epay-copy__btn" data-target="epay-td-outip" aria-label="<?php echo esc_attr__( 'Copy IP address to clipboard', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>">
											<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
											<span class="epay-copy__btn-label"><?php echo esc_html__( 'Copy', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?></span>
										</button>
									</div>
								<?php else : ?>
									<span class="epay-techdata__label">
										<span class="dashicons dashicons-networking" aria-hidden="true"></span>
										<?php echo esc_html( $out_row['label'] ); ?>
									</span>
									<span class="epay-techdata__static"><?php echo esc_html( $out_row['value'] ); ?></span>
								<?php endif; ?>
								<p class="epay-techdata__hint"><?php echo esc_html( $out_row['hint'] ); ?></p>
							</div>

							<?php $ip_row = $bank_data['server']['ip_address']; ?>
							<div class="epay-techdata__row">
								<span class="epay-techdata__label">
									<span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
									<?php echo esc_html( $ip_row['label'] ); ?>
								</span>
								<span class="epay-techdata__static"><?php echo esc_html( $ip_row['value'] ); ?></span>
								<p class="epay-techdata__hint"><?php echo esc_html( $ip_row['hint'] ); ?></p>
							</div>

							<?php $resp_row = $bank_data['server']['response']; ?>
							<div class="epay-techdata__row">
								<span class="epay-techdata__label">
									<span class="dashicons dashicons-controls-repeat" aria-hidden="true"></span>
									<?php echo esc_html( $resp_row['label'] ); ?>
								</span>
								<span class="epay-techdata__static"><?php echo esc_html( $resp_row['value'] ); ?></span>
								<p class="epay-techdata__hint"><?php echo esc_html( $resp_row['hint'] ); ?></p>
							</div>

						</div>

						<?php if ( $this->is_cloudflare_proxied() ) : ?>
							<?php $cf_ranges = $this->fetch_cloudflare_ipv4_ranges(); ?>
							<details class="epay-techdata__notice epay-techdata__notice--cloudflare">
								<summary class="epay-techdata__notice-heading">
									<span class="dashicons dashicons-cloud" aria-hidden="true"></span>
									<?php echo esc_html__( 'Cloudflare detected on this site', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
								</summary>
								<p>
									<?php echo esc_html__( 'This store appears to be served through Cloudflare (a CF-Ray / CF-Connecting-IP header was detected on the current request). Requests from Paycenter to your Success / Failure / Backlink URLs therefore reach your site through Cloudflare edge IPs, not only from the web server address shown above. To avoid blocked callbacks, ask Euronet Merchant Services to whitelist the full Cloudflare IPv4 range in addition to your webserver IP.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
								</p>
								<p>
									<?php echo esc_html__( 'Contact the following addresses and request that the Cloudflare IPv4 ranges be added to your merchant record:', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
								</p>
								<ul class="epay-techdata__emails">
									<li>
										<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
										<a href="<?php echo esc_url( 'mailto:OnlineServices@epayworldwide.gr' ); ?>">OnlineServices@epayworldwide.gr</a>
									</li>
									<li>
										<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
										<a href="<?php echo esc_url( 'mailto:epayGreece@pds.gr' ); ?>">epayGreece@pds.gr</a>
									</li>
								</ul>
								<p>
									<?php
									echo wp_kses_post(
										sprintf(
											/* translators: %s: link to Cloudflare's official IPv4 list. */
											__( 'The authoritative Cloudflare IPv4 list is always published at %s and is updated by Cloudflare when their edge network changes.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ),
											'<a href="' . esc_url( 'https://www.cloudflare.com/ips-v4/' ) . '" target="_blank" rel="noopener noreferrer">cloudflare.com/ips-v4</a>'
										)
									);
									?>
								</p>

								<?php if ( ! empty( $cf_ranges ) ) : ?>
									<label class="epay-techdata__label" for="epay-td-cf-ipv4">
										<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
										<?php
										printf(
											/* translators: %d: number of CIDR ranges fetched. */
											esc_html( _n( 'Current Cloudflare IPv4 ranges (%d CIDR)', 'Current Cloudflare IPv4 ranges (%d CIDRs)', count( $cf_ranges ), 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ) ),
											(int) count( $cf_ranges )
										);
										?>
									</label>
									<div class="epay-copy epay-copy--block">
										<textarea id="epay-td-cf-ipv4" class="epay-copy__textarea" readonly rows="6"><?php echo esc_textarea( implode( "\n", $cf_ranges ) ); ?></textarea>
										<button type="button" class="epay-copy__btn" data-target="epay-td-cf-ipv4" aria-label="<?php echo esc_attr__( 'Copy Cloudflare IPv4 ranges to clipboard', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>">
											<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
											<span class="epay-copy__btn-label"><?php echo esc_html__( 'Copy all ranges', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?></span>
										</button>
									</div>
									<p class="epay-techdata__hint">
										<?php echo esc_html__( 'Fetched from Cloudflare and cached for 12 hours. Copy these CIDRs into the email alongside your webserver IP.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
									</p>
								<?php else : ?>
									<p class="epay-techdata__hint">
										<?php echo esc_html__( 'The live Cloudflare IPv4 list could not be fetched from this server right now. Please copy it manually from the link above.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
									</p>
								<?php endif; ?>
							</details>
						<?php endif; ?>

					</div>
				</div>
		</section>
		<?php
	}

	/**
	 * Render the "Callback diagnostics" card. Loopback POST probe that
	 * lets the merchant detect host-WAF interception of Paycenter
	 * failure callbacks before going live.
	 *
	 * @return void
	 */
	private function render_callback_diagnostics_card() {
		?>
		<section class="epay-card" aria-labelledby="epay-waftest-title">
			<div class="epay-card__header">
				<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
				<h3 id="epay-waftest-title" class="epay-card__title">
					<?php echo esc_html__( 'Callback diagnostics', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
				</h3>
				<span class="epay-card__subtitle">
					<?php echo esc_html__( 'Detect host-WAF interception before going live', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
				</span>
			</div>
			<div class="epay-card__body">
				<p class="epay-techdata__notice">
					<?php echo esc_html__( 'Some shared-hosting firewalls (cPFence, ModSecurity / OWASP CRS, Imunify360, BitNinja, LiteSpeed WAF) can intercept the Paycenter failure callbacks because their payloads contain patterns (dash-only TransactionDateTime, Greek error text, empty HashKey on declined transactions) that look like attack signatures. The test below issues a realistic declined-transaction POST from this WordPress server to the plugin\'s own callback URL and reports whether it is blocked before it reaches PHP. No order is created or modified; the synthetic payload carries a WAFTEST- merchant reference that no order in the database can match.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
				</p>
				<p class="epay-techdata__hint">
					<?php echo esc_html__( 'Scope: this exercises your local webserver / host WAF (ModSecurity, cPFence, Imunify360, BitNinja, LiteSpeed). CDN-level WAFs that sit in front of your origin (Cloudflare, Sucuri, Akamai) are not exercised by the loopback POST; their rules must be tested with an external probe.', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?>
				</p>
				<div class="epay-waftest__controls">
					<button type="button" class="button button-primary" id="epay-waftest-run">
						<span class="dashicons dashicons-update" aria-hidden="true"></span>
						<span class="epay-waftest__btn-label"><?php echo esc_html__( 'Test callback URL', 'secure-card-gateway-for-epay-paycenter-piraeus-bank' ); ?></span>
					</button>
				</div>
				<div class="epay-waftest__result" id="epay-waftest-result" role="status" aria-live="polite"></div>
			</div>
		</section>
		<?php
	}
}
