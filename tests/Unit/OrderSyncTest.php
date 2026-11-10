<?php
/**
 * Invoice state → order state.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce\Tests\Unit;

use CoinsPay\WooCommerce\OrderSync;
use PHPUnit\Framework\TestCase;

final class OrderSyncTest extends TestCase {

	const INVOICE_ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

	private function invoice( array $overrides = array() ) {
		return $overrides + array(
			'id'              => self::INVOICE_ID,
			'number'          => 12,
			'status'          => 'open',
			'test'            => false,
			'currency'        => 'USD',
			'amount'          => '49.9',
			'amount_paid'     => '0',
			'amount_pending'  => '0',
			'amount_due'      => '49.9',
			'amount_overpaid' => null,
			'paid_late'       => false,
			'paid_manually'   => false,
			'payments'        => array(),
		);
	}

	private function order( array $overrides = array() ) {
		return $overrides + array(
			'is_paid'             => false,
			'is_cancelled'        => false,
			'cancelled_by_plugin' => false,
			'transaction_id'      => '',
			'currency'            => 'USD',
			'expected_amount'     => '49.9',
			'is_current'          => true,
			'cancel_on_expiry'    => true,
			'test_allowed'        => false,
		);
	}

	private function payment( $status = 'confirmed' ) {
		return array(
			'deposit_id' => 'd1',
			'status'     => $status,
			'chain'      => 'tron',
			'asset'      => 'USDT',
			'amount'     => '49.9',
			'tx_hash'    => 'abc123',
		);
	}

	public function test_test_payment_completes_only_an_order_of_a_shop_manager() {
		$paid = $this->invoice(
			array(
				'status'      => 'paid',
				'test'        => true,
				'amount_paid' => '49.9',
				'amount_due'  => '0',
				'payments'    => array( $this->payment() ),
			)
		);

		$decision = OrderSync::decide( $paid, $this->order() );
		$this->assertSame( OrderSync::ON_HOLD, $decision['action'] );
		$this->assertStringContainsString( 'no money was received', $decision['note'] );
		$this->assertTrue( $decision['final'] );

		$this->assertSame( OrderSync::COMPLETE, OrderSync::decide( $paid, $this->order( array( 'test_allowed' => true ) ) )['action'] );
		$this->assertSame( OrderSync::COMPLETE, OrderSync::decide( array( 'test' => false ) + $paid, $this->order() )['action'] );
	}

	public function test_payment_of_an_order_cancelled_by_the_store_puts_it_on_hold() {
		$paid = $this->invoice(
			array(
				'status'      => 'paid',
				'amount_paid' => '49.9',
				'amount_due'  => '0',
				'payments'    => array( $this->payment() ),
			)
		);

		$decision = OrderSync::decide( $paid, $this->order( array( 'is_cancelled' => true ) ) );
		$this->assertSame( OrderSync::ON_HOLD, $decision['action'] );
		$this->assertStringContainsString( 'cancelled in the store', $decision['note'] );
		$this->assertTrue( $decision['final'] );

		// The plugin cancelled it when the invoice expired: a late payment completes the order.
		$late = array( 'paid_late' => true ) + $paid;
		$this->assertSame( OrderSync::COMPLETE, OrderSync::decide( $late, $this->order( array( 'is_cancelled' => true, 'cancelled_by_plugin' => true ) ) )['action'] );
	}

	public function test_paid_invoice_is_checked_against_the_order_total_now() {
		$paid = $this->invoice(
			array(
				'status'      => 'paid',
				'amount'      => '12.5',
				'amount_paid' => '12.5',
				'amount_due'  => '0',
				'payments'    => array( $this->payment() ),
			)
		);

		// The store raised the order total after the invoice was made.
		$decision = OrderSync::decide( $paid, $this->order( array( 'expected_amount' => '100' ) ) );
		$this->assertSame( OrderSync::ON_HOLD, $decision['action'] );
		$this->assertStringContainsString( '12.5 USD differs from the order (100 USD)', $decision['note'] );
	}

	public function test_open_unpaid_invoice_changes_nothing() {
		$this->assertSame( OrderSync::NONE, OrderSync::decide( $this->invoice(), $this->order() )['action'] );
		$this->assertFalse( OrderSync::decide( $this->invoice(), $this->order() )['final'] );
	}

	public function test_payment_on_its_way_puts_the_order_on_hold() {
		$decision = OrderSync::decide(
			$this->invoice(
				array(
					'amount_pending' => '49.9',
					'amount_due'     => '0',
					'payments'       => array( $this->payment( 'pending' ) ),
				)
			),
			$this->order()
		);
		$this->assertSame( OrderSync::ON_HOLD, $decision['action'] );
		$this->assertStringContainsString( 'on its way', $decision['note'] );
		$this->assertStringContainsString( '49.9 USDT, TRON, tx abc123', $decision['note'] );
		$this->assertFalse( $decision['final'] );
	}

	public function test_partial_payment_puts_the_order_on_hold_with_the_rest() {
		$decision = OrderSync::decide( $this->invoice( array( 'amount_paid' => '20', 'amount_due' => '29.9' ) ), $this->order() );
		$this->assertSame( OrderSync::ON_HOLD, $decision['action'] );
		$this->assertStringContainsString( '20 USD received, 29.9 USD left', $decision['note'] );
	}

	public function test_rest_on_its_way_after_a_partial_payment_is_not_called_partial() {
		$decision = OrderSync::decide(
			$this->invoice(
				array(
					'amount_paid'    => '20',
					'amount_pending' => '29.9',
					'amount_due'     => '0',
					'payments'       => array( $this->payment( 'confirmed' ), $this->payment( 'pending' ) ),
				)
			),
			$this->order()
		);
		$this->assertSame( OrderSync::ON_HOLD, $decision['action'] );
		$this->assertStringContainsString( '20 USD received, the rest is on its way', $decision['note'] );
		$this->assertStringNotContainsString( 'left', $decision['note'] );

		// Part paid, part on its way, still something to pay: partially paid.
		$partial = OrderSync::decide( $this->invoice( array( 'amount_paid' => '10', 'amount_pending' => '10', 'amount_due' => '29.9' ) ), $this->order() );
		$this->assertStringContainsString( '10 USD received, 29.9 USD left', $partial['note'] );
	}

	public function test_paid_invoice_completes_the_order() {
		$decision = OrderSync::decide(
			$this->invoice(
				array(
					'status'      => 'paid',
					'amount_paid' => '49.9',
					'amount_due'  => '0',
					'payments'    => array( $this->payment() ),
				)
			),
			$this->order()
		);
		$this->assertSame( OrderSync::COMPLETE, $decision['action'] );
		$this->assertTrue( $decision['final'] );
		$this->assertStringContainsString( 'invoice No. 12: 49.9 USDT, TRON', $decision['note'] );
	}

	public function test_late_overpaid_and_manual_payments_are_noted() {
		$decision = OrderSync::decide(
			$this->invoice(
				array(
					'status'          => 'paid',
					'amount_paid'     => '55',
					'amount_overpaid' => '5.1',
					'paid_late'       => true,
					'paid_manually'   => true,
				)
			),
			$this->order()
		);
		$this->assertSame( OrderSync::COMPLETE, $decision['action'] );
		$this->assertStringContainsString( 'after the invoice expired', $decision['note'] );
		$this->assertStringContainsString( 'Overpaid by 5.1 USD', $decision['note'] );
		$this->assertStringContainsString( 'marked as paid manually', $decision['note'] );
	}

	public function test_paid_invoice_with_another_amount_or_currency_holds_the_order() {
		$paid = $this->invoice( array( 'status' => 'paid' ) );
		$this->assertSame( OrderSync::ON_HOLD, OrderSync::decide( $paid, $this->order( array( 'expected_amount' => '59.9' ) ) )['action'] );
		$this->assertSame( OrderSync::ON_HOLD, OrderSync::decide( $paid, $this->order( array( 'currency' => 'EUR' ) ) )['action'] );
		$this->assertStringContainsString( 'differs from the order (59.9 USD)', OrderSync::decide( $paid, $this->order( array( 'expected_amount' => '59.9' ) ) )['note'] );
	}

	public function test_second_paid_invoice_of_a_paid_order_is_only_noted() {
		$paid = $this->invoice( array( 'status' => 'paid' ) );
		$same = OrderSync::decide( $paid, $this->order( array( 'is_paid' => true, 'transaction_id' => self::INVOICE_ID ) ) );
		$this->assertSame( OrderSync::NONE, $same['action'] );

		$other = OrderSync::decide( $paid, $this->order( array( 'is_paid' => true, 'transaction_id' => 'another-invoice' ) ) );
		$this->assertSame( OrderSync::NOTE, $other['action'] );
		$this->assertStringContainsString( 'Refund the buyer', $other['note'] );
	}

	public function test_expired_unpaid_invoice_cancels_the_order_or_only_notes() {
		$expired = $this->invoice( array( 'status' => 'expired' ) );
		$this->assertSame( OrderSync::CANCEL, OrderSync::decide( $expired, $this->order() )['action'] );
		$this->assertSame( OrderSync::NOTE, OrderSync::decide( $expired, $this->order( array( 'cancel_on_expiry' => false ) ) )['action'] );
		// An older invoice of the order ran out while the buyer pays a newer one.
		$this->assertSame( OrderSync::NONE, OrderSync::decide( $expired, $this->order( array( 'is_current' => false ) ) )['action'] );
	}

	public function test_cancelled_unpaid_invoice_cancels_the_order() {
		$decision = OrderSync::decide( $this->invoice( array( 'status' => 'cancelled' ) ), $this->order() );
		$this->assertSame( OrderSync::CANCEL, $decision['action'] );
		$this->assertStringContainsString( 'cancelled in the CoinsPay dashboard', $decision['note'] );
	}

	public function test_expired_or_cancelled_partially_paid_invoice_leaves_the_decision_to_the_merchant() {
		foreach ( array( 'expired', 'cancelled' ) as $status ) {
			$decision = OrderSync::decide( $this->invoice( array( 'status' => $status, 'amount_paid' => '20' ) ), $this->order() );
			$this->assertSame( OrderSync::ON_HOLD, $decision['action'], $status );
			$this->assertStringContainsString( '20 USD of 49.9 USD', $decision['note'] );
			$this->assertTrue( $decision['final'] );
		}
	}

	public function test_paid_order_is_not_touched_by_other_states() {
		foreach ( array( 'open', 'expired', 'cancelled' ) as $status ) {
			$this->assertSame( OrderSync::NONE, OrderSync::decide( $this->invoice( array( 'status' => $status ) ), $this->order( array( 'is_paid' => true ) ) )['action'] );
		}
	}

	public function test_fingerprint_changes_with_what_matters_only() {
		$base = OrderSync::fingerprint( $this->invoice() );
		$this->assertSame( $base, OrderSync::fingerprint( $this->invoice( array( 'amount_paid' => '0.00' ) ) ) );
		$this->assertSame( $base, OrderSync::fingerprint( $this->invoice( array( 'expires_at' => '2026-10-29T00:00:00Z' ) ) ) );
		$this->assertNotSame( $base, OrderSync::fingerprint( $this->invoice( array( 'amount_pending' => '49.9' ) ) ) );
		$this->assertNotSame( $base, OrderSync::fingerprint( $this->invoice( array( 'status' => 'expired' ) ) ) );
		$this->assertNotSame( $base, OrderSync::fingerprint( $this->invoice( array( 'payments' => array( $this->payment( 'pending' ) ) ) ) ) );
		$this->assertNotSame(
			OrderSync::fingerprint( $this->invoice( array( 'payments' => array( $this->payment( 'pending' ) ) ) ) ),
			OrderSync::fingerprint( $this->invoice( array( 'payments' => array( $this->payment( 'confirmed' ) ) ) ) )
		);
	}
}
