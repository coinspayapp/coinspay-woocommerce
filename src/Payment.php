<?php
/**
 * Invoice of an order.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce;

use CoinsPay\WooCommerce\Api\ApiError;

defined( 'ABSPATH' ) || exit;

/**
 * Gives an order its CoinsPay invoice: the open one with the same amount if
 * there is one, a new one otherwise.
 */
final class Payment {

	/**
	 * Invoice to send the buyer to.
	 *
	 * @param \WC_Order $order Order.
	 * @return array Invoice: open, or paid when the order is already paid.
	 * @throws ApiError The invoice cannot be created.
	 */
	public static function invoice_for( $order ) {
		$client = Settings::client();
		$scale  = Currencies::scale( $order->get_currency() );
		$body   = InvoiceRequest::from_order( $order, $scale );

		$known    = OrderSync::known_invoices( $order );
		$current  = (string) $order->get_meta( OrderSync::META_CURRENT, true );
		$previous = $order->get_meta( OrderSync::META_SNAPSHOT, true );
		if ( '' !== $current && isset( $known[ $current ] )
			&& $known[ $current ]['amount'] === $body['amount']
			&& $known[ $current ]['currency'] === $body['currency']
			&& Settings::is_test_mode() === (bool) $known[ $current ]['test'] ) {
			try {
				$invoice = $client->invoice( $current );
				if ( in_array( $invoice['status'], array( 'open', 'paid' ), true ) ) {
					OrderSync::apply( $order, $invoice );
					return $invoice;
				}
			} catch ( ApiError $e ) {
				if ( ! $e->is_not_found() ) {
					throw $e;
				}
			}
		}

		// The same attempt and body give the same key: a repeated click gets the same invoice.
		$attempt = count( $known ) + 1;
		$invoice = $client->create_invoice( $body, InvoiceRequest::idempotency_key( home_url(), $order->get_id(), $attempt, $body ) );

		$order = wc_get_order( $order->get_id() );
		OrderSync::remember( $order, $invoice, $attempt );
		if ( empty( $invoice['test'] ) ) {
			/* translators: 1: invoice number, 2: amount with currency */
			$format = __( 'CoinsPay invoice No. %1$s created for %2$s. Waiting for the payment.', 'coinspay-for-woocommerce' );
		} else {
			/* translators: 1: invoice number, 2: amount with currency */
			$format = __( '[Test] CoinsPay invoice No. %1$s created for %2$s. Waiting for the payment.', 'coinspay-for-woocommerce' );
		}
		$order->add_order_note( sprintf( $format, $invoice['number'], $invoice['amount'] . ' ' . $invoice['currency'] ) );
		$order->save();
		Poller::track( $invoice['id'], $order->get_id() );
		self::cancel_replaced( $client, $previous );
		Log::info( sprintf( 'order %d: invoice %s created', $order->get_id(), $invoice['id'] ) );
		return $invoice;
	}

	/**
	 * Cancels the invoice a new one replaced (the order total, currency or mode
	 * changed), so the buyer cannot pay the old amount. Only an open invoice
	 * without money is cancelled; a paid one is still checked against the order
	 * total when it arrives.
	 *
	 * @param Api\Client $client   API client.
	 * @param mixed      $previous Snapshot of the replaced invoice, if any.
	 */
	private static function cancel_replaced( $client, $previous ) {
		if ( ! is_array( $previous ) || empty( $previous['id'] ) || ! isset( $previous['status'] ) || 'open' !== $previous['status'] ) {
			return;
		}
		foreach ( array( 'amount_paid', 'amount_pending' ) as $field ) {
			if ( isset( $previous[ $field ] ) && (float) $previous[ $field ] > 0 ) {
				return;
			}
		}
		try {
			$client->cancel_invoice( $previous['id'] );
			Log::info( sprintf( 'invoice %s replaced and cancelled', $previous['id'] ) );
		} catch ( \Exception $e ) {
			Log::info( sprintf( 'replaced invoice %s was not cancelled: %s', $previous['id'], $e->getMessage() ) );
		}
	}

	/**
	 * What to tell the buyer when the invoice cannot be created.
	 *
	 * @param ApiError $error Error.
	 * @return string
	 */
	public static function error_message( ApiError $error ) {
		switch ( $error->error_code() ) {
			case 'CURRENCY_NOT_SUPPORTED':
				return __( 'Crypto payment is not available for this currency. Please choose another payment method.', 'coinspay-for-woocommerce' );
			case 'QUOTE_UNAVAILABLE':
			case 'NETWORK':
			case 'RATE_LIMITED':
				return __( 'The crypto payment service is not answering right now. Please try again in a minute.', 'coinspay-for-woocommerce' );
			default:
				return __( 'The crypto payment could not be started. Please try again or choose another payment method.', 'coinspay-for-woocommerce' );
		}
	}
}
