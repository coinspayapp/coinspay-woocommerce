<?php
/**
 * Invoice request built from an order.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an order into the body of POST /invoices. The pure helpers take plain
 * values, so they run in unit tests without WordPress.
 */
final class InvoiceRequest {

	/**
	 * Field limits of the API.
	 */
	const MAX_DESCRIPTION = 1000;
	const MAX_REFERENCE   = 190;
	const MAX_RETURN_URL  = 500;

	/**
	 * Request body for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $scale Decimals of the order currency in CoinsPay.
	 * @return array
	 */
	public static function from_order( $order, $scale ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
			);
		}

		return self::body(
			array(
				'currency'   => $order->get_currency(),
				'amount'     => self::format_amount( (string) $order->get_total(), $scale ),
				'number'     => (string) $order->get_order_number(),
				'items'      => $items,
				'return_url' => $order->get_checkout_order_received_url(),
			),
			array(
				'lifetime'           => Settings::lifetime(),
				'stablecoins_only'   => Settings::flag( 'stablecoins_only' ),
				'buyer_pays_fee'     => Settings::flag( 'buyer_pays_fee' ),
				'buyer_pays_network' => Settings::flag( 'buyer_pays_network' ),
				'auto_return'        => Settings::flag( 'auto_return' ),
			)
		);
	}

	/**
	 * Request body from plain order data.
	 *
	 * @param array $order   {currency, amount, number, items: [{name, quantity}], return_url}.
	 * @param array $options {lifetime, stablecoins_only, buyer_pays_fee, buyer_pays_network, auto_return}.
	 * @return array
	 */
	public static function body( array $order, array $options ) {
		$lines = array();
		foreach ( $order['items'] as $item ) {
			$name = trim( html_entity_decode( wp_strip_all_tags( (string) $item['name'] ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' !== $name ) {
				$lines[] = sprintf( '%s × %s', $name, self::normalize( (string) $item['quantity'] ) );
			}
		}

		$body = array(
			'currency'         => strtoupper( (string) $order['currency'] ),
			'amount'           => (string) $order['amount'],
			// No title: the payment page then names the invoice "Order <reference>" in the buyer's language.
			'reference'        => self::truncate( (string) $order['number'], self::MAX_REFERENCE ),
			'lifetime_minutes' => Settings::clamp_lifetime( $options['lifetime'] ),
		);
		if ( ! empty( $lines ) ) {
			$body['description'] = self::truncate( implode( ', ', $lines ), self::MAX_DESCRIPTION );
		}

		// A link longer than the API takes would fail the whole invoice; without it the buyer still pays.
		$return_url = (string) $order['return_url'];
		if ( '' !== $return_url && strlen( $return_url ) <= self::MAX_RETURN_URL ) {
			$body['return_url'] = $return_url;
			if ( ! empty( $options['auto_return'] ) ) {
				$body['auto_return'] = true;
			}
		}

		foreach ( array( 'stablecoins_only', 'buyer_pays_fee', 'buyer_pays_network' ) as $flag ) {
			if ( ! empty( $options[ $flag ] ) ) {
				$body[ $flag ] = true;
			}
		}
		return $body;
	}

	/**
	 * Order total as the API takes it: at most $scale decimals, no trailing zeros.
	 *
	 * @param string $total Order total, e.g. 49.90.
	 * @param int    $scale Decimals of the currency.
	 * @return string
	 */
	public static function format_amount( $total, $scale ) {
		return self::normalize( number_format( (float) $total, max( 0, (int) $scale ), '.', '' ) );
	}

	/**
	 * Decimal string without trailing zeros: 49.90 → 49.9, 10.00 → 10.
	 *
	 * @param string $amount Decimal string.
	 * @return string
	 */
	public static function normalize( $amount ) {
		$amount = trim( (string) $amount );
		if ( false !== strpos( $amount, '.' ) ) {
			$amount = rtrim( rtrim( $amount, '0' ), '.' );
		}
		return '' === $amount ? '0' : $amount;
	}

	/**
	 * Cuts text to a number of characters, with an ellipsis.
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 * @return string
	 */
	public static function truncate( $text, $max ) {
		$text = (string) $text;
		if ( mb_strlen( $text, 'UTF-8' ) <= $max ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, $max - 1, 'UTF-8' ) ) . '…';
	}

	/**
	 * Idempotency key of an invoice request.
	 *
	 * The same order, attempt and body give the same key: a repeated "Place
	 * order" gets the invoice already created instead of a second one. The body
	 * is part of the key, so a changed total or setting never meets the
	 * "same key, other body" error. The site part keeps a staging copy on the
	 * same CoinsPay shop from getting invoices of the live site.
	 *
	 * @param string $site     Home URL.
	 * @param int    $order_id Order id.
	 * @param int    $attempt  Invoice number within the order, from 1.
	 * @param array  $body     Request body.
	 * @return string 8–128 printable ASCII characters.
	 */
	public static function idempotency_key( $site, $order_id, $attempt, array $body ) {
		return sprintf(
			'wc-%s-%d-%d-%s',
			substr( md5( (string) $site ), 0, 8 ),
			(int) $order_id,
			(int) $attempt,
			substr( sha1( (string) wp_json_encode( $body ) ), 0, 12 )
		);
	}

	/**
	 * Order key from the return link of an invoice: the link is the order
	 * received page, which carries ?key=wc_order_….
	 *
	 * @param string $return_url Invoice return_url.
	 * @return string Empty when there is no key.
	 */
	public static function order_key( $return_url ) {
		$query = wp_parse_url( (string) $return_url, PHP_URL_QUERY );
		if ( ! is_string( $query ) || '' === $query ) {
			return '';
		}
		parse_str( $query, $args );
		// Usually wc_order_…, but the key can be filtered (woocommerce_generate_order_key).
		return isset( $args['key'] ) && is_string( $args['key'] ) && preg_match( '/^[A-Za-z0-9_-]{6,64}$/', $args['key'] ) ? $args['key'] : '';
	}
}
