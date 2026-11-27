<?php
/**
 * Fields the webhook receiver takes from an event.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce\Tests\Unit;

use CoinsPay\WooCommerce\Webhook;
use PHPUnit\Framework\TestCase;

/**
 * Only an event type of CoinsPay's shape and an invoice UUID leave the event:
 * anything else is refused before it reaches the API, the order or the log.
 */
final class WebhookTest extends TestCase {

	const INVOICE = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

	public function test_takes_the_event_types_of_coinspay() {
		foreach ( array( 'invoice.paid', 'invoice.partially_paid', 'invoice.expired', 'invoice.cancelled', 'payout.failed', 'webhook.test' ) as $type ) {
			$this->assertSame( $type, Webhook::event_type( array( 'type' => $type ) ) );
		}
	}

	public function test_refuses_an_event_type_of_another_shape() {
		$types = array(
			'',
			'Invoice.Paid',
			'invoice paid',
			'<script>alert(1)</script>',
			"invoice.paid\nfake log line",
			str_repeat( 'a', 65 ),
			array( 'invoice.paid' ),
			42,
			null,
		);
		foreach ( $types as $type ) {
			$this->assertSame( '', Webhook::event_type( array( 'type' => $type ) ), var_export( $type, true ) );
		}
		$this->assertSame( '', Webhook::event_type( array() ) );
	}

	public function test_takes_the_invoice_of_an_invoice_or_deposit_event() {
		$this->assertSame( self::INVOICE, Webhook::invoice_id( array( 'data' => array( 'invoice' => array( 'id' => self::INVOICE ) ) ) ) );
		$this->assertSame( self::INVOICE, Webhook::invoice_id( array( 'data' => array( 'deposit' => array( 'invoice_id' => self::INVOICE ) ) ) ) );
		// The API answers in lowercase; the order meta holds the id as the API gave it.
		$this->assertSame( self::INVOICE, Webhook::invoice_id( array( 'data' => array( 'invoice' => array( 'id' => strtoupper( self::INVOICE ) ) ) ) ) );
	}

	public function test_refuses_an_invoice_id_that_is_not_a_uuid() {
		$ids = array( '', '42', '../orders', self::INVOICE . "\n", '<b>' . self::INVOICE . '</b>', array( self::INVOICE ), 7 );
		foreach ( $ids as $id ) {
			$this->assertSame( '', Webhook::invoice_id( array( 'data' => array( 'invoice' => array( 'id' => $id ) ) ) ), var_export( $id, true ) );
		}
		$this->assertSame( '', Webhook::invoice_id( array( 'data' => 'invoice' ) ) );
		$this->assertSame( '', Webhook::invoice_id( array() ) );
	}
}
