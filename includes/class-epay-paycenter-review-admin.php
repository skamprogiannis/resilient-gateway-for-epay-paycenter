<?php
/**
 * Native administration for payment reviews.
 *
 * @package EpayPaycenter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Presents local evidence; no bank requests run while an admin page renders.
 *
 * @phpstan-import-type ReviewCase from Epay_Paycenter_Review
 * @phpstan-import-type RecoveryStatus from Epay_Paycenter_Reconciliation
 */
final class Epay_Paycenter_Review_Admin {
	const PAGE      = 'epay-payment-reviews';
	const PAGE_SIZE = 20;

	/**
	 * Local recovery status provider.
	 *
	 * @var callable():RecoveryStatus
	 */
	private $read_status;

	/**
	 * Register admin entry points independently of the payment worker.
	 *
	 * @param callable $read_status Local recovery status provider.
	 * @phpstan-param callable():RecoveryStatus $read_status
	 */
	public function __construct( callable $read_status ) {
		$this->read_status = $read_status;
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_epay_paycenter_review', array( __CLASS__, 'acknowledge' ) );
		add_action( 'wp_ajax_epay_paycenter_review', array( __CLASS__, 'acknowledge' ) );
		add_filter( 'views_edit-shop_order', array( __CLASS__, 'order_views' ) );
		add_filter( 'views_woocommerce_page_wc-orders', array( __CLASS__, 'order_views' ) );
		add_action( 'woocommerce_settings_checkout', array( $this, 'settings_status' ), 30 );
	}

	/** Register the native WooCommerce submenu. */
	public function menu(): void {
		add_submenu_page( 'woocommerce', __( 'ePay reviews', 'resilient-gateway-for-epay-paycenter' ), __( 'ePay reviews', 'resilient-gateway-for-epay-paycenter' ), 'manage_woocommerce', self::PAGE, array( $this, 'render' ) );
	}

	/**
	 * Staff-facing category names.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'all'         => __( 'All unreviewed', 'resilient-gateway-for-epay-paycenter' ),
			'payments'    => __( 'Payment discrepancies', 'resilient-gateway-for-epay-paycenter' ),
			'problems'    => __( 'Check problems', 'resilient-gateway-for-epay-paycenter' ),
			'unconfirmed' => __( 'Unconfirmed attempts', 'resilient-gateway-for-epay-paycenter' ),
			'historical'  => __( 'Historical checks', 'resilient-gateway-for-epay-paycenter' ),
			'reviewed'    => __( 'Reviewed cases', 'resilient-gateway-for-epay-paycenter' ),
		);
	}

	/**
	 * Build a review link without accepting an external redirect target.
	 *
	 * @param array<string,string|int> $args Read-only filters.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/** Restrict assets to review, order and ePay settings screens. */
	public static function assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! current_user_can( 'manage_woocommerce' ) || ! in_array( $screen->id, array( 'woocommerce_page_' . self::PAGE, 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders', 'woocommerce_page_wc-settings' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'epay-paycenter-review', EPAY_PAYCENTER_PLUGIN_URL . 'assets/css/epay-paycenter-review.css', array(), EPAY_PAYCENTER_VERSION );
		wp_enqueue_script( 'epay-paycenter-review', EPAY_PAYCENTER_PLUGIN_URL . 'assets/js/epay-paycenter-review.js', array(), EPAY_PAYCENTER_VERSION, true );
		wp_localize_script(
			'epay-paycenter-review',
			'epayPaycenterReview',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'epay_paycenter_review' ),
				'strings' => array(
					'saving'       => __( 'Saving…', 'resilient-gateway-for-epay-paycenter' ),
					'saved'        => __( 'Review saved.', 'resilient-gateway-for-epay-paycenter' ),
					'failed'       => __( 'Review could not be saved. Please try again.', 'resilient-gateway-for-epay-paycenter' ),
					'partial'      => __( 'Some reviews were not saved. Check the remaining rows and try again.', 'resilient-gateway-for-epay-paycenter' ),
					'select'       => __( 'Select unconfirmed or historical cases to review.', 'resilient-gateway-for-epay-paycenter' ),
					'copied'       => __( 'Reference copied.', 'resilient-gateway-for-epay-paycenter' ),
					'copyFailed'   => __( 'Could not copy. Select and copy the reference manually.', 'resilient-gateway-for-epay-paycenter' ),
					'countsFailed' => __( 'Review counts are unavailable. Refresh the page.', 'resilient-gateway-for-epay-paycenter' ),
					'unavailable'  => __( 'unavailable', 'resilient-gateway-for-epay-paycenter' ),
					/* translators: %d: number of unreviewed cases. */
					'unreviewed'   => __( 'Unreviewed cases: %d', 'resilient-gateway-for-epay-paycenter' ),
					'none'         => __( 'No unreviewed payment exceptions.', 'resilient-gateway-for-epay-paycenter' ),
				),
			)
		);
	}

	/**
	 * Count cases rather than orders; retries can need separate reviews.
	 *
	 * @param array<string,ReviewCase> $cases Local snapshot.
	 * @return array<string,int>
	 */
	public static function counts( array $cases ): array {
		$counts = array_fill_keys( array_keys( self::labels() ), 0 );
		foreach ( $cases as $review_case ) {
			$group = $review_case['group'];
			if ( 'resolved' !== $group ) {
				++$counts[ $group ];
				if ( 'reviewed' !== $group ) {
					++$counts['all'];
				}
			}
		}
		return $counts;
	}

	/**
	 * Add navigation, not a global notice, to both order list implementations.
	 *
	 * @param array<string,string> $views Native order views.
	 * @return array<string,string>
	 */
	public static function order_views( array $views ): array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $views;
		}
		try {
			$count = (string) self::counts( Epay_Paycenter_Review::cases() )['all'];
		} catch ( RuntimeException $error ) {
			$count = __( 'unavailable', 'resilient-gateway-for-epay-paycenter' );
			Epay_Paycenter_Logger::error( 'Could not read payment review status.' );
		}
		$views['epay_reviews'] = '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'ePay reviews', 'resilient-gateway-for-epay-paycenter' ) . ' (<span data-epay-count="all">' . esc_html( $count ) . '</span>)</a>';
		return $views;
	}

	/**
	 * Apply read-only category and reference filters.
	 *
	 * @param array<string,ReviewCase> $cases Local snapshot.
	 * @param string                   $group Selected category.
	 * @param string                   $search Order number or exact/partial reference.
	 * @return array<string,ReviewCase>
	 */
	private static function filter( array $cases, string $group, string $search ): array {
		return array_filter(
			$cases,
			static function ( array $review_case ) use ( $group, $search ): bool {
				if ( 'resolved' === $review_case['group'] || ( 'all' === $group ? 'reviewed' === $review_case['group'] : $group !== $review_case['group'] ) ) {
					return false;
				}
				return '' === $search || ltrim( $search, '#' ) === (string) $review_case['item']['order_id'] || false !== stripos( $review_case['item']['reference'], $search );
			}
		);
	}

	/**
	 * Read-only filters are not privileged operations.
	 *
	 * @param string $key Query parameter.
	 */
	private static function query( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/** Keep worker health on settings, without duplicating the queue. */
	public function settings_status(): void {
		if ( EPAY_PAYCENTER_GATEWAY_ID !== self::query( 'section' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<section class="epay-paycenter-review epay-review-health"><h2>' . esc_html__( 'Payment review status', 'resilient-gateway-for-epay-paycenter' ) . '</h2>';
		$this->health();
		echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open ePay reviews', 'resilient-gateway-for-epay-paycenter' ) . '</a></p></section>';
	}

	/** Render errors explicitly instead of displaying a false all-clear. */
	private function health(): void {
		try {
			$counts = self::counts( Epay_Paycenter_Review::cases() );
			self::render_status( ( $this->read_status )(), $counts['all'] );
		} catch ( RuntimeException $error ) {
			self::unavailable();
		}
	}

	/** Local data-read failure shared by the review surfaces. */
	public static function unavailable(): void {
		echo '<p role="alert">' . esc_html__( 'Payment review status is unavailable. Ask the site administrator to check the gateway log.', 'resilient-gateway-for-epay-paycenter' ) . '</p>';
		Epay_Paycenter_Logger::error( 'Could not read payment review status.' );
	}

	/** Render the paginated native review table. */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You cannot review payments.', 'resilient-gateway-for-epay-paycenter' ), '', array( 'response' => 403 ) );
		}
		$labels = self::labels();
		$group  = self::query( 'review_group' );
		$group  = isset( $labels[ $group ] ) ? $group : 'all';
		$search = self::query( 's' );
		echo '<div class="wrap epay-paycenter-review"><h1>' . esc_html__( 'ePay reviews', 'resilient-gateway-for-epay-paycenter' ) . '</h1>';
		try {
			$cases  = Epay_Paycenter_Review::cases();
			$counts = self::counts( $cases );
			$status = ( $this->read_status )();
		} catch ( RuntimeException $error ) {
			self::unavailable();
			echo '</div>';
			return;
		}
		$needs_attention = null === $status['awaiting'] || ! $status['enabled'] || ! $status['scheduled'] || $status['overdue'] || ! empty( $status['last_run']['errors'] );
		echo '<details class="epay-review-health"' . ( $needs_attention ? ' open' : '' ) . '><summary>' . esc_html__( 'Recovery status', 'resilient-gateway-for-epay-paycenter' ) . '</summary>';
		self::render_status( $status, $counts['all'] );
		echo '</details>';
		echo '<p>' . esc_html__( 'Compare the evidence in AdminTool before marking a case reviewed. Reviewing does not change payments or orders.', 'resilient-gateway-for-epay-paycenter' ) . '</p>';
		$saved  = absint( self::query( 'saved' ) );
		$failed = absint( self::query( 'failed' ) );
		if ( $saved || $failed ) {
			echo '<p role="status">' . esc_html( $failed ? __( 'Some reviews were not saved. Check the remaining rows and try again.', 'resilient-gateway-for-epay-paycenter' ) : __( 'Review saved.', 'resilient-gateway-for-epay-paycenter' ) ) . '</p>';
		}
		echo '<ul class="subsubsub epay-review-filters">';
		$index = 0;
		foreach ( $labels as $name => $label ) {
			echo '<li>' . ( $index++ ? ' | ' : '' ) . '<a data-review-group="' . esc_attr( $name ) . '" href="' . esc_url(
				self::url(
					array(
						'review_group' => $name,
						's'            => $search,
					)
				)
			) . '"' . ( $name === $group ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . ' (<span data-epay-count="' . esc_attr( $name ) . '">' . absint( $counts[ $name ] ) . '</span>)</a></li>';
		}
		echo '</ul><form method="get" class="epay-review-search"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		echo '<div class="epay-review-category"><label for="epay-review-category">' . esc_html__( 'Review category', 'resilient-gateway-for-epay-paycenter' ) . '</label><select id="epay-review-category" name="review_group">';
		foreach ( $labels as $name => $label ) {
			echo '<option value="' . esc_attr( $name ) . '" data-review-option="' . esc_attr( $name ) . '" data-label="' . esc_attr( $label ) . '"' . selected( $group, $name, false ) . '>' . esc_html( $label ) . ' (' . absint( $counts[ $name ] ) . ')</option>';
		}
		echo '</select></div><p class="search-box"><label class="screen-reader-text" for="epay-review-search">' . esc_html__( 'Order number or ePay reference', 'resilient-gateway-for-epay-paycenter' ) . '</label><input id="epay-review-search" type="search" name="s" value="' . esc_attr( $search ) . '"><button class="button" type="submit">' . esc_html__( 'Search cases', 'resilient-gateway-for-epay-paycenter' ) . '</button></p></form>';
		$filtered = self::filter( $cases, $group, $search );
		$total    = count( $filtered );
		$pages    = max( 1, (int) ceil( $total / self::PAGE_SIZE ) );
		$page     = min( $pages, max( 1, absint( self::query( 'paged' ) ) ) );
		$visible  = array_slice( array_reverse( $filtered, true ), ( $page - 1 ) * self::PAGE_SIZE, self::PAGE_SIZE, true );
		echo '<form class="epay-review-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-review-page="' . absint( $page ) . '" data-page-size="' . absint( self::PAGE_SIZE ) . '">';
		echo '<input type="hidden" name="action" value="epay_paycenter_review"><input type="hidden" name="review_group" value="' . esc_attr( $group ) . '"><input type="hidden" name="s" value="' . esc_attr( $search ) . '"><input type="hidden" name="paged" value="' . absint( $page ) . '">';
		wp_nonce_field( 'epay_paycenter_review' );
		echo '<div class="tablenav top"><div class="alignleft actions bulkactions"><label class="screen-reader-text" for="epay-review-bulk">' . esc_html__( 'Bulk actions', 'resilient-gateway-for-epay-paycenter' ) . '</label><select id="epay-review-bulk" name="bulk_action"><option value="">' . esc_html__( 'Bulk actions', 'resilient-gateway-for-epay-paycenter' ) . '</option><option value="review">' . esc_html__( 'Mark reviewed', 'resilient-gateway-for-epay-paycenter' ) . '</option></select> <button class="button" type="submit">' . esc_html__( 'Apply', 'resilient-gateway-for-epay-paycenter' ) . '</button></div>';
		self::pagination( $page, $pages, $total, $group, $search );
		echo '</div><p class="description epay-review-bulk-help">' . esc_html__( 'Bulk review is available only for unconfirmed and historical cases.', 'resilient-gateway-for-epay-paycenter' ) . '</p>';
		echo '<p class="epay-review-feedback" role="status" aria-live="polite" tabindex="-1"></p>';
		echo '<table class="wp-list-table widefat fixed striped epay-review-table"><caption class="screen-reader-text">' . esc_html__( 'Payment review cases', 'resilient-gateway-for-epay-paycenter' ) . '</caption><thead><tr><td class="check-column"><input type="checkbox" class="epay-review-select-all" aria-label="' . esc_attr__( 'Select eligible cases on this page', 'resilient-gateway-for-epay-paycenter' ) . '"> <span class="epay-mobile-select-label">' . esc_html__( 'Select eligible cases on this page', 'resilient-gateway-for-epay-paycenter' ) . '</span></td><th scope="col">' . esc_html__( 'Order / reference', 'resilient-gateway-for-epay-paycenter' ) . '</th><th scope="col">' . esc_html__( 'Reason for review', 'resilient-gateway-for-epay-paycenter' ) . '</th><th scope="col" class="epay-review-action-column">' . esc_html__( 'Review', 'resilient-gateway-for-epay-paycenter' ) . '</th></tr></thead><tbody>';
		foreach ( $visible as $key => $review_case ) {
			self::row( $key, $review_case );
		}
		echo '</tbody></table>';
		echo '<p class="epay-review-empty"' . ( $visible ? ' hidden' : '' ) . '>' . esc_html__( 'No cases on this page.', 'resilient-gateway-for-epay-paycenter' ) . ' <a href="' . esc_url(
			self::url(
				array(
					'review_group' => $group,
					's'            => $search,
					'paged'        => $page,
				)
			)
		) . '">' . esc_html__( 'Refresh list', 'resilient-gateway-for-epay-paycenter' ) . '</a></p>';
		echo '</form></div>';
	}

	/**
	 * Keep category and search context across pages.
	 *
	 * @param int    $page Current page.
	 * @param int    $pages Page count.
	 * @param int    $total Filtered case count.
	 * @param string $group Category.
	 * @param string $search Search term.
	 */
	private static function pagination( int $page, int $pages, int $total, string $group, string $search ): void {
		echo '<div class="tablenav-pages"><span class="displaying-num"><span data-review-total>' . absint( $total ) . '</span> ' . esc_html__( 'cases', 'resilient-gateway-for-epay-paycenter' ) . '</span><span class="pagination-links">';
		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url(
				self::url(
					array(
						'review_group' => $group,
						's'            => $search,
						'paged'        => $page - 1,
					)
				)
			) . '">' . esc_html__( 'Previous page', 'resilient-gateway-for-epay-paycenter' ) . '</a> ';
		}
		echo '<span class="paging-input">' . absint( $page ) . ' / <span data-review-pages>' . absint( $pages ) . '</span></span> ';
		if ( $page < $pages ) {
			echo '<a class="button epay-review-next" href="' . esc_url(
				self::url(
					array(
						'review_group' => $group,
						's'            => $search,
						'paged'        => $page + 1,
					)
				)
			) . '">' . esc_html__( 'Next page', 'resilient-gateway-for-epay-paycenter' ) . '</a>';
		}
		echo '</span></div>';
	}

	/**
	 * Explain the recorded discrepancy without inferring a bank outcome.
	 *
	 * @param string $type Stored reason.
	 * @return string
	 */
	public static function reason( string $type ): string {
		$reasons = array(
			'unresolved'           => __( 'No final bank result. Check this reference in AdminTool before treating it as paid or unpaid.', 'resilient-gateway-for-epay-paycenter' ),
			'missing_paid'         => __( 'Bank confirmed payment, but the order is missing or cannot be fulfilled. Account for the payment in AdminTool.', 'resilient-gateway-for-epay-paycenter' ),
			'trash_paid'           => __( 'Bank confirmed payment for a trashed order. Review the payment before restoring or refunding the order.', 'resilient-gateway-for-epay-paycenter' ),
			'late_paid'            => __( 'Payment arrived after stock was released. Confirm stock and fulfilment before dispatch.', 'resilient-gateway-for-epay-paycenter' ),
			'double_paid'          => __( 'More than one payment was detected. Compare the transactions in AdminTool before arranging a refund.', 'resilient-gateway-for-epay-paycenter' ),
			'settlement_error'     => __( 'Bank confirmed payment, but the order update failed. Check the gateway log and order status.', 'resilient-gateway-for-epay-paycenter' ),
			'database_error'       => __( 'A bank result could not be saved. Check the gateway log and AdminTool.', 'resilient-gateway-for-epay-paycenter' ),
			'reconciliation_error' => __( 'The bank check could not finish. Check the gateway log and AdminTool.', 'resilient-gateway-for-epay-paycenter' ),
		);
		return $reasons[ $type ] ?? __( 'Review this payment in AdminTool.', 'resilient-gateway-for-epay-paycenter' );
	}

	/**
	 * Render one case, with a checkbox only for routine reviews.
	 *
	 * @param string $key Case identity.
	 * @param array  $review_case Case and local order link.
	 * @phpstan-param ReviewCase $review_case
	 */
	private static function row( string $key, array $review_case ): void {
		$item     = $review_case['item'];
		$eligible = in_array( $review_case['group'], array( 'unconfirmed', 'historical' ), true );
		echo '<tr class="epay-review-row" data-review-key="' . esc_attr( $key ) . '"><th scope="row" class="check-column">';
		if ( $eligible ) {
			echo '<input type="checkbox" name="review_keys[]" value="' . esc_attr( $key ) . '" aria-label="' . esc_attr(
				sprintf(
				/* translators: %s: ePay reference. */
					__( 'Select %s', 'resilient-gateway-for-epay-paycenter' ),
					$item['reference']
				)
			) . '">';
		}
		echo '</th><td><strong>';
		$order = wc_get_order( $item['order_id'] );
		if ( $order instanceof WC_Order ) {
			echo '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . absint( $item['order_id'] ) . '</a>';
		} else {
			echo '#' . absint( $item['order_id'] ) . ' — ' . esc_html__( 'Order unavailable', 'resilient-gateway-for-epay-paycenter' );
		}
		echo '</strong>';
		self::reference( $item['reference'] );
		echo '<a href="https://paycenter.piraeusbank.gr/AdminTool/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open AdminTool', 'resilient-gateway-for-epay-paycenter' ) . '</a>';
		echo '</td><td><strong>' . esc_html( self::labels()[ $review_case['group'] ] ) . '</strong><p>' . esc_html( self::reason( $item['type'] ) ) . '</p></td><td>';
		self::review_action( $key, $review_case, true );
		echo '</td></tr>';
	}

	/**
	 * Keep the reference selectable even when clipboard access fails.
	 *
	 * @param string $reference Non-secret bank reference.
	 */
	public static function reference( string $reference ): void {
		if ( '' === $reference ) {
			echo '<p>' . esc_html__( 'Reference unavailable', 'resilient-gateway-for-epay-paycenter' ) . '</p>';
			return;
		}
		echo '<div class="epay-reference"><code>' . esc_html( $reference ) . '</code> <button class="button button-small epay-copy-reference" type="button" data-reference="' . esc_attr( $reference ) . '">' . esc_html__( 'Copy reference', 'resilient-gateway-for-epay-paycenter' ) . '</button><span class="epay-copy-feedback" role="status" aria-live="polite"></span></div>';
	}

	/**
	 * Order panels use buttons, never nested forms or order-form submit controls.
	 *
	 * @param string $key Case identity.
	 * @param array  $review_case Case.
	 * @param bool   $in_review_form Whether a no-JS submit is safe.
	 * @phpstan-param ReviewCase $review_case
	 */
	public static function review_action( string $key, array $review_case, bool $in_review_form ): void {
		$item = $review_case['item'];
		if ( '' !== $item['reviewed_at'] ) {
			echo '<p>' . esc_html__( 'Reviewed (UTC):', 'resilient-gateway-for-epay-paycenter' ) . ' ' . esc_html( $item['reviewed_at'] ) . '</p>';
			return;
		}
		echo '<button type="' . ( $in_review_form ? 'submit' : 'button' ) . '" class="button button-small epay-mark-reviewed" name="review_key" value="' . esc_attr( $key ) . '">' . esc_html__( 'Mark reviewed', 'resilient-gateway-for-epay-paycenter' ) . '</button><p class="epay-review-error" role="alert"></p>';
		if ( ! $in_review_form ) {
			echo '<noscript><a href="' . esc_url( self::url( array( 's' => (string) $item['order_id'] ) ) ) . '">' . esc_html__( 'Open ePay reviews', 'resilient-gateway-for-epay-paycenter' ) . '</a></noscript>';
		}
	}

	/** Authorize and persist both AJAX and no-JavaScript requests. */
	public static function acknowledge(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You cannot review payments.', 'resilient-gateway-for-epay-paycenter' ), '', array( 'response' => 403 ) );
		}
		if ( wp_doing_ajax() ) {
			check_ajax_referer( 'epay_paycenter_review', '_wpnonce' );
		} else {
			check_admin_referer( 'epay_paycenter_review' );
		}
		$key  = isset( $_POST['review_key'] ) && is_string( $_POST['review_key'] ) ? sanitize_text_field( wp_unslash( $_POST['review_key'] ) ) : '';
		$bulk = '' === $key;
		// Validate every member against the exact case-key grammar below; reject nested arrays.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$keys = $bulk && isset( $_POST['review_keys'] ) && is_array( $_POST['review_keys'] ) ? wp_unslash( $_POST['review_keys'] ) : array( $key );
		if ( ( $bulk && ( ! isset( $_POST['bulk_action'] ) || 'review' !== $_POST['bulk_action'] ) ) || ! $keys || count( $keys ) > 100 ) {
			wp_die( esc_html__( 'Select unconfirmed or historical cases to review.', 'resilient-gateway-for-epay-paycenter' ), '', array( 'response' => 400 ) );
		}
		foreach ( $keys as $candidate ) {
			if ( ! is_string( $candidate ) || ! preg_match( '/^[a-z0-9_]+:[0-9]+(?::[a-f0-9]{12})?$/D', $candidate ) ) {
				wp_die( esc_html__( 'Invalid review selection.', 'resilient-gateway-for-epay-paycenter' ), '', array( 'response' => 400 ) );
			}
		}
		$group  = isset( $_POST['review_group'] ) && is_string( $_POST['review_group'] ) ? sanitize_key( $_POST['review_group'] ) : 'all';
		$group  = isset( self::labels()[ $group ] ) ? $group : 'all';
		$search = isset( $_POST['s'] ) && is_string( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
		try {
			$result = Epay_Paycenter_Review::acknowledge_keys( array_values( $keys ), $bulk );
		} catch ( RuntimeException $error ) {
			$result = array(
				'saved'  => array(),
				'errors' => array_fill_keys( $keys, __( 'Review could not be saved. Please try again.', 'resilient-gateway-for-epay-paycenter' ) ),
			);
			Epay_Paycenter_Logger::error( 'Could not acknowledge payment review cases.' );
		}
		if ( wp_doing_ajax() ) {
			$counts = null;
			$total  = null;
			try {
				$cases  = Epay_Paycenter_Review::cases();
				$counts = self::counts( $cases );
				$total  = count( self::filter( $cases, $group, $search ) );
			} catch ( RuntimeException $error ) {
				Epay_Paycenter_Logger::error( 'Could not refresh payment review counts.' );
			}
			wp_send_json_success(
				array_merge(
					$result,
					array(
						'counts' => $counts,
						'total'  => $total,
					)
				)
			);
		}
		$page = isset( $_POST['paged'] ) && is_scalar( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;
		wp_safe_redirect(
			self::url(
				array(
					'review_group' => $group,
					's'            => $search,
					'paged'        => $page,
					'saved'        => count( $result['saved'] ),
					'failed'       => count( $result['errors'] ),
				)
			)
		);
		exit;
	}

	/**
	 * Distinguish configured recovery, queued attempts, and actual worker runs.
	 *
	 * @param array $status Read-only recovery snapshot.
	 * @param int   $count Unreviewed cases, not distinct orders.
	 * @phpstan-param RecoveryStatus $status
	 */
	private static function render_status( array $status, int $count ): void {
		$enabled = $status['verified']
			? ( $status['enabled'] ? __( 'Automatic recovery: Enabled', 'resilient-gateway-for-epay-paycenter' ) : __( 'Automatic recovery: Disabled', 'resilient-gateway-for-epay-paycenter' ) )
			: __( 'Automatic recovery: Requires verification', 'resilient-gateway-for-epay-paycenter' );
		echo '<div class="epay-review-overview"><p class="epay-review-status">' . esc_html( $enabled ) . '</p>';
		if ( null === $status['awaiting'] ) {
			echo '<p>' . esc_html__( 'Pending-check status is unavailable. Ask the site administrator to check the gateway log.', 'resilient-gateway-for-epay-paycenter' ) . '</p>';
		} else {
			echo '<p class="epay-review-awaiting">' . esc_html(
				sprintf(
				/* translators: %d: number of payment attempts, not distinct orders. */
					_n( 'Awaiting bank result: %d attempt', 'Awaiting bank result: %d attempts', $status['awaiting'], 'resilient-gateway-for-epay-paycenter' ),
					$status['awaiting']
				)
			) . '</p>';
		}
		echo '<p class="epay-review-count">' . esc_html(
			$count ? sprintf(
				/* translators: %d: number of unreviewed exceptions, not distinct orders. */
				__( 'Unreviewed cases: %d', 'resilient-gateway-for-epay-paycenter' ),
				$count
			) : __( 'No unreviewed payment exceptions.', 'resilient-gateway-for-epay-paycenter' )
		) . '</p></div>';
		if ( ! $status['enabled'] ) {
			echo '<p>' . esc_html__( 'Automatic checks are not running. Ask the site administrator to review the gateway settings.', 'resilient-gateway-for-epay-paycenter' ) . '</p>';
		} elseif ( ! $status['scheduled'] ) {
			echo '<p>' . esc_html__( 'No recovery run is scheduled. Ask the site administrator to check WordPress scheduled tasks.', 'resilient-gateway-for-epay-paycenter' ) . '</p>';
		} elseif ( $status['overdue'] ) {
			echo '<p>' . esc_html__( 'Automatic checks are more than 15 minutes behind. Ask the site administrator to check WordPress scheduled tasks and the gateway log.', 'resilient-gateway-for-epay-paycenter' ) . '</p>';
		}
		$run = $status['last_run'];
		echo '<p class="epay-review-last-run">';
		if ( null === $run ) {
			echo esc_html__( 'No run recorded yet.', 'resilient-gateway-for-epay-paycenter' );
		} else {
			echo esc_html(
				sprintf(
				/* translators: %s: recovery run completion time in the site's timezone. */
					__( 'Last recovery run: %s', 'resilient-gateway-for-epay-paycenter' ),
					wp_date( 'Y-m-d H:i:s T', $run['completed_at'] )
				)
			) . ' — ';
			echo esc_html(
				$run['errors'] > 0
				? __( 'Completed with errors. Ask the site administrator to check the gateway log.', 'resilient-gateway-for-epay-paycenter' )
				: __( 'Completed without reported errors.', 'resilient-gateway-for-epay-paycenter' )
			);
		}
		echo '</p>';
	}
}
