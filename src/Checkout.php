<?php
/**
 * Order received page and unpaid orders.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce;

use CoinsPay\WooCommerce\Api\ApiError;

defined( 'ABSPATH' ) || exit;

/**
 * What happens around the payment page: the buyer comes back to the order
 * received page, WooCommerce wants to cancel unpaid orders.
 */
final class Checkout {

	/**
	 * Hooks in.
	 */
	public static function register() {
		add_filter( 'woocommerce_cancel_unpaid_order', array( __CLASS__, 'keep_unpaid_order' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'refresh_on_return' ) );
		add_action( 'woocommerce_thankyou_' . Plugin::GATEWAY_ID, array( __CLASS__, 'thankyou_message' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'cancel_invoice' ), 10, 2 );
	}

	/**
	 * The store cancelled an order whose invoice is open: the invoice is
	 * cancelled too, so the buyer can no longer pay it. When the plugin cancels
	 * the order itself, the invoice has already expired or been cancelled.
	 *
	 * @param int       $order_id Order id.
	 * @param \WC_Order $order    Order, when WooCommerce passes it.
	 */
	public static function cancel_invoice( $order_id, $order = null ) {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order || Plugin::GATEWAY_ID !== $order->get_payment_method() || ! Settings::configured() ) {
			return;
		}
		$invoice_id = (string) $order->get_meta( OrderSync::META_CURRENT, true );
		if ( '' === $invoice_id || 'open' !== (string) $order->get_meta( OrderSync::META_STATUS, true ) ) {
			return;
		}
		try {
			Settings::client()->cancel_invoice( $invoice_id );
			Log::info( sprintf( 'order %d cancelled: invoice %s cancelled too', $order->get_id(), $invoice_id ) );
		} catch ( \Exception $e ) {
			Log::error( sprintf( 'order %d cancelled, but its invoice %s was not: %s', $order->get_id(), $invoice_id, $e->getMessage() ) );
		}
	}

	/**
	 * "Hold stock" of WooCommerce cancels unpaid orders after N minutes; the
	 * invoice may live longer. While it is open the order stays, the expiry of
	 * the invoice cancels it (OrderSync).
	 *
	 * @param bool      $cancel Cancel the order.
	 * @param \WC_Order $order  Order.
	 * @return bool
	 */
	public static function keep_unpaid_order( $cancel, $order ) {
		if ( $cancel && $order && Plugin::GATEWAY_ID === $order->get_payment_method()
			&& 'open' === (string) $order->get_meta( OrderSync::META_STATUS, true ) ) {
			return false;
		}
		return $cancel;
	}

	/**
	 * The buyer is back on the order received page: fetch the invoice right now,
	 * so the page shows the real state even if the webhook is late.
	 */
	public static function refresh_on_return() {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) || ! Settings::configured() ) {
			return;
		}
		$order_id = absint( get_query_var( 'order-received' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the order key is the proof, as in WooCommerce.
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! $order || Plugin::GATEWAY_ID !== $order->get_payment_method() || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			return;
		}
		self::refresh( $order, 10 );
	}

	/**
	 * Fetches the current invoice of an order and applies it.
	 *
	 * @param \WC_Order $order    Order.
	 * @param int       $throttle Seconds between fetches for the order; 0 — always.
	 * @return string What happened, or why nothing was done.
	 */
	public static function refresh( $order, $throttle = 0 ) {
		$invoice_id = (string) $order->get_meta( OrderSync::META_CURRENT, true );
		if ( '' === $invoice_id ) {
			return 'no invoice';
		}
		if ( $throttle > 0 ) {
			$lock = 'coinspay_wc_refresh_' . $order->get_id();
			if ( get_transient( $lock ) ) {
				return 'refreshed recently';
			}
			set_transient( $lock, 1, $throttle );
		}
		try {
			return OrderSync::apply( $order, Settings::client()->invoice( $invoice_id ) );
		} catch ( ApiError $e ) {
			Log::error( sprintf( 'refresh of order %d failed: %s', $order->get_id(), $e->summary() ) );
			return $e->error_code();
		} catch ( \Exception $e ) {
			Log::error( sprintf( 'refresh of order %d failed: %s', $order->get_id(), $e->getMessage() ) );
			return 'failed';
		}
	}

	/**
	 * A line about the payment on the order received page.
	 *
	 * @param int $order_id Order id.
	 */
	public static function thankyou_message( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}
		$invoice = $order->get_meta( OrderSync::META_SNAPSHOT, true );
		if ( ! is_array( $invoice ) || empty( $invoice['status'] ) ) {
			return;
		}

		$link    = '';
		$message = '';
		$status  = (string) $invoice['status'];
		$paid    = ! empty( $invoice['amount_paid'] ) && preg_match( '/[1-9]/', (string) $invoice['amount_paid'] );
		$pending = ! empty( $invoice['amount_pending'] ) && preg_match( '/[1-9]/', (string) $invoice['amount_pending'] );

		if ( 'open' === $status && $pending && ! preg_match( '/[1-9]/', (string) $invoice['amount_due'] ) ) {
			$message = __( 'Your payment has arrived and is waiting for network confirmations. The order will update on its own.', 'coinspay-for-woocommerce' );
		} elseif ( 'open' === $status && ( $paid || $pending ) ) {
			$message = __( 'The order is partially paid. Pay the rest on the payment page.', 'coinspay-for-woocommerce' );
			$link    = (string) $invoice['pay_url'];
		} elseif ( 'open' === $status ) {
			$message = __( 'The order is waiting for payment.', 'coinspay-for-woocommerce' );
			$link    = (string) $invoice['pay_url'];
		} elseif ( in_array( $status, array( 'expired', 'cancelled' ), true ) && ! $paid ) {
			$message = __( 'The time to pay for this order has run out. Please place the order again.', 'coinspay-for-woocommerce' );
		}
		if ( '' === $message ) {
			return;
		}

		echo '<p class="coinspay-payment-status">' . esc_html( $message );
		if ( '' !== $link ) {
			printf(
				' <a class="button" href="%s">%s</a>',
				esc_url( add_query_arg( 'lang', Compat::pay_page_language(), $link ) ),
				esc_html__( 'Go to the payment page', 'coinspay-for-woocommerce' )
			);
		}
		echo '</p>';
	}
}
