<?php
/**
 * Applying an invoice to its order survives a failed write: the next webhook decides again.
 *
 * @package CoinsPay\WooCommerce
 */

namespace {
	// The WordPress functions apply() touches, answering as a site without settings would.
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $fallback = false ) {
			return $fallback;
		}
	}
	if ( ! function_exists( 'update_option' ) ) {
		function update_option( $name, $value, $autoload = null ) {
			return true;
		}
	}
	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $key ) {
			// The currency list CoinsPay sends, cached.
			return array( 'USD' => 2 );
		}
	}
	if ( ! function_exists( 'user_can' ) ) {
		function user_can( $user, $capability ) {
			return false;
		}
	}

	/**
	 * An order kept in memory. save() copies it to the "database"; reload() reads it back, as a
	 * fresh WC_Order does on the next request.
	 */
	final class Coinspay_Test_Order {

		/** @var array Saved state, shared by every copy of the order. */
		public static $saved = array();

		/** @var int How many times payment_complete() fails before it works. */
		public static $failures = 0;

		/** @var array */
		private $state;

		public function __construct() {
			$this->state = self::$saved;
		}

		public static function reload() {
			return new self();
		}

		public function get_id() {
			return 7;
		}

		public function get_meta( $key, $single = true ) {
			return isset( $this->state['meta'][ $key ] ) ? $this->state['meta'][ $key ] : '';
		}

		public function update_meta_data( $key, $value ) {
			$this->state['meta'][ $key ] = $value;
		}

		public function save() {
			self::$saved = $this->state;
		}

		public function is_paid() {
			return in_array( $this->state['status'], array( 'processing', 'completed' ), true );
		}

		public function has_status( $status ) {
			return in_array( $this->state['status'], (array) $status, true );
		}

		public function get_transaction_id() {
			return $this->state['transaction_id'];
		}

		public function get_currency() {
			return 'USD';
		}

		public function get_total() {
			return '49.90';
		}

		public function get_payment_method() {
			return 'coinspay';
		}

		public function get_customer_id() {
			return 0;
		}

		public function payment_complete( $transaction_id ) {
			if ( self::$failures > 0 ) {
				--self::$failures;
				throw new \RuntimeException( 'the order could not be saved' );
			}
			$this->state['status']         = 'processing';
			$this->state['transaction_id'] = $transaction_id;
			$this->save();
		}

		public function add_order_note( $note ) {
			$this->state['notes'][] = $note;
		}

		public function update_status( $status, $note = '' ) {
			$this->state['status']  = $status;
			$this->state['notes'][] = $note;
			$this->save();
		}
	}
}

namespace CoinsPay\WooCommerce\Tests\Unit {

	use CoinsPay\WooCommerce\OrderSync;
	use PHPUnit\Framework\TestCase;

	final class OrderSyncApplyTest extends TestCase {

		const INVOICE_ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

		private function apply( $order, array $invoice ) {
			$method = new \ReflectionMethod( OrderSync::class, 'apply_locked' );
			if ( PHP_VERSION_ID < 80100 ) {
				// Needed before PHP 8.1, deprecated in 8.5.
				$method->setAccessible( true );
			}
			return $method->invoke( null, $order, $invoice );
		}

		public function test_a_failed_order_write_does_not_lose_the_payment() {
			\Coinspay_Test_Order::$saved    = array(
				'status'         => 'pending',
				'transaction_id' => '',
				'notes'          => array(),
				'meta'           => array(
					OrderSync::META_INVOICES => array( self::INVOICE_ID => array( 'amount' => '49.9', 'currency' => 'USD' ) ),
					OrderSync::META_CURRENT  => self::INVOICE_ID,
				),
			);
			\Coinspay_Test_Order::$failures = 1;
			$invoice                        = array(
				'id'              => self::INVOICE_ID,
				'number'          => 12,
				'status'          => 'paid',
				'test'            => false,
				'currency'        => 'USD',
				'amount'          => '49.9',
				'amount_paid'     => '49.9',
				'amount_pending'  => '0',
				'amount_due'      => '0',
				'amount_overpaid' => null,
				'paid_late'       => false,
				'paid_manually'   => false,
				'payments'        => array(),
			);

			try {
				$this->apply( \Coinspay_Test_Order::reload(), $invoice );
				$this->fail( 'the write did not fail' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'the order could not be saved', $e->getMessage() );
			}
			$this->assertSame( 'pending', \Coinspay_Test_Order::$saved['status'] );

			// The next webhook comes with a fresh order: it must complete the payment, not see "no change".
			$this->assertSame( OrderSync::COMPLETE, $this->apply( \Coinspay_Test_Order::reload(), $invoice ) );
			$this->assertSame( 'processing', \Coinspay_Test_Order::$saved['status'] );
			$this->assertSame( self::INVOICE_ID, \Coinspay_Test_Order::$saved['transaction_id'] );
			$this->assertSame( 'no change', $this->apply( \Coinspay_Test_Order::reload(), $invoice ) );
		}
	}
}
