<?php
/**
 * WooCommerce gateway for ePay Paycenter.
 *
 * @package EpayPaycenter
 *
 * Modified by the fork contributors on 2026-08-01, 2026-08-09,
 * 2026-09-04, and 2026-09-07. See NOTICE.md for attribution.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Form-based redirect payment gateway for ePay Paycenter.
 *
 * @phpstan-import-type TicketResult from Epay_Paycenter_Ticketing
 * @phpstan-import-type TicketRequest from Epay_Paycenter_Ticketing
 * @phpstan-type FieldSection array{label:string,icon:string,intro?:string,fields:list<string>}
 * @phpstan-type BankDataRow array{label:string,value:string,hint:string,detected?:bool}
 */
class Epay_Paycenter_Gateway extends WC_Payment_Gateway {

	const FORM_POST_URL = 'https://paycenter.piraeusbank.gr/redirection/pay.aspx';

	/**
	 * Sale transactions settle in the next bank batch. Preauthorization requires
	 * a separate capture workflow, which this gateway does not implement.
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
	 * Distinguishes the bank-mandated MD5 digest from legacy plaintext settings.
	 * The digest remains a bearer credential and must never be logged.
	 *
	 * @var string
	 */
	const PASSWORD_DIGEST_PREFIX = Epay_Paycenter_Credentials::PASSWORD_DIGEST_PREFIX;

	/**
	 * Language code sent to Paycenter.
	 *
	 * @var string
	 */
	protected $language_code;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = EPAY_PAYCENTER_GATEWAY_ID;
		$this->has_fields         = true;
		$this->method_title       = __( 'ePay Paycenter (Piraeus Bank)', 'resilient-gateway-for-epay-paycenter' );
		$this->method_description = __( 'Accept credit / debit card payments through the ePay Paycenter Redirection service (Piraeus Bank / Euronet Merchant Services). Customers are redirected to a secure payment page hosted by the bank.', 'resilient-gateway-for-epay-paycenter' );

		$this->supports = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title         = (string) $this->get_option( 'title' );
		$this->description   = (string) $this->get_option( 'description' );
		$this->language_code = (string) $this->get_option( 'language_code', 'en-US' );

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			function (): void {
				$this->process_admin_options();
			}
		);
		add_action(
			'woocommerce_receipt_' . $this->id,
			array( $this, 'output_receipt_page' )
		);
		// The bootstrapper registers callbacks before WooCommerce creates gateways.
	}

	/**
	 * Render payment fields shown when this method is selected at checkout.
	 *
	 * A site may supply its own licensed icon through the
	 * `epay_paycenter_icon` filter. The default is text-only.
	 */
	public function payment_fields(): void {
		$icon_url = $this->filtered_icon_url();

		if ( $icon_url ) {
			echo '<div class="epay-paycenter-icon">'
				. '<img src="' . esc_url( $icon_url ) . '"'
				. ' alt="' . esc_attr__( 'Accepted card brands', 'resilient-gateway-for-epay-paycenter' ) . '"'
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
	 * Resolve a filtered icon to an absolute HTTP(S) URL.
	 *
	 * @return string
	 */
	private function filtered_icon_url() {
		$icon_url = apply_filters( 'epay_paycenter_icon', '' );
		if ( ! is_string( $icon_url ) || ! wp_http_validate_url( $icon_url ) ) {
			return '';
		}

		return esc_url_raw( $icon_url );
	}

	/**
	 * Render the classic-checkout installment selector. Its submitted value is
	 * clamped against the order total and merchant rules before requesting a ticket.
	 * Blocks uses a one-time payment without an installment selector.
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
		echo '<label for="epay_installments">' . esc_html__( 'Installments (interest-free)', 'resilient-gateway-for-epay-paycenter' ) . '</label>';
		echo '<select name="epay_installments" id="epay_installments" class="epay-installments-picker__select">';
		for ( $i = 1; $i <= $max; $i++ ) {
			if ( 1 === $i ) {
				$label = __( 'One-time payment', 'resilient-gateway-for-epay-paycenter' );
			} else {
				$label = sprintf(
					/* translators: %d: number of monthly installments selected by the customer. */
					_n( '%d installment', '%d installments', $i, 'resilient-gateway-for-epay-paycenter' ),
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
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'                     => array(
				'title'   => __( 'Enable / Disable', 'resilient-gateway-for-epay-paycenter' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable ePay Paycenter payments', 'resilient-gateway-for-epay-paycenter' ),
				'default' => 'no',
			),
			'title'                       => array(
				'title'       => __( 'Title', 'resilient-gateway-for-epay-paycenter' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown at checkout.', 'resilient-gateway-for-epay-paycenter' ),
				'default'     => __( 'Credit / Debit Card (Piraeus Bank)', 'resilient-gateway-for-epay-paycenter' ),
				'desc_tip'    => true,
			),
			'description'                 => array(
				'title'       => __( 'Description', 'resilient-gateway-for-epay-paycenter' ),
				'type'        => 'textarea',
				'description' => __( 'Description shown at checkout.', 'resilient-gateway-for-epay-paycenter' ),
				'default'     => __( 'Pay securely with your credit or debit card. You will be redirected to the Piraeus Bank secure payment page to complete your order.', 'resilient-gateway-for-epay-paycenter' ),
			),
			'mode'                        => array(
				'title'       => __( 'Environment', 'resilient-gateway-for-epay-paycenter' ),
				'type'        => 'select',
				'description' => __( 'Use the test environment when validating test transactions. Credentials for test and live accounts are different and provided by Euronet Merchant Services.', 'resilient-gateway-for-epay-paycenter' ),
				'default'     => 'test',
				'options'     => array(
					'test' => __( 'Test account', 'resilient-gateway-for-epay-paycenter' ),
					'live' => __( 'Live account', 'resilient-gateway-for-epay-paycenter' ),
				),
				'desc_tip'    => true,
			),
			'acquirer_id'                 => array(
				'title'       => __( 'Acquirer ID', 'resilient-gateway-for-epay-paycenter' ),
				'type'        => 'text',
				'description' => __( 'Numeric AcquirerId provided by Euronet Merchant Services.', 'resilient-gateway-for-epay-paycenter' ),
				'desc_tip'    => true,
			),
			'merchant_id'                 => array(
				'title'       => __( 'Merchant ID', 'resilient-gateway-for-epay-paycenter' ),
				'type'        => 'text',
				'description' => __( 'Numeric MerchantId provided by Euronet Merchant Services.', 'resilient-gateway-for-epay-paycenter' ),
				'desc_tip'    => true,
			),
			'pos_id'                      => array(
				'title'       => __( 'POS ID', 'resilient-gateway-for-epay-paycenter' ),
				'type'        => 'text',
				'description' => __( 'Numeric PosId provided by Euronet Merchant Services.', 'resilient-gateway-for-epay-paycenter' ),
				'desc_tip'    => true,
			),
			'username'                    => array(
				'title'       => __( 'Username', 'resilient-gateway-for-epay-paycenter' ),
				'type'        => 'text',
				'description' => __( 'Username for the Ticketing Web Service (max. 50 characters).', 'resilient-gateway-for-epay-paycenter' ),
				'desc_tip'    => true,
			),
			'password'                    => array(
				'title'       => __( 'Password', 'resilient-gateway-for-epay-paycenter' ),
				'type'        => 'password',
				'description' => __( 'Password for the Ticketing Web Service, as issued by Euronet Merchant Services. Only its MD5 digest is stored — the specification requires the digest to be what is transmitted, so the plain password is never written to the database. The field therefore always displays empty: leave it blank to keep the current credential, or type a new password to replace it.', 'resilient-gateway-for-epay-paycenter' ),
				'desc_tip'    => true,
				'placeholder' => __( 'Leave blank to keep the saved password', 'resilient-gateway-for-epay-paycenter' ),
			),
			'language_code'               => array(
				'title'   => __( 'Payment page language', 'resilient-gateway-for-epay-paycenter' ),
				'type'    => 'select',
				'default' => 'en-US',
				'options' => array(
					'el-GR' => __( 'Greek', 'resilient-gateway-for-epay-paycenter' ),
					'en-US' => __( 'English', 'resilient-gateway-for-epay-paycenter' ),
					'ru-RU' => __( 'Russian', 'resilient-gateway-for-epay-paycenter' ),
					'de-DE' => __( 'German', 'resilient-gateway-for-epay-paycenter' ),
				),
			),
			'installments'                => array(
				'title'       => __( 'Installments support', 'resilient-gateway-for-epay-paycenter' ),
				'type'        => 'checkbox',
				'label'       => __( 'Offer installments when allowed by the merchant agreement.', 'resilient-gateway-for-epay-paycenter' ),
				'default'     => 'no',
				'description' => __( 'Requires activation by Euronet Merchant Services. Installments are not supported for IRIS payments.', 'resilient-gateway-for-epay-paycenter' ),
			),
			'max_installments'            => array(
				'title'             => __( 'Maximum installments', 'resilient-gateway-for-epay-paycenter' ),
				'type'              => 'number',
				'default'           => 0,
				'custom_attributes' => array(
					'min' => 0,
					'max' => 36,
				),
				'description'       => __( 'Maximum number of installments offered to the customer. Use 0 or 1 to disable.', 'resilient-gateway-for-epay-paycenter' ),
			),
			'min_amount_for_installments' => array(
				'title'             => __( 'Minimum order total for installments', 'resilient-gateway-for-epay-paycenter' ),
				'type'              => 'number',
				'default'           => 0,
				'custom_attributes' => array(
					'min'  => 0,
					'step' => '0.01',
				),
				'description'       => __( 'Orders below this amount will not offer installments.', 'resilient-gateway-for-epay-paycenter' ),
			),
			'installments_tiers'          => array(
				'title'       => __( 'Tiered max installments by amount (interest-free)', 'resilient-gateway-for-epay-paycenter' ),
				'type'        => 'textarea',
				'default'     => '',
				'placeholder' => '50:3, 100:6, 200:12',
				'description' => __( 'Optional tiered configuration. Format: "amount:max,amount:max,...". Example: "50:3, 100:6, 200:12" means orders >= 50 offer up to 3 installments, >= 100 up to 6, >= 200 up to 12. Per Piraeus Bank policy these installments are always interest-free for the customer (the merchant absorbs the bank commission). When this field is filled, it overrides the flat Maximum installments value above. Empty = use the flat maximum.', 'resilient-gateway-for-epay-paycenter' ),
			),
			'follow_up_enabled'           => array(
				'title'       => __( 'Automatic payment recovery', 'resilient-gateway-for-epay-paycenter' ),
				'type'        => 'checkbox',
				'label'       => __( 'Use ePay FOLLOW_UP to reconcile missing callbacks', 'resilient-gateway-for-epay-paycenter' ),
				'default'     => 'no',
				'description' => __( 'Verify the channel above, then enable recovery and save. Only an exact approved bank response can mark an order paid.', 'resilient-gateway-for-epay-paycenter' ),
			),
			'follow_up_window_hours'      => array(
				'title'             => __( 'Recheck payments for (hours)', 'resilient-gateway-for-epay-paycenter' ),
				'type'              => 'number',
				'default'           => '48',
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => '168',
					'step' => '1',
				),
				'description'       => __( '1–168 hours from each payment attempt (default: 48). Applies to new and ongoing checks, not expired or reviewed cases. Technical failures may receive up to two extra hourly retries; WordPress scheduling can delay checks. Stock reservation is separate.', 'resilient-gateway-for-epay-paycenter' ),
			),
			'follow_up_hold_minutes'      => array(
				'title'             => __( 'ePay stock reservation', 'resilient-gateway-for-epay-paycenter' ),
				'type'              => 'number',
				'default'           => 240,
				'custom_attributes' => array(
					'min'  => 60,
					'max'  => 1440,
					'step' => 1,
				),
				'description'       => __( 'Minutes to reserve stock for unpaid ePay orders (default: 240 / four hours). Changing the recheck duration does not extend stock reservation.', 'resilient-gateway-for-epay-paycenter' ),
			),
			'debug'                       => array(
				'title'   => __( 'Logging', 'resilient-gateway-for-epay-paycenter' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable debug logging to WooCommerce → Status → Logs (source: epay-paycenter). Credentials are never logged.', 'resilient-gateway-for-epay-paycenter' ),
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
	public function admin_options(): void {
		// Defence-in-depth: WooCommerce already gates this screen behind
		// `manage_woocommerce`, but re-check here so rendering never runs
		// for unprivileged callers if the method is invoked directly.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$sections = $this->get_field_sections();
		$mode     = (string) $this->get_option( 'mode', 'test' );
		$is_live  = ( 'live' === $mode );
		$version  = defined( 'EPAY_PAYCENTER_VERSION' ) ? EPAY_PAYCENTER_VERSION : '';
		?>
		<div class="epay-admin">

			<header class="epay-admin__header" role="banner">
				<div class="epay-admin__heading">
					<h2 class="epay-admin__title">
						<?php echo esc_html__( 'ePay Paycenter for WooCommerce', 'resilient-gateway-for-epay-paycenter' ); ?>
						<?php if ( '' !== $version ) : ?>
							<span class="epay-admin__version">v<?php echo esc_html( $version ); ?></span>
						<?php endif; ?>
					</h2>
					<p class="epay-admin__subtitle">
						<?php echo esc_html__( 'Accept credit and debit card payments through the Piraeus Bank / Euronet Merchant Services secure Redirection service.', 'resilient-gateway-for-epay-paycenter' ); ?>
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
						if ( 'reconciliation' === $section_key ) {
							$this->render_follow_up_card( $section_fields );
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
										<?php echo esc_html( $is_live ? __( 'Live account', 'resilient-gateway-for-epay-paycenter' ) : __( 'Test account', 'resilient-gateway-for-epay-paycenter' ) ); ?>
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

				<aside class="epay-admin__sidebar" aria-label="<?php echo esc_attr__( 'Integration resources', 'resilient-gateway-for-epay-paycenter' ); ?>">

					<section class="epay-card" aria-labelledby="epay-resources-title">
						<div class="epay-card__header">
							<span class="dashicons dashicons-sos" aria-hidden="true"></span>
							<h3 id="epay-resources-title" class="epay-card__title">
								<?php echo esc_html__( 'Resources', 'resilient-gateway-for-epay-paycenter' ); ?>
							</h3>
						</div>
						<div class="epay-card__body">
							<ul class="epay-links">
								<li>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ); ?>">
										<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
										<?php echo esc_html__( 'WooCommerce → Status → Logs', 'resilient-gateway-for-epay-paycenter' ); ?>
									</a>
								</li>
								<li>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&_payment_method=' . rawurlencode( EPAY_PAYCENTER_GATEWAY_ID ) ) ); ?>">
										<span class="dashicons dashicons-cart" aria-hidden="true"></span>
										<?php echo esc_html__( 'Orders paid via Paycenter', 'resilient-gateway-for-epay-paycenter' ); ?>
									</a>
								</li>
								<li>
									<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">
										<span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span>
										<?php echo esc_html__( 'Installed plugins', 'resilient-gateway-for-epay-paycenter' ); ?>
									</a>
								</li>
							</ul>
						</div>
					</section>

					<section class="epay-card" aria-labelledby="epay-about-title">
						<div class="epay-card__header">
							<span class="dashicons dashicons-info" aria-hidden="true"></span>
							<h3 id="epay-about-title" class="epay-card__title">
								<?php echo esc_html__( 'About this plugin', 'resilient-gateway-for-epay-paycenter' ); ?>
							</h3>
						</div>
						<div class="epay-card__body">
							<p class="epay-footnote">
								<?php echo esc_html__( 'Implements the official ePay Paycenter Redirection v2.9 specification: SOAP ticketing, HMAC-SHA256 response verification, HPOS and Checkout Blocks support.', 'resilient-gateway-for-epay-paycenter' ); ?>
							</p>
							<p class="epay-footnote">
								<?php echo esc_html__( 'Maintained by Stephanos Kamprogiannis. Licensed under GPL-2.0-or-later. "ePay", "Paycenter" and the Piraeus Bank payment mark are trademarks of Piraeus Bank S.A. / Euronet Merchant Services.', 'resilient-gateway-for-epay-paycenter' ); ?>
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
	 * @return array<string,FieldSection>
	 */
	private function get_field_sections() {
		return array(
			'general'        => array(
				'label'  => __( 'General', 'resilient-gateway-for-epay-paycenter' ),
				'icon'   => 'admin-settings',
				'fields' => array( 'enabled', 'title', 'description' ),
			),
			'credentials'    => array(
				'label'  => __( 'Merchant credentials', 'resilient-gateway-for-epay-paycenter' ),
				'icon'   => 'lock',
				'intro'  => __( 'Credentials are provided by Euronet Merchant Services. Test and live accounts use separate credential sets.', 'resilient-gateway-for-epay-paycenter' ),
				'fields' => array( 'mode', 'acquirer_id', 'merchant_id', 'pos_id', 'username', 'password' ),
			),
			'payment'        => array(
				'label'  => __( 'Payment behaviour', 'resilient-gateway-for-epay-paycenter' ),
				'icon'   => 'cart',
				'fields' => array( 'language_code' ),
			),
			'installments'   => array(
				'label'  => __( 'Installments', 'resilient-gateway-for-epay-paycenter' ),
				'icon'   => 'chart-line',
				'intro'  => __( 'Activation by Euronet Merchant Services is required. Installments are interest-free for the customer (the merchant absorbs the bank commission). Installments are not available for IRIS payments.', 'resilient-gateway-for-epay-paycenter' ),
				'fields' => array( 'installments', 'max_installments', 'min_amount_for_installments', 'installments_tiers' ),
			),
			'reconciliation' => array(
				'label'  => __( 'Missing-response recovery', 'resilient-gateway-for-epay-paycenter' ),
				'icon'   => 'backup',
				'intro'  => __( 'These controls recover payments that ePay later resolves through DIAS but cannot send back through the normal callback flow.', 'resilient-gateway-for-epay-paycenter' ),
				'fields' => array( 'follow_up_enabled', 'follow_up_window_hours', 'follow_up_hold_minutes' ),
			),
			'advanced'       => array(
				'label'  => __( 'Advanced & logging', 'resilient-gateway-for-epay-paycenter' ),
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
			if ( isset( $this->form_fields[ $key ] ) ) {
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
	 * @return array{urls:array<string,BankDataRow>,server:array<string,BankDataRow>}
	 */
	private function collect_bank_integration_data() {
		$home     = home_url( '/' );
		$callback = add_query_arg( 'wc-api', EPAY_PAYCENTER_GATEWAY_ID, home_url( '/' ) );
		$checkout = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : $home;

		// SERVER_ADDR is the inbound address; the bank needs the outbound address.
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
				'label' => __( 'Website URL', 'resilient-gateway-for-epay-paycenter' ),
				'value' => $home,
				'hint'  => __( 'Your site origin. This is the URL from which test or live transactions are initiated.', 'resilient-gateway-for-epay-paycenter' ),
			),
			'referrer' => array(
				'label' => __( 'Referrer URL', 'resilient-gateway-for-epay-paycenter' ),
				'value' => $checkout,
				'hint'  => __( 'Page that initiates the payment. Paycenter receives the POST from the auto-submit form rendered on the order pay page that follows checkout.', 'resilient-gateway-for-epay-paycenter' ),
			),
			'success'  => array(
				'label' => __( 'Success URL', 'resilient-gateway-for-epay-paycenter' ),
				'value' => $callback,
				'hint'  => __( 'Paycenter posts the successful transaction response to this URL. The plugin verifies it via HMAC-SHA256 HashKey before marking the order paid.', 'resilient-gateway-for-epay-paycenter' ),
			),
			'failure'  => array(
				'label' => __( 'Failure URL', 'resilient-gateway-for-epay-paycenter' ),
				'value' => $callback,
				'hint'  => __( 'Paycenter posts a failed-transaction response to this URL. The plugin handler uses ResultCode / StatusFlag to flag the order as failed.', 'resilient-gateway-for-epay-paycenter' ),
			),
			'backlink' => array(
				'label' => __( 'Backlink URL', 'resilient-gateway-for-epay-paycenter' ),
				'value' => $callback,
				'hint'  => __( 'URL the customer is sent to when pressing "Cancel" on the Paycenter page. The plugin appends a per-order token automatically via the ParamBackLink field at ticket creation.', 'resilient-gateway-for-epay-paycenter' ),
			),
		);

		// Egress IP cannot be inferred from SERVER_ADDR behind NAT or a proxy.
		// Hosts may supply a confirmed outbound IP through this filter.
		$outbound_ip = apply_filters( 'epay_paycenter_outbound_ip', '' );
		$outbound_ip = is_scalar( $outbound_ip ) ? trim( (string) $outbound_ip ) : '';

		// Display only a valid address that the merchant can safely copy.
		if ( '' !== $outbound_ip && ! filter_var( $outbound_ip, FILTER_VALIDATE_IP ) ) {
			$outbound_ip = '';
		}

		$server = array(
			'outbound_ip' => array(
				'detected' => ( '' !== $outbound_ip ),
				'label'    => __( 'Outbound IP (the value ePay needs)', 'resilient-gateway-for-epay-paycenter' ),
				'value'    => ( '' !== $outbound_ip ) ? $outbound_ip : __( 'Not detected automatically', 'resilient-gateway-for-epay-paycenter' ),
				'hint'     => __( 'The address this server uses when it calls the Paycenter ticketing service, and the one to register with Euronet Merchant Services. WordPress cannot read it, because only a request to an outside host reveals it. To find it, run  curl -4 https://api.ipify.org  on the server over SSH, or ask your hosting provider for the outbound (egress) IP. Hosts and developers can publish it here with the epay_paycenter_outbound_ip filter.', 'resilient-gateway-for-epay-paycenter' ),
			),
			'ip_address'  => array(
				'label' => __( 'Website IP (not the value for ePay)', 'resilient-gateway-for-epay-paycenter' ),
				'value' => ( '' !== $server_addr ) ? $server_addr : __( 'Not detected', 'resilient-gateway-for-epay-paycenter' ),
				'hint'  => __( 'The address this site is served on, as reported by WordPress. Behind a reverse proxy, load balancer, NAT or on a server with more than one IP, this is not the address that reaches Paycenter. Do not give this value to the bank unless your hosting provider has confirmed that the incoming and outgoing addresses are the same.', 'resilient-gateway-for-epay-paycenter' ),
			),
			'response'    => array(
				'label' => __( 'Response method', 'resilient-gateway-for-epay-paycenter' ),
				'value' => __( 'POST (recommended). GET is also accepted.', 'resilient-gateway-for-epay-paycenter' ),
				'hint'  => __( 'Method Paycenter uses to deliver the transaction response to the Success / Failure URLs. The plugin handler accepts both; POST is the recommended choice on the merchant form.', 'resilient-gateway-for-epay-paycenter' ),
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
				'user-agent'  => 'resilient-gateway-for-epay-paycenter/' . EPAY_PAYCENTER_VERSION,
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

		return Epay_Paycenter_Credentials::for_storage( $value );
	}

	/**
	 * Reject invalid durations with a settings error and retain valid stored hours.
	 *
	 * @param string $key Settings field key.
	 * @param string $value Submitted hours.
	 * @return string Valid whole hours.
	 */
	public function validate_follow_up_window_hours_field( $key, $value ) {
		$hours = filter_var(
			$value,
			FILTER_VALIDATE_INT,
			array(
				'options' => array(
					'min_range' => 1,
					'max_range' => 168,
				),
			)
		);
		if ( false === $hours ) {
			WC_Admin_Settings::add_error( __( 'Enter a whole number of hours from 1 to 168.', 'resilient-gateway-for-epay-paycenter' ) );
			return (string) Epay_Paycenter_Reconciliation::window_hours();
		}
		return (string) $hours;
	}

	/**
	 * Return the MD5 digest to send as the Ticketing Web Service `Password`.
	 *
	 * Handles both storage formats: the prefixed digest written since 1.0.36 and
	 * unprefixed plaintext from an older or restored database.
	 *
	 * @return string 32-character MD5 digest, or an empty string when unset.
	 */
	private function get_password_digest() {
		return Epay_Paycenter_Credentials::digest( (string) $this->get_option( 'password' ) );
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
	 * @throws RuntimeException If another component closes the output buffer.
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
		$html = ob_get_clean();
		if ( false === $html ) {
			throw new RuntimeException( 'The gateway settings output buffer was closed unexpectedly.' );
		}
		return $html;
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
	 * @return array{result:'failure'}|array{result:'success',redirect:string}
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'Order could not be loaded.', 'resilient-gateway-for-epay-paycenter' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// WooCommerce verifies the checkout nonce before calling process_payment().
		// Recheck the submitted installment count against the merchant's rules.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$picked = isset( $_POST['epay_installments'] ) && is_scalar( $_POST['epay_installments'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			? absint( wp_unslash( $_POST['epay_installments'] ) )
			: 1;
		$max_allowed  = $this->compute_max_installments( (float) $order->get_total() );
		$installments = max( 1, min( $picked, $max_allowed ) );
		$order->update_meta_data( '_epay_installments', $installments );

		$order->update_status( 'pending', __( 'Awaiting Paycenter payment.', 'resilient-gateway-for-epay-paycenter' ) );

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
	public function output_receipt_page( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'resilient-gateway-for-epay-paycenter' ) . '</p>';
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

		// Keep each attempt independently so concurrent receipts retain their secrets.
		Epay_Paycenter_Open_Tickets::store( $order, $merchant_reference, $ticket['tran_ticket'], $cancel_token );

		$this->record_ticket_log( $order, $ticket['tran_ticket'], $merchant_reference );

		// TranTicket authenticates callbacks and must remain server-side.
		// The bank resolves this form using MerchantReference and merchant credentials.
		$form_fields = array(
			'AcquirerId'        => (string) $this->get_acquirer_id(),
			'MerchantId'        => (string) $this->get_option( 'merchant_id' ),
			'PosId'             => (string) $this->get_pos_id(),
			'User'              => (string) $this->get_option( 'username' ),
			'LanguageCode'      => $this->language_code,
			'MerchantReference' => $merchant_reference,
			'ParamBackLink'     => $this->build_param_back_link( $order, $cancel_token ),
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
		// A random suffix distinguishes concurrent retries for the same order.
		// Reference matching never replaces the callback's HashKey verification.
		$suffix     = strtoupper( wp_generate_password( 24, false, false ) );
		$suffix     = substr( $suffix, 0, 12 );
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
	 * Insert a minimal accounting record for the ticket so administrators can
	 * audit ticketing activity without inspecting order meta. Tran ticket
	 * itself is stored as a SHA-256 digest only - the plaintext lives only in
	 * order meta until verification, then it is deleted.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $tran_ticket Ticket.
	 * @param string   $reference   Merchant reference.
	 */
	private function record_ticket_log( $order, $tran_ticket, $reference ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'epay_paycenter_tickets';

		$now = gmdate( 'Y-m-d H:i:s' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->insert(
			$table,
			array(
				'order_id'           => (int) $order->get_id(),
				'merchant_reference' => $reference,
				'tran_ticket_hash'   => hash( 'sha256', $tran_ticket ),
				'acquirer_id'        => $this->get_acquirer_id(),
				'merchant_id'        => (int) $this->get_option( 'merchant_id' ),
				'pos_id'             => $this->get_pos_id(),
				'amount'             => wc_format_decimal( $order->get_total(), 2 ),
				'currency_code'      => (int) Epay_Paycenter_Currencies::to_numeric( $order->get_currency() ),
				'installments'       => (int) max( 1, (int) $order->get_meta( '_epay_installments', true ) ),
				'request_type'       => self::REQUEST_TYPE,
				'status'             => Epay_Paycenter_Ticket_Audit::STATUS_PENDING,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		// phpcs:enable

		Epay_Paycenter_Reconciliation::schedule_attempt( (int) $order->get_id(), $reference );
	}

	/**
	 * Request a TranTicket from the Paycenter SOAP service for an order.
	 *
	 * @param WC_Order $order              Order.
	 * @param string   $merchant_reference Reference.
	 * @return TicketResult Ticket result.
	 */
	private function request_ticket( $order, $merchant_reference ) {
		$currency_numeric = Epay_Paycenter_Currencies::to_numeric( $order->get_currency() );
		if ( null === $currency_numeric ) {
			return array(
				'success'     => false,
				'error'       => sprintf(
					/* translators: %s currency code */
					__( 'Currency %s is not supported by Paycenter.', 'resilient-gateway-for-epay-paycenter' ),
					$order->get_currency()
				),
				'result_code' => '',
				'description' => '',
				'tran_ticket' => '',
				'minutes'     => 0,
			);
		}

		// Recheck at ticket creation because settings or totals can change after checkout.
		$picked_installments = (int) $order->get_meta( '_epay_installments', true );
		if ( $picked_installments < 1 ) {
			$picked_installments = 1;
		}
		$max_for_order = $this->compute_max_installments( (float) $order->get_total() );
		$installments  = max( 1, min( $picked_installments, $max_for_order ) );

		$request = array(
			'Username'          => (string) $this->get_option( 'username' ),
			'Password'          => $this->get_password_digest(),
			'MerchantId'        => (string) $this->get_option( 'merchant_id' ),
			'PosId'             => (string) $this->get_pos_id(),
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

		// ResultCode 100 identifies invalid Ticketing credentials. Transport failures
		// cannot establish a credential outage; successful issuance clears one.
		if ( ! empty( $result['success'] ) ) {
			Epay_Paycenter_Credential_Notice::clear();
		} elseif ( '100' === (string) $result['result_code'] ) {
			Epay_Paycenter_Credential_Notice::flag( (string) $result['description'] );
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
	 * @param array<string,string> $request Reference to request to modify.
	 * @phpstan-param TicketRequest $request
	 * @param WC_Order             $order   Order.
	 */
	private function append_address_fields( array &$request, $order ): void {
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
			$request['BillAddrPostCode'] = $this->sanitize_postcode( $order->get_billing_postcode() );
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
				$request['ShipAddrPostCode'] = $this->sanitize_postcode( $order->get_shipping_postcode() );
				$request['ShipAddrState']    = Epay_Paycenter_Countries::map_state( $shipping_country, (string) $order->get_shipping_state() );
			}
		}

		// Classify Greek fixed lines separately from mobile numbers for 3-D Secure.
		$phone = $this->format_phone( $order->get_billing_phone(), $billing_country );
		if ( '' !== $phone ) {
			$request[ $this->phone_field_for( $phone, $billing_country ) ] = $phone;
		}

		// Extensions may supply additional address lines or phones absent from core.
		// Filter values are validated before they enter the bank request.
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
	 * @return 'MobilePhone'|'HomePhone'
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
	 * @param array<string,string>   $request Request being built, by reference.
	 * @phpstan-param TicketRequest $request
	 * @param array<array-key,mixed> $extra Raw values returned by the filter.
	 * @param string                 $country_alpha2 Billing country, for phone formatting.
	 */
	private function merge_3ds_fields( array &$request, array $extra, $country_alpha2 ): void {
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
		$name = remove_accents( (string) $name );
		$name = str_replace( array( '"', "'", '`', ';', '<', '>' ), '', $name );
		$name = preg_replace( '/\s+/', ' ', $name );
		if ( null === $name ) {
			return '';
		}
		$name = trim( $name );
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
		$text = preg_replace( '#[^\p{L}\p{N} /:_().,+\-]#u', '', $text );
		if ( null === $text ) {
			return '';
		}
		$text = preg_replace( '/\s+/', ' ', $text );
		return null === $text ? '' : substr( trim( $text ), 0, 50 );
	}

	/**
	 * Limit a postal code to the characters and length accepted by Paycenter.
	 *
	 * @param string $postcode Billing or shipping postal code.
	 * @return string
	 */
	private function sanitize_postcode( $postcode ) {
		$clean = preg_replace( '/[^A-Za-z0-9 -]/', '', $postcode );
		return null === $clean ? '' : substr( $clean, 0, 16 );
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
		if ( null === $phone || '' === $phone ) {
			return '';
		}

		// WooCommerce country code to common calling codes (subset).
		$calling_codes = array(
			'GR' => '30',
			'CY' => '357',
			'GB' => '44',
			'DE' => '49',
			'FR' => '33',
			'IT' => '39',
			'ES' => '34',
			'US' => '1',
			'CA' => '1',
			'BG' => '359',
			'RO' => '40',
			'AT' => '43',
			'BE' => '32',
			'NL' => '31',
			'PL' => '48',
			'PT' => '351',
			'IE' => '353',
			'SE' => '46',
			'NO' => '47',
			'DK' => '45',
			'FI' => '358',
			'CH' => '41',
			'CZ' => '420',
			'HU' => '36',
			'SK' => '421',
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
	 * @phpstan-param TicketResult $result
	 */
	private function render_ticket_error( $order, array $result ): void {
		$note = sprintf(
			/* translators: 1: result code, 2: description */
			__( 'Paycenter ticketing failed. Result code: %1$s. Description: %2$s.', 'resilient-gateway-for-epay-paycenter' ),
			$result['result_code'],
			$result['description']
		);
		$order->add_order_note( $note );
		$order->update_status( 'failed' );
		$order->save();

		echo '<div class="woocommerce-error" role="alert"><p>';
		echo esc_html__( 'We could not start a secure card payment at the moment. Please try again later or choose a different payment method.', 'resilient-gateway-for-epay-paycenter' );
		echo '</p></div>';

		echo '<p><a class="button" href="' . esc_url( wc_get_checkout_url() ) . '">';
		echo esc_html__( 'Return to checkout', 'resilient-gateway-for-epay-paycenter' );
		echo '</a></p>';
	}

	/**
	 * Test the callback using a synthetic, order-independent loopback request.
	 *
	 * Success requires the handler marker and its exact checkout redirect.
	 * A loopback success does not establish that requests from the bank reach PHP.
	 * Only administrators with a valid nonce can run this fixed-URL diagnostic;
	 * response headers are allowlisted and the displayed body is bounded.
	 */
	public static function ajax_run_waf_test(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'forbidden',
					'message' => __( 'You do not have permission to run this diagnostic.', 'resilient-gateway-for-epay-paycenter' ),
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
			// Verify HTTPS unless a local-development filter explicitly opts out.
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', true ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
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
					'message'    => __( 'The self-test POST could not be issued from this server. This usually means the WordPress host cannot reach its own public URL (DNS / loopback / TLS issue). Paycenter callbacks from the bank are a separate ingress path and may still work, but this self-test cannot validate them without loopback connectivity.', 'resilient-gateway-for-epay-paycenter' ),
					'detail'     => $response->get_error_message(),
					'target_url' => $target,
				),
				500
			);
		}

		$status      = (int) wp_remote_retrieve_response_code( $response );
		$headers_obj = wp_remote_retrieve_headers( $response );
		$all_headers = array();
		if ( $headers_obj instanceof \WpOrg\Requests\Utility\CaseInsensitiveDictionary ) {
			$all_headers = $headers_obj->getAll();
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
		$safe_headers      = array();
		foreach ( $header_allow_list as $name ) {
			if ( isset( $all_headers[ $name ] ) ) {
				$value                 = is_array( $all_headers[ $name ] )
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

		$handler_marker  = wp_remote_retrieve_header( $response, 'x-epay-paycenter-handler' );
		$location        = wp_remote_retrieve_header( $response, 'location' );
		$handler_reached = '1' === $handler_marker && 302 === $status && wc_get_checkout_url() === $location;
		$verdict         = $handler_reached ? 'pass' : 'unexpected_response';
		$blocked         = false;
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
					<?php echo esc_html__( 'Bank integration data', 'resilient-gateway-for-epay-paycenter' ); ?>
				</h3>
				<span class="epay-card__subtitle">
					<?php echo esc_html__( 'Values to submit to Euronet Merchant Services', 'resilient-gateway-for-epay-paycenter' ); ?>
				</span>
			</div>
			<div class="epay-card__body">
					<div class="epay-techdata">

						<p class="epay-techdata__notice">
							<?php echo esc_html__( 'Copy the following values into the technical data form provided by Euronet Merchant Services / Piraeus Bank when requesting test or live credentials. Values are generated from this WordPress installation and will change if the site URL moves.', 'resilient-gateway-for-epay-paycenter' ); ?>
						</p>

						<?php
						// Keep related bank integration values in one copyable field.
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
								<button type="button" class="epay-copy__btn" data-target="epay-td-urls" aria-label="<?php echo esc_attr__( 'Copy callback URLs to clipboard', 'resilient-gateway-for-epay-paycenter' ); ?>">
									<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
									<span class="epay-copy__btn-label"><?php echo esc_html__( 'Copy', 'resilient-gateway-for-epay-paycenter' ); ?></span>
								</button>
							</div>
						</div>

						<div class="epay-techdata__group epay-techdata__group--split">

							<?php
							// Offer copying only for a confirmed outbound address.
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
										<button type="button" class="epay-copy__btn" data-target="epay-td-outip" aria-label="<?php echo esc_attr__( 'Copy IP address to clipboard', 'resilient-gateway-for-epay-paycenter' ); ?>">
											<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
											<span class="epay-copy__btn-label"><?php echo esc_html__( 'Copy', 'resilient-gateway-for-epay-paycenter' ); ?></span>
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
									<?php echo esc_html__( 'Cloudflare detected on this site', 'resilient-gateway-for-epay-paycenter' ); ?>
								</summary>
								<p>
									<?php echo esc_html__( 'This store appears to be served through Cloudflare (a CF-Ray / CF-Connecting-IP header was detected on the current request). Requests from Paycenter to your Success / Failure / Backlink URLs therefore reach your site through Cloudflare edge IPs, not only from the web server address shown above. To avoid blocked callbacks, ask Euronet Merchant Services to whitelist the full Cloudflare IPv4 range in addition to your webserver IP.', 'resilient-gateway-for-epay-paycenter' ); ?>
								</p>
								<p>
									<?php echo esc_html__( 'Contact the following addresses and request that the Cloudflare IPv4 ranges be added to your merchant record:', 'resilient-gateway-for-epay-paycenter' ); ?>
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
											__( 'The authoritative Cloudflare IPv4 list is always published at %s and is updated by Cloudflare when their edge network changes.', 'resilient-gateway-for-epay-paycenter' ),
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
											esc_html( _n( 'Current Cloudflare IPv4 ranges (%d CIDR)', 'Current Cloudflare IPv4 ranges (%d CIDRs)', count( $cf_ranges ), 'resilient-gateway-for-epay-paycenter' ) ),
											(int) count( $cf_ranges )
										);
										?>
									</label>
									<div class="epay-copy epay-copy--block">
										<textarea id="epay-td-cf-ipv4" class="epay-copy__textarea" readonly rows="6"><?php echo esc_textarea( implode( "\n", $cf_ranges ) ); ?></textarea>
										<button type="button" class="epay-copy__btn" data-target="epay-td-cf-ipv4" aria-label="<?php echo esc_attr__( 'Copy Cloudflare IPv4 ranges to clipboard', 'resilient-gateway-for-epay-paycenter' ); ?>">
											<span class="dashicons dashicons-admin-page" aria-hidden="true"></span>
											<span class="epay-copy__btn-label"><?php echo esc_html__( 'Copy all ranges', 'resilient-gateway-for-epay-paycenter' ); ?></span>
										</button>
									</div>
									<p class="epay-techdata__hint">
										<?php echo esc_html__( 'Fetched from Cloudflare and cached for 12 hours. Copy these CIDRs into the email alongside your webserver IP.', 'resilient-gateway-for-epay-paycenter' ); ?>
									</p>
								<?php else : ?>
									<p class="epay-techdata__hint">
										<?php echo esc_html__( 'The live Cloudflare IPv4 list could not be fetched from this server right now. Please copy it manually from the link above.', 'resilient-gateway-for-epay-paycenter' ); ?>
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
	 * Render channel verification and recovery settings together. A successful test stores
	 * only the verified channel/fingerprint; enabling recovery remains a
	 * separate settings save.
	 *
	 * @param array<string,array<string,mixed>> $fields WooCommerce settings fields.
	 * @return void
	 */
	private function render_follow_up_card( array $fields ): void {
		$channel = Epay_Paycenter_Reconciliation::verified_channel();
		$active  = Epay_Paycenter_Reconciliation::is_enabled();
		if ( $active ) {
			$status_label = __( 'Active', 'resilient-gateway-for-epay-paycenter' );
		} elseif ( '' !== $channel ) {
			/* translators: %s: verified ePay ChannelType (eCommerce or 3DSecure). */
			$status_label = sprintf( __( 'Verified: %s', 'resilient-gateway-for-epay-paycenter' ), $channel );
		} else {
			$status_label = __( 'Not verified', 'resilient-gateway-for-epay-paycenter' );
		}
		?>
		<section class="epay-card" aria-labelledby="epay-follow-up-title">
			<div class="epay-card__header">
				<span class="dashicons dashicons-update-alt" aria-hidden="true"></span>
				<h3 id="epay-follow-up-title" class="epay-card__title">
					<?php echo esc_html__( 'Missing-response recovery', 'resilient-gateway-for-epay-paycenter' ); ?>
				</h3>
				<span class="epay-card__subtitle" id="epay-follow-up-status">
					<?php echo esc_html( $status_label ); ?>
				</span>
			</div>
			<div class="epay-card__body">
				<p class="epay-techdata__notice">
					<?php echo esc_html__( 'Use an ePay order that is already shown as successful in the AdminTool. The test tries eCommerce and then 3DSecure with AcquirerID GR014. It reads the bank result but does not change the order.', 'resilient-gateway-for-epay-paycenter' ); ?>
				</p>
				<div class="epay-waftest__controls epay-follow-up__controls">
					<label for="epay-follow-up-order"><?php echo esc_html__( 'Successful order ID', 'resilient-gateway-for-epay-paycenter' ); ?></label>
					<input id="epay-follow-up-order" type="number" min="1" inputmode="numeric" class="small-text" />
					<button type="button" class="button" id="epay-follow-up-test">
						<span class="dashicons dashicons-search" aria-hidden="true"></span>
						<span><?php echo esc_html__( 'Verify follow-up channel', 'resilient-gateway-for-epay-paycenter' ); ?></span>
					</button>
				</div>
				<div class="epay-waftest__result" id="epay-follow-up-result" role="status" aria-live="polite"></div>
				<table class="form-table" role="presentation">
					<?php
					// WooCommerce escapes the trusted field definitions and stored values.
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $this->generate_settings_html( $fields, false );
					?>
				</table>
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
					<?php echo esc_html__( 'Callback diagnostics', 'resilient-gateway-for-epay-paycenter' ); ?>
				</h3>
				<span class="epay-card__subtitle">
					<?php echo esc_html__( 'Detect host-WAF interception before going live', 'resilient-gateway-for-epay-paycenter' ); ?>
				</span>
			</div>
			<div class="epay-card__body">
				<p class="epay-techdata__notice">
					<?php echo esc_html__( 'Some shared-hosting firewalls (cPFence, ModSecurity / OWASP CRS, Imunify360, BitNinja, LiteSpeed WAF) can intercept the Paycenter failure callbacks because their payloads contain patterns (dash-only TransactionDateTime, Greek error text, empty HashKey on declined transactions) that look like attack signatures. The test below issues a realistic declined-transaction POST from this WordPress server to the plugin\'s own callback URL and reports whether it is blocked before it reaches PHP. No order is created or modified; the synthetic payload carries a WAFTEST- merchant reference that no order in the database can match.', 'resilient-gateway-for-epay-paycenter' ); ?>
				</p>
				<p class="epay-techdata__hint">
					<?php echo esc_html__( 'Scope: this exercises your local webserver / host WAF (ModSecurity, cPFence, Imunify360, BitNinja, LiteSpeed). CDN-level WAFs that sit in front of your origin (Cloudflare, Sucuri, Akamai) are not exercised by the loopback POST; their rules must be tested with an external probe.', 'resilient-gateway-for-epay-paycenter' ); ?>
				</p>
				<div class="epay-waftest__controls">
					<button type="button" class="button button-primary" id="epay-waftest-run">
						<span class="dashicons dashicons-update" aria-hidden="true"></span>
						<span class="epay-waftest__btn-label"><?php echo esc_html__( 'Test callback URL', 'resilient-gateway-for-epay-paycenter' ); ?></span>
					</button>
				</div>
				<div class="epay-waftest__result" id="epay-waftest-result" role="status" aria-live="polite"></div>
			</div>
		</section>
		<?php
	}
}
