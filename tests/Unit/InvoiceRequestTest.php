<?php
/**
 * Invoice request built from an order.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce\Tests\Unit;

use CoinsPay\WooCommerce\InvoiceRequest;
use PHPUnit\Framework\TestCase;

final class InvoiceRequestTest extends TestCase {

	private function order( array $overrides = array() ) {
		return $overrides + array(
			'currency'   => 'usd',
			'amount'     => '49.9',
			'number'     => '1042',
			'items'      => array(
				array(
					'name'     => 'Espresso beans &amp; grinder',
					'quantity' => 2,
				),
				array(
					'name'     => '<b>Delivery</b>',
					'quantity' => 1,
				),
			),
			'return_url' => 'https://shop.example/checkout/order-received/1042/?key=wc_order_AbC123',
		);
	}

	private function options( array $overrides = array() ) {
		return $overrides + array(
			'lifetime'           => 60,
			'stablecoins_only'   => false,
			'buyer_pays_fee'     => false,
			'buyer_pays_network' => false,
			'auto_return'        => true,
		);
	}

	public function test_amount_follows_the_currency_precision() {
		$this->assertSame( '49.9', InvoiceRequest::format_amount( '49.90', 2 ) );
		$this->assertSame( '10', InvoiceRequest::format_amount( '10.00', 2 ) );
		$this->assertSame( '1235', InvoiceRequest::format_amount( '1234.5', 0 ) );
		$this->assertSame( '0.1', InvoiceRequest::format_amount( '0.1', 3 ) );
		$this->assertSame( '19.99', InvoiceRequest::format_amount( '19.989', 2 ) );
		$this->assertSame( '0.00012345', InvoiceRequest::format_amount( '0.00012345', 8 ) );
		$this->assertSame( '49.9', InvoiceRequest::normalize( '49.900' ) );
		$this->assertSame( '0', InvoiceRequest::normalize( '0.000' ) );
		$this->assertSame( '100', InvoiceRequest::normalize( '100' ) );
	}

	public function test_body_of_an_order() {
		$body = InvoiceRequest::body( $this->order(), $this->options() );
		$this->assertSame(
			array(
				'currency'         => 'USD',
				'amount'           => '49.9',
				'reference'        => '1042',
				'lifetime_minutes' => 60,
				'description'      => 'Espresso beans & grinder × 2, Delivery × 1',
				'return_url'       => 'https://shop.example/checkout/order-received/1042/?key=wc_order_AbC123',
				'auto_return'      => true,
			),
			$body
		);
	}

	public function test_flags_and_limits() {
		$body = InvoiceRequest::body(
			$this->order( array( 'items' => array() ) ),
			$this->options(
				array(
					'lifetime'           => 5,
					'stablecoins_only'   => true,
					'buyer_pays_fee'     => true,
					'buyer_pays_network' => true,
					'auto_return'        => false,
				)
			)
		);
		$this->assertSame( 15, $body['lifetime_minutes'] );
		$this->assertTrue( $body['stablecoins_only'] );
		$this->assertTrue( $body['buyer_pays_fee'] );
		$this->assertTrue( $body['buyer_pays_network'] );
		$this->assertArrayNotHasKey( 'auto_return', $body );
		$this->assertArrayNotHasKey( 'description', $body );

		$this->assertSame( 1440, InvoiceRequest::body( $this->order(), $this->options( array( 'lifetime' => 100000 ) ) )['lifetime_minutes'] );
	}

	public function test_a_too_long_return_link_is_left_out_with_auto_return() {
		$body = InvoiceRequest::body( $this->order( array( 'return_url' => 'https://shop.example/?key=' . str_repeat( 'a', 500 ) ) ), $this->options() );
		$this->assertArrayNotHasKey( 'return_url', $body );
		$this->assertArrayNotHasKey( 'auto_return', $body );
	}

	public function test_long_texts_are_cut_by_characters() {
		$items = array();
		for ( $i = 0; $i < 80; $i++ ) {
			$items[] = array(
				'name'     => 'Кофейные зёрна сорта №' . $i,
				'quantity' => 1,
			);
		}
		$body = InvoiceRequest::body( $this->order( array( 'items' => $items, 'number' => str_repeat( '9', 300 ) ) ), $this->options() );
		$this->assertSame( 1000, mb_strlen( $body['description'], 'UTF-8' ) );
		$this->assertSame( '…', mb_substr( $body['description'], -1, 1, 'UTF-8' ) );
		$this->assertSame( 190, mb_strlen( $body['reference'], 'UTF-8' ) );
		$this->assertTrue( mb_check_encoding( $body['description'], 'UTF-8' ) );
	}

	public function test_idempotency_key_is_stable_per_body_and_attempt() {
		$body = InvoiceRequest::body( $this->order(), $this->options() );
		$key  = InvoiceRequest::idempotency_key( 'https://shop.example', 1042, 1, $body );

		$this->assertMatchesRegularExpression( '/^[\x21-\x7e]{8,128}$/', $key );
		$this->assertSame( $key, InvoiceRequest::idempotency_key( 'https://shop.example', 1042, 1, $body ) );
		$this->assertNotSame( $key, InvoiceRequest::idempotency_key( 'https://shop.example', 1042, 2, $body ) );
		$this->assertNotSame( $key, InvoiceRequest::idempotency_key( 'https://staging.shop.example', 1042, 1, $body ) );
		$changed = InvoiceRequest::body( $this->order( array( 'amount' => '59.9' ) ), $this->options() );
		$this->assertNotSame( $key, InvoiceRequest::idempotency_key( 'https://shop.example', 1042, 1, $changed ) );
	}

	public function test_order_key_from_the_return_link() {
		$this->assertSame( 'wc_order_AbC123', InvoiceRequest::order_key( 'https://shop.example/checkout/order-received/1042/?key=wc_order_AbC123' ) );
		$this->assertSame( 'wc_order_58d2', InvoiceRequest::order_key( 'https://shop.example/?page_id=7&order-received=1042&key=wc_order_58d2' ) );
		$this->assertSame( '', InvoiceRequest::order_key( 'https://shop.example/thanks' ) );
		$this->assertSame( '', InvoiceRequest::order_key( 'https://shop.example/?key=%3Cscript%3E' ) );
		$this->assertSame( '', InvoiceRequest::order_key( '' ) );
	}
}
