<?php
/**
 * CoinsPay box on the order screen.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce\Admin;

use CoinsPay\WooCommerce\Checkout;
use CoinsPay\WooCommerce\Compat;
use CoinsPay\WooCommerce\OrderSync;
use CoinsPay\WooCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the invoice of the order and adds the "Refresh from CoinsPay" order action.
 */
final class OrderPanel {

	/**
	 * Hooks in.
	 */
	public static function register() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ), 30 );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_coinspay_refresh', array( __CLASS__, 'refresh' ) );
	}

	/**
	 * Adds the box to both order screens: posts and order tables.
	 */
	public static function add_box() {
		foreach ( Compat::order_screens() as $screen ) {
			add_meta_box( 'coinspay-invoice', __( 'CoinsPay', 'coinspay-for-woocommerce' ), array( __CLASS__, 'render' ), $screen, 'side', 'default' );
		}
	}

	/**
	 * Box content.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order The post on the posts screen, the order with order tables.
	 */
	public static function render( $post_or_order ) {
		$order = $post_or_order instanceof \WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}
		$invoice = $order->get_meta( OrderSync::META_SNAPSHOT, true );
		if ( ! is_array( $invoice ) || empty( $invoice['id'] ) ) {
			echo '<p>' . esc_html__( 'No CoinsPay invoice for this order.', 'coinspay-for-woocommerce' ) . '</p>';
			return;
		}

		$statuses = array(
			'open'      => __( 'open', 'coinspay-for-woocommerce' ),
			'paid'      => __( 'paid', 'coinspay-for-woocommerce' ),
			'expired'   => __( 'expired', 'coinspay-for-woocommerce' ),
			'cancelled' => __( 'cancelled', 'coinspay-for-woocommerce' ),
		);
		$status   = isset( $statuses[ $invoice['status'] ] ) ? $statuses[ $invoice['status'] ] : (string) $invoice['status'];
		$money    = function ( $field ) use ( $invoice ) {
			return ( isset( $invoice[ $field ] ) ? (string) $invoice[ $field ] : '0' ) . ' ' . (string) $invoice['currency'];
		};

		echo '<p><strong>';
		/* translators: %s: invoice number */
		echo esc_html( sprintf( __( 'Invoice No. %s', 'coinspay-for-woocommerce' ), $invoice['number'] ) );
		echo '</strong>';
		if ( ! empty( $invoice['test'] ) ) {
			echo ' <mark class="order-status"><span>' . esc_html__( 'test', 'coinspay-for-woocommerce' ) . '</span></mark>';
		}
		echo '<br>' . esc_html( $status ) . '</p>';

		echo '<p>';
		/* translators: %s: amount with currency */
		echo esc_html( sprintf( __( 'Amount: %s', 'coinspay-for-woocommerce' ), $money( 'amount' ) ) ) . '<br>';
		/* translators: %s: amount with currency */
		echo esc_html( sprintf( __( 'Paid: %s', 'coinspay-for-woocommerce' ), $money( 'amount_paid' ) ) ) . '<br>';
		if ( ! empty( $invoice['amount_pending'] ) && preg_match( '/[1-9]/', (string) $invoice['amount_pending'] ) ) {
			/* translators: %s: amount with currency */
			echo esc_html( sprintf( __( 'On its way: %s', 'coinspay-for-woocommerce' ), $money( 'amount_pending' ) ) ) . '<br>';
		}
		if ( ! empty( $invoice['amount_overpaid'] ) ) {
			/* translators: %s: amount with currency */
			echo esc_html( sprintf( __( 'Overpaid: %s', 'coinspay-for-woocommerce' ), $money( 'amount_overpaid' ) ) ) . '<br>';
		}
		echo '</p>';

		if ( ! empty( $invoice['payments'] ) ) {
			echo '<ul>';
			foreach ( $invoice['payments'] as $payment ) {
				echo '<li>' . esc_html( trim( $payment['amount'] . ' ' . $payment['asset'] . ', ' . OrderSync::chain_name( $payment['chain'] ) ) );
				if ( '' !== $payment['tx_hash'] ) {
					echo '<br><code style="word-break:break-all">' . esc_html( $payment['tx_hash'] ) . '</code>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}

		if ( 'open' === $invoice['status'] && ! empty( $invoice['pay_url'] ) ) {
			printf( '<p><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>', esc_url( $invoice['pay_url'] ), esc_html__( 'Payment page', 'coinspay-for-woocommerce' ) );
		}
		echo '<p class="description">' . esc_html__( 'The order follows the invoice on its own. To fetch it right now, choose "Refresh from CoinsPay" in the order actions.', 'coinspay-for-woocommerce' ) . '</p>';
	}

	/**
	 * Adds "Refresh from CoinsPay" to the order actions.
	 *
	 * @param array          $actions Actions.
	 * @param \WC_Order|null $order   Order; passed since WooCommerce 6.x.
	 * @return array
	 */
	public static function order_actions( $actions, $order = null ) {
		if ( null === $order ) {
			global $theorder;
			$order = $theorder;
		}
		if ( $order && is_a( $order, 'WC_Order' ) && '' !== (string) $order->get_meta( OrderSync::META_CURRENT, true ) ) {
			$actions['coinspay_refresh'] = __( 'Refresh from CoinsPay', 'coinspay-for-woocommerce' );
		}
		return $actions;
	}

	/**
	 * Runs the action.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function refresh( $order ) {
		if ( Plugin::GATEWAY_ID === $order->get_payment_method() ) {
			Checkout::refresh( $order );
		}
	}
}
