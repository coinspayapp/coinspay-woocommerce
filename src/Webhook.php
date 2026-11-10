<?php
/**
 * CoinsPay webhook receiver.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce;

use CoinsPay\WooCommerce\Api\ApiError;
use CoinsPay\WooCommerce\Api\Signature;

defined( 'ABSPATH' ) || exit;

/**
 * Takes CoinsPay events at WC()->api_request_url( 'coinspay' ).
 *
 * The event only says that something happened: the plugin fetches the invoice
 * itself and applies its current state. So the order of events does not
 * matter, and a sample event from the dashboard (a made-up invoice) changes
 * nothing. Answers: 2xx — taken (CoinsPay stops), 401 — bad signature, 5xx —
 * try again later.
 */
final class Webhook {

	/**
	 * Option: unix time of the last webhook.test event.
	 */
	const LAST_TEST_OPTION = 'coinspay_wc_last_webhook_test';

	/**
	 * Hooks the receiver in.
	 */
	public static function register() {
		add_action( 'woocommerce_api_coinspay', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Address to enter in the CoinsPay dashboard.
	 *
	 * @return string
	 */
	public static function url() {
		return WC()->api_request_url( Plugin::GATEWAY_ID );
	}

	/**
	 * Handles one delivery and ends the request.
	 */
	public static function handle() {
		// The signature is over the raw bytes; php://input is the only place they are.
		$body   = (string) file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$header = isset( $_SERVER['HTTP_X_CPAY_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_CPAY_SIGNATURE'] ) ) : '';

		$secret = Settings::webhook_secret();
		if ( '' === $secret ) {
			self::respond( 401, 'webhook secret is not set in the plugin' );
		}
		if ( ! Signature::verify( $body, $header, $secret ) ) {
			Log::error( 'webhook rejected: bad or stale signature' );
			self::respond( 401, 'bad signature' );
		}

		$event = json_decode( $body, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			self::respond( 400, 'not an event' );
		}

		$type = (string) $event['type'];
		if ( 'webhook.test' === $type ) {
			update_option( self::LAST_TEST_OPTION, time(), false );
			self::respond( 200, 'test event received' );
		}

		// Test and live events share the webhook of the shop; a test key handles only test ones.
		if ( ! empty( $event['test'] ) !== Settings::is_test_mode() ) {
			self::respond( 200, 'ignored: other mode' );
		}

		$invoice_id = self::invoice_id( $event );
		if ( '' === $invoice_id ) {
			self::respond( 200, 'ignored: no invoice' );
		}

		try {
			$result = self::sync( $invoice_id );
		} catch ( ApiError $e ) {
			if ( $e->is_not_found() ) {
				self::respond( 200, 'ignored: unknown invoice' );
			}
			Log::error( sprintf( 'webhook %s for invoice %s: %s', $type, $invoice_id, $e->summary() ) );
			self::respond( 503, 'CoinsPay API is not available, retry later' );
		} catch ( \Exception $e ) {
			Log::error( sprintf( 'webhook %s for invoice %s failed: %s', $type, $invoice_id, $e->getMessage() ) );
			self::respond( 500, 'processing failed, retry later' );
		}
		self::respond( 200, $result );
	}

	/**
	 * Fetches the invoice and applies it to its order.
	 *
	 * @param string $invoice_id Invoice id.
	 * @return string What happened.
	 */
	public static function sync( $invoice_id ) {
		$invoice = Settings::client()->invoice( $invoice_id );
		$order   = self::find_order( $invoice );
		if ( ! $order ) {
			return 'ignored: not an order of this shop';
		}
		return OrderSync::apply( $order, $invoice );
	}

	/**
	 * The order of an invoice: from the poll list, else by the order key in the
	 * return link — both work the same in WooCommerce 3.x and with order tables.
	 * The invoice must be one the order created.
	 *
	 * @param array $invoice Invoice.
	 * @return \WC_Order|null
	 */
	public static function find_order( array $invoice ) {
		$invoice_id = isset( $invoice['id'] ) ? (string) $invoice['id'] : '';
		$order_id   = Poller::order_of( $invoice_id );
		if ( ! $order_id && ! empty( $invoice['return_url'] ) ) {
			$key = InvoiceRequest::order_key( (string) $invoice['return_url'] );
			if ( '' !== $key ) {
				$order_id = (int) wc_get_order_id_by_order_key( $key );
			}
		}
		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order || Plugin::GATEWAY_ID !== $order->get_payment_method() ) {
			return null;
		}
		$known = OrderSync::known_invoices( $order );
		return isset( $known[ $invoice_id ] ) ? $order : null;
	}

	/**
	 * Invoice id an event is about.
	 *
	 * @param array $event Event.
	 * @return string
	 */
	public static function invoice_id( array $event ) {
		$data = isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : array();
		if ( isset( $data['invoice']['id'] ) && is_string( $data['invoice']['id'] ) ) {
			return $data['invoice']['id'];
		}
		if ( isset( $data['deposit']['invoice_id'] ) && is_string( $data['deposit']['invoice_id'] ) ) {
			return $data['deposit']['invoice_id'];
		}
		return '';
	}

	/**
	 * Answers and ends the request.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Short reason.
	 */
	private static function respond( $status, $message ) {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode(
			array(
				'ok'      => $status < 300,
				'message' => $message,
			)
		);
		exit;
	}
}
