<?php
/**
 * Native payment evidence panel for legacy and HPOS order editors.
 *
 * @package EpayPaycenter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows the current order's attempts and review actions in either editor.
 *
 * @phpstan-import-type OrderEvidence from Epay_Paycenter_Order_Evidence
 */
final class Epay_Paycenter_Order_Admin {
	/** Register both supported order editors. */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ), 10, 2 );
	}

	/**
	 * Use the native screen identity instead of assuming post storage.
	 *
	 * @param string                $screen_id Native editor screen.
	 * @param WP_Post|WC_Order|null $editor Editor subject.
	 */
	public static function register( string $screen_id, $editor ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! in_array( $screen_id, array( 'shop_order', wc_get_page_screen_id( 'shop-order' ) ), true ) || ! ( $editor instanceof WP_Post || $editor instanceof WC_Order ) ) {
			return;
		}
		$order = $editor instanceof WC_Order ? $editor : wc_get_order( $editor->ID );
		if ( ! $order instanceof WC_Order || ( EPAY_PAYCENTER_GATEWAY_ID !== $order->get_payment_method() && '' === (string) $order->get_meta( '_epay_merchant_reference', true ) ) ) {
			return;
		}
		foreach ( array_unique( array( 'shop_order', wc_get_page_screen_id( 'shop-order' ) ) ) as $screen ) {
			add_meta_box( 'epay-order-payment', __( 'ePay payment', 'resilient-gateway-for-epay-paycenter' ), array( __CLASS__, 'render' ), $screen, 'normal', 'high' );
		}
	}

	/**
	 * Normalize the legacy post and HPOS order editor contracts.
	 *
	 * @param WP_Post|WC_Order $editor Editor's order or post.
	 */
	public static function render( $editor ): void {
		$order = $editor instanceof WC_Order ? $editor : wc_get_order( $editor->ID );
		if ( ! $order instanceof WC_Order || ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return;
		}
		echo '<div class="epay-order-payment">';
		try {
			$attempts = Epay_Paycenter_Order_Evidence::for_order( $order );
		} catch ( RuntimeException $error ) {
			Epay_Paycenter_Review_Admin::unavailable();
			echo '</div>';
			return;
		}
		if ( ! $attempts ) {
			echo '<p>' . esc_html__( 'No ePay attempts recorded for this order.', 'resilient-gateway-for-epay-paycenter' ) . '</p>';
			self::reviews( $order );
			echo '</div>';
			return;
		}
		echo '<p class="description">' . esc_html__( 'Stored payment evidence, not a live bank check. AdminTool may require sign-in.', 'resilient-gateway-for-epay-paycenter' ) . '</p>';
		self::attempt( array_shift( $attempts ), $order );
		if ( $attempts ) {
			echo '<details><summary>' . esc_html__( 'Earlier attempts', 'resilient-gateway-for-epay-paycenter' ) . ' (' . count( $attempts ) . ')</summary>';
			foreach ( $attempts as $attempt ) {
				self::attempt( $attempt, $order );
			}
			echo '</details>';
		}
		self::reviews( $order );
		echo '</div>';
	}

	/**
	 * Present recorded responses separately from the local recovery state.
	 *
	 * @param array    $attempt Non-secret evidence.
	 * @param WC_Order $order Current order, for currency labels only.
	 * @phpstan-param OrderEvidence $attempt
	 */
	private static function attempt( array $attempt, WC_Order $order ): void {
		$unknown = __( 'Not recorded', 'resilient-gateway-for-epay-paycenter' );
		echo '<div class="epay-attempt">';
		Epay_Paycenter_Review_Admin::reference( $attempt['reference'] );
		if ( $attempt['settled'] ) {
			echo '<p><strong>' . esc_html__( 'Recorded paid reference', 'resilient-gateway-for-epay-paycenter' ) . '</strong></p>';
		}
		$currency = Epay_Paycenter_Currencies::to_numeric( $order->get_currency() ) === $attempt['currency_code'] ? $order->get_currency() : 'ISO ' . $attempt['currency_code'];
		$fields   = array(
			__( 'Attempt issued (UTC)', 'resilient-gateway-for-epay-paycenter' ) => $attempt['issued_at'],
			__( 'Attempt amount', 'resilient-gateway-for-epay-paycenter' ) => '' !== $attempt['amount'] ? $attempt['amount'] . ' ' . $currency : '',
			__( 'Payment method', 'resilient-gateway-for-epay-paycenter' ) => 'iris' === $attempt['method'] ? 'IRIS' : ( 'card' === $attempt['method'] ? __( 'Card', 'resilient-gateway-for-epay-paycenter' ) : '' ),
			__( 'Recorded bank response', 'resilient-gateway-for-epay-paycenter' ) => $attempt['bank_status'] . ( '' !== $attempt['response_code'] ? ' / ' . $attempt['response_code'] : '' ),
			__( 'Recovery state', 'resilient-gateway-for-epay-paycenter' ) => self::recovery_label( $attempt['recovery_state'] ),
			__( 'Last bank check (UTC)', 'resilient-gateway-for-epay-paycenter' ) => $attempt['last_checked'],
		);
		echo '<dl>';
		foreach ( $fields as $label => $value ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( '' === $value ? $unknown : $value ) . '</dd>';
		}
		echo '</dl><p>';
		if ( '' !== $attempt['detail_url'] ) {
			echo '<a class="button" href="' . esc_url( $attempt['detail_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View transaction', 'resilient-gateway-for-epay-paycenter' ) . '</a> ';
		}
		echo '<a href="' . esc_url( $attempt['search_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open AdminTool', 'resilient-gateway-for-epay-paycenter' ) . '</a></p>';
		if ( '' === $attempt['detail_url'] ) {
			echo '<p class="description">' . esc_html__( 'Copy the reference and paste it into AdminTool’s transaction search.', 'resilient-gateway-for-epay-paycenter' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Explain stored worker states in the staff member's language.
	 *
	 * @param string $state Stored recovery state.
	 */
	private static function recovery_label( string $state ): string {
		$labels = array(
			'untracked'   => __( 'Not scheduled', 'resilient-gateway-for-epay-paycenter' ),
			'pending'     => __( 'Awaiting bank result', 'resilient-gateway-for-epay-paycenter' ),
			'query_error' => __( 'Check problem', 'resilient-gateway-for-epay-paycenter' ),
			'unresolved'  => __( 'No final bank result', 'resilient-gateway-for-epay-paycenter' ),
			'paid'        => __( 'Bank confirmed payment', 'resilient-gateway-for-epay-paycenter' ),
			'declined'    => __( 'Bank declined payment', 'resilient-gateway-for-epay-paycenter' ),
			'local_paid'  => __( 'Payment recorded locally', 'resilient-gateway-for-epay-paycenter' ),
		);
		return $labels[ $state ] ?? __( 'Not recorded', 'resilient-gateway-for-epay-paycenter' );
	}

	/**
	 * Provide in-place acknowledgement without submitting the order form.
	 *
	 * @param WC_Order $order Only this order's cases belong in its editor.
	 */
	private static function reviews( WC_Order $order ): void {
		echo '<div class="epay-order-reviews"><h3>' . esc_html__( 'Payment reviews', 'resilient-gateway-for-epay-paycenter' ) . '</h3><p class="epay-review-feedback" role="status" aria-live="polite" tabindex="-1"></p>';
		try {
			$cases = Epay_Paycenter_Review::cases( $order->get_id() );
		} catch ( RuntimeException $error ) {
			Epay_Paycenter_Review_Admin::unavailable();
			echo '</div>';
			return;
		}
		$count = 0;
		foreach ( $cases as $key => $case ) {
			if ( $order->get_id() !== $case['item']['order_id'] || in_array( $case['group'], array( 'reviewed', 'resolved' ), true ) ) {
				continue;
			}
			++$count;
			echo '<div class="epay-review-row" data-review-key="' . esc_attr( $key ) . '"><strong>' . esc_html( $case['item']['reference'] ) . '</strong><p>' . esc_html( Epay_Paycenter_Review_Admin::reason( $case['item']['type'] ) ) . '</p>';
			Epay_Paycenter_Review_Admin::review_action( $key, $case, false );
			echo '</div>';
		}
		echo '<p class="epay-review-empty"' . ( $count ? ' hidden' : '' ) . '>' . esc_html__( 'No unreviewed payment exceptions.', 'resilient-gateway-for-epay-paycenter' ) . '</p></div>';
	}
}
