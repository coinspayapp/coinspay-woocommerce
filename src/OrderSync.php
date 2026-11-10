<?php
/**
 * Invoice state → order state.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Applies a CoinsPay invoice to its order. The webhook, the poller, the order
 * received page and the "Refresh from CoinsPay" action all fetch the current
 * invoice and come here, so the order follows the invoice whatever order the
 * events arrive in and however often they repeat.
 *
 * The rules are in decide(), on plain arrays (unit-tested); apply() runs them
 * on a WC_Order under a per-order lock.
 */
final class OrderSync {

	const NONE     = 'none';
	const NOTE     = 'note';
	const ON_HOLD  = 'on-hold';
	const COMPLETE = 'complete';
	const CANCEL   = 'cancel';

	/**
	 * Order meta: the current invoice id (the latest one of the order).
	 */
	const META_CURRENT = '_coinspay_invoice_id';

	/**
	 * Order meta: every invoice of the order → {amount, currency, number, test, attempt}.
	 */
	const META_INVOICES = '_coinspay_invoices';

	/**
	 * Order meta: the last fetched current invoice, for the order screen.
	 */
	const META_SNAPSHOT = '_coinspay_invoice';

	/**
	 * Order meta: status of the current invoice (open, paid, expired, cancelled).
	 */
	const META_STATUS = '_coinspay_invoice_status';

	/**
	 * Order meta: invoice id → state fingerprint already applied.
	 */
	const META_SEEN = '_coinspay_seen';

	/**
	 * Order meta: the invoice for which the plugin itself cancelled the order
	 * (expired or cancelled unpaid). A late payment of it completes the order;
	 * an order cancelled by the store is only put on hold.
	 */
	const META_CANCELLED_BY = '_coinspay_cancelled_by';

	/**
	 * What to do with the order for this invoice.
	 *
	 * @param array $invoice Invoice from the API.
	 * @param array $order   {is_paid, is_cancelled, cancelled_by_plugin, transaction_id, currency,
	 *                       expected_amount: the order total now, in the format of invoice amounts,
	 *                       is_current, cancel_on_expiry, test_allowed}.
	 * @return array {action, note, final}: final — the invoice will not change
	 *               any more except by a late payment.
	 */
	public static function decide( array $invoice, array $order ) {
		$status  = isset( $invoice['status'] ) ? (string) $invoice['status'] : '';
		$paid    = self::positive( self::value( $invoice, 'amount_paid' ) );
		$pending = self::positive( self::value( $invoice, 'amount_pending' ) );
		$number  = self::value( $invoice, 'number' );

		if ( 'paid' === $status ) {
			if ( ! empty( $order['is_paid'] ) ) {
				if ( self::value( $invoice, 'id' ) !== (string) $order['transaction_id'] ) {
					return self::result(
						self::NOTE,
						sprintf(
							/* translators: %s: CoinsPay invoice number */
							__( 'CoinsPay invoice No. %s of this order is paid as well, although the order was already paid. Refund the buyer from the CoinsPay dashboard if needed.', 'coinspay-for-woocommerce' ),
							$number
						),
						true
					);
				}
				return self::result( self::NONE, '', true );
			}

			// Money that came after the store cancelled the order does not ship it: the store decides.
			if ( ! empty( $order['is_cancelled'] ) && empty( $order['cancelled_by_plugin'] ) ) {
				return self::result(
					self::ON_HOLD,
					sprintf(
						/* translators: %s: CoinsPay invoice number */
						__( 'CoinsPay invoice No. %s is paid, but the order was cancelled in the store. Check the price and the stock, then ship the order or refund the buyer from the CoinsPay dashboard.', 'coinspay-for-woocommerce' ),
						$number
					),
					true
				);
			}

			// A test payment moves no money. It completes only orders of shop managers, who test
			// the store; anyone else got a test key left on a working store.
			if ( ! empty( $invoice['test'] ) && empty( $order['test_allowed'] ) ) {
				return self::result(
					self::ON_HOLD,
					__( 'This is a test payment: no money was received. The order was not placed by a shop manager, so it stays on hold. If the store takes real orders, replace the test API key with a live one.', 'coinspay-for-woocommerce' ),
					true
				);
			}

			$currency = strtoupper( self::value( $invoice, 'currency' ) );
			$amount   = InvoiceRequest::normalize( self::value( $invoice, 'amount' ) );
			if ( strtoupper( (string) $order['currency'] ) !== $currency || (string) $order['expected_amount'] !== $amount ) {
				return self::result(
					self::ON_HOLD,
					sprintf(
						/* translators: 1: invoice number, 2: invoice amount with currency, 3: order amount with currency */
						__( 'CoinsPay invoice No. %1$s is paid, but its amount %2$s differs from the order (%3$s). Check the order before shipping.', 'coinspay-for-woocommerce' ),
						$number,
						$amount . ' ' . $currency,
						$order['expected_amount'] . ' ' . strtoupper( (string) $order['currency'] )
					),
					true
				);
			}

			$note = sprintf(
				/* translators: 1: invoice number, 2: payments, e.g. "12.5 USDT, TRON" */
				__( 'Paid with CoinsPay, invoice No. %1$s: %2$s.', 'coinspay-for-woocommerce' ),
				$number,
				self::payments_line( $invoice )
			);
			if ( ! empty( $invoice['paid_manually'] ) ) {
				$note .= ' ' . __( 'The invoice was marked as paid manually in the CoinsPay dashboard.', 'coinspay-for-woocommerce' );
			}
			if ( ! empty( $invoice['paid_late'] ) ) {
				$note .= ' ' . __( 'The payment came after the invoice expired.', 'coinspay-for-woocommerce' );
			}
			if ( self::positive( self::value( $invoice, 'amount_overpaid' ) ) ) {
				$note .= ' ' . sprintf(
					/* translators: %s: overpaid amount with currency */
					__( 'Overpaid by %s.', 'coinspay-for-woocommerce' ),
					self::money( $invoice, 'amount_overpaid' )
				);
			}
			return self::result( self::COMPLETE, $note, true );
		}

		if ( ! empty( $order['is_paid'] ) ) {
			return self::result( self::NONE, '', 'open' !== $status );
		}

		if ( 'open' === $status ) {
			// Part is paid and the rest is on its way: nothing is left to pay.
			if ( $paid && $pending && ! self::positive( self::value( $invoice, 'amount_due' ) ) ) {
				return self::result(
					self::ON_HOLD,
					sprintf(
						/* translators: 1: invoice number, 2: paid amount, 3: payments, e.g. "12.5 USDT, TRON" */
						__( 'CoinsPay invoice No. %1$s: %2$s received, the rest is on its way and waits for network confirmations (%3$s).', 'coinspay-for-woocommerce' ),
						$number,
						self::money( $invoice, 'amount_paid' ),
						self::payments_line( $invoice )
					),
					false
				);
			}
			if ( $paid ) {
				return self::result(
					self::ON_HOLD,
					sprintf(
						/* translators: 1: invoice number, 2: paid amount, 3: amount left to pay */
						__( 'CoinsPay invoice No. %1$s is partially paid: %2$s received, %3$s left. The buyer can pay the rest on the same payment page.', 'coinspay-for-woocommerce' ),
						$number,
						self::money( $invoice, 'amount_paid' ),
						self::money( $invoice, 'amount_due' )
					),
					false
				);
			}
			if ( $pending ) {
				return self::result(
					self::ON_HOLD,
					sprintf(
						/* translators: 1: invoice number, 2: payments, e.g. "12.5 USDT, TRON" */
						__( 'CoinsPay invoice No. %1$s: the payment is on its way and waits for network confirmations (%2$s).', 'coinspay-for-woocommerce' ),
						$number,
						self::payments_line( $invoice )
					),
					false
				);
			}
			return self::result( self::NONE, '', false );
		}

		if ( 'expired' === $status || 'cancelled' === $status ) {
			if ( $paid || $pending ) {
				if ( 'expired' === $status ) {
					/* translators: 1: invoice number, 2: paid amount, 3: invoice amount */
					$format = __( 'CoinsPay invoice No. %1$s expired partially paid: %2$s of %3$s. Decide whether to ship or refund the buyer from the CoinsPay dashboard.', 'coinspay-for-woocommerce' );
				} else {
					/* translators: 1: invoice number, 2: paid amount, 3: invoice amount */
					$format = __( 'CoinsPay invoice No. %1$s was cancelled partially paid: %2$s of %3$s. Decide whether to ship or refund the buyer from the CoinsPay dashboard.', 'coinspay-for-woocommerce' );
				}
				return self::result(
					self::ON_HOLD,
					sprintf(
						$format,
						$number,
						self::money( $invoice, $paid ? 'amount_paid' : 'amount_pending' ),
						self::money( $invoice, 'amount' )
					),
					true
				);
			}
			// An older invoice of the order ran out: the buyer is paying a newer one.
			if ( empty( $order['is_current'] ) ) {
				return self::result( self::NONE, '', true );
			}
			if ( 'cancelled' === $status ) {
				return self::result(
					self::CANCEL,
					/* translators: %s: invoice number */
					sprintf( __( 'CoinsPay invoice No. %s was cancelled in the CoinsPay dashboard.', 'coinspay-for-woocommerce' ), $number ),
					true
				);
			}
			if ( ! empty( $order['cancel_on_expiry'] ) ) {
				return self::result(
					self::CANCEL,
					/* translators: %s: invoice number */
					sprintf( __( 'CoinsPay invoice No. %s expired unpaid.', 'coinspay-for-woocommerce' ), $number ),
					true
				);
			}
			return self::result(
				self::NOTE,
				/* translators: %s: invoice number */
				sprintf( __( 'CoinsPay invoice No. %s expired unpaid. The buyer can pay again from the order page: a new invoice will be created.', 'coinspay-for-woocommerce' ), $number ),
				true
			);
		}

		return self::result( self::NONE, '', false );
	}

	/**
	 * Applies an invoice to its order.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $invoice Invoice from the API.
	 * @return string What happened, for the log and the webhook answer.
	 * @throws \RuntimeException Another request holds the order too long; the caller retries later.
	 */
	public static function apply( $order, array $invoice ) {
		$order_id = (int) $order->get_id();
		if ( ! Compat::lock( $order_id ) ) {
			throw new \RuntimeException( 'the order is busy' );
		}

		try {
			// A fresh copy: another request may have changed the order while we waited for the lock.
			$fresh = Compat::fresh_order( $order_id );
			if ( $fresh ) {
				$order = $fresh;
			}
			return self::apply_locked( $order, $invoice );
		} finally {
			Compat::unlock( $order_id );
		}
	}

	/**
	 * Applies an invoice while holding the order lock.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $invoice Invoice from the API.
	 * @return string
	 */
	private static function apply_locked( $order, array $invoice ) {
		$invoice_id = self::value( $invoice, 'id' );
		$known      = self::known_invoices( $order );
		if ( ! isset( $known[ $invoice_id ] ) ) {
			return 'not an invoice of this order';
		}

		$current = (string) $order->get_meta( self::META_CURRENT, true );
		if ( $current === $invoice_id ) {
			$order->update_meta_data( self::META_SNAPSHOT, self::snapshot( $invoice ) );
			$order->update_meta_data( self::META_STATUS, self::value( $invoice, 'status' ) );
		}

		$seen        = $order->get_meta( self::META_SEEN, true );
		$seen        = is_array( $seen ) ? $seen : array();
		$fingerprint = self::fingerprint( $invoice );
		if ( isset( $seen[ $invoice_id ] ) && $seen[ $invoice_id ] === $fingerprint ) {
			$order->save();
			return 'no change';
		}

		$by_plugin = (string) $order->get_meta( self::META_CANCELLED_BY, true ) === $invoice_id;
		$decision  = self::decide(
			$invoice,
			array(
				'is_paid'             => $order->is_paid() || $order->has_status( 'refunded' ),
				'is_cancelled'        => $order->has_status( 'cancelled' ),
				'cancelled_by_plugin' => $by_plugin,
				'transaction_id'      => (string) $order->get_transaction_id(),
				// The order as it is now: the store may have changed the total after the invoice was made.
				'currency'            => strtoupper( (string) $order->get_currency() ),
				'expected_amount'     => self::order_amount( $order ),
				'is_current'          => $current === $invoice_id,
				'cancel_on_expiry'    => Settings::flag( 'cancel_on_expiry' ),
				'test_allowed'        => self::placed_by_manager( $order ),
			)
		);

		$note   = $decision['note'];
		$action = $decision['action'];

		// The buyer switched the order to another payment method: its status is not ours any
		// more, only money that came by CoinsPay is noted.
		if ( Plugin::GATEWAY_ID !== $order->get_payment_method() && self::NONE !== $action ) {
			if ( self::positive( self::value( $invoice, 'amount_paid' ) ) || self::positive( self::value( $invoice, 'amount_pending' ) ) ) {
				$action = self::NOTE;
				$note  .= ' ' . __( 'The order uses another payment method now, so its status is left as it is.', 'coinspay-for-woocommerce' );
			} else {
				$action = self::NONE;
			}
		}

		if ( ! empty( $invoice['test'] ) && '' !== $note ) {
			$note = __( '[Test]', 'coinspay-for-woocommerce' ) . ' ' . $note;
		}

		switch ( $action ) {
			case self::COMPLETE:
				$order->payment_complete( $invoice_id );
				$order->add_order_note( $note );
				break;
			case self::ON_HOLD:
				// A cancelled order comes back for the store to decide when its invoice is paid. Money
				// on its way or a part reopens it only if the plugin cancelled it: an order the store
				// cancelled must not become payable again before the store sees the payment.
				$reopen = $order->has_status( 'cancelled' ) && ( $by_plugin || 'paid' === self::value( $invoice, 'status' ) );
				if ( $reopen || $order->has_status( array( 'pending', 'failed' ) ) ) {
					$order->update_status( 'on-hold', $note );
				} else {
					$order->add_order_note( $note );
					$action = self::NOTE;
				}
				break;
			case self::CANCEL:
				if ( $order->has_status( array( 'pending', 'on-hold', 'failed' ) ) ) {
					$order->update_meta_data( self::META_CANCELLED_BY, $invoice_id );
					$order->update_status( 'cancelled', $note );
				} else {
					$action = self::NONE;
				}
				break;
			case self::NOTE:
				$order->add_order_note( $note );
				break;
		}

		// Only after the order took the action: had it failed, the next webhook or poll must
		// decide again, not see "no change" and lose the payment.
		$seen[ $invoice_id ] = $fingerprint;
		$order->update_meta_data( self::META_SEEN, $seen );
		$order->save();

		if ( $decision['final'] ) {
			$paid = 'paid' === self::value( $invoice, 'status' );
			Poller::settle( $invoice_id, $paid );
		}
		Log::info( sprintf( 'order %d, invoice %s (%s): %s', $order->get_id(), $invoice_id, self::value( $invoice, 'status' ), $action ) );
		return $action;
	}

	/**
	 * Order total in the format of invoice amounts: the decimals of the currency in CoinsPay.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private static function order_amount( $order ) {
		try {
			$scale = Currencies::scale( $order->get_currency() );
		} catch ( \Exception $e ) {
			$scale = wc_get_price_decimals();
		}
		return InvoiceRequest::format_amount( (string) $order->get_total(), $scale );
	}

	/**
	 * Whether a shop manager placed the order: only their orders may be completed by a test payment.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private static function placed_by_manager( $order ) {
		$customer = (int) $order->get_customer_id();
		return $customer > 0 && user_can( $customer, 'manage_woocommerce' );
	}

	/**
	 * Invoices of the order: id → {amount, currency, number, test, attempt}.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	public static function known_invoices( $order ) {
		$known = $order->get_meta( self::META_INVOICES, true );
		return is_array( $known ) ? $known : array();
	}

	/**
	 * Remembers a new invoice of the order and makes it the current one.
	 *
	 * @param \WC_Order $order   Order.
	 * @param array     $invoice Created invoice.
	 * @param int       $attempt Invoice number within the order.
	 */
	public static function remember( $order, array $invoice, $attempt ) {
		$known      = self::known_invoices( $order );
		$invoice_id = self::value( $invoice, 'id' );

		$known[ $invoice_id ] = array(
			'amount'   => InvoiceRequest::normalize( self::value( $invoice, 'amount' ) ),
			'currency' => strtoupper( self::value( $invoice, 'currency' ) ),
			'number'   => self::value( $invoice, 'number' ),
			'test'     => ! empty( $invoice['test'] ),
			'attempt'  => (int) $attempt,
		);
		$order->update_meta_data( self::META_INVOICES, $known );
		$order->update_meta_data( self::META_CURRENT, $invoice_id );
		$order->update_meta_data( self::META_SNAPSHOT, self::snapshot( $invoice ) );
		$order->update_meta_data( self::META_STATUS, self::value( $invoice, 'status' ) );
	}

	/**
	 * Fields of the invoice the order screen shows.
	 *
	 * @param array $invoice Invoice.
	 * @return array
	 */
	public static function snapshot( array $invoice ) {
		$fields = array( 'id', 'number', 'status', 'test', 'currency', 'amount', 'amount_paid', 'amount_pending', 'amount_due', 'amount_overpaid', 'pay_url', 'expires_at', 'paid_late', 'paid_manually' );
		$copy   = array();
		foreach ( $fields as $field ) {
			$copy[ $field ] = isset( $invoice[ $field ] ) ? $invoice[ $field ] : null;
		}
		$copy['payments'] = array();
		if ( ! empty( $invoice['payments'] ) && is_array( $invoice['payments'] ) ) {
			foreach ( $invoice['payments'] as $payment ) {
				$copy['payments'][] = array(
					'chain'   => isset( $payment['chain'] ) ? (string) $payment['chain'] : '',
					'asset'   => isset( $payment['asset'] ) ? (string) $payment['asset'] : '',
					'amount'  => isset( $payment['amount'] ) ? (string) $payment['amount'] : '',
					'status'  => isset( $payment['status'] ) ? (string) $payment['status'] : '',
					'tx_hash' => isset( $payment['tx_hash'] ) ? (string) $payment['tx_hash'] : '',
				);
			}
		}
		return $copy;
	}

	/**
	 * What of the invoice matters for the order: the same fingerprint means nothing new.
	 *
	 * @param array $invoice Invoice.
	 * @return string
	 */
	public static function fingerprint( array $invoice ) {
		$payments = array();
		if ( ! empty( $invoice['payments'] ) && is_array( $invoice['payments'] ) ) {
			foreach ( $invoice['payments'] as $payment ) {
				$payments[] = ( isset( $payment['deposit_id'] ) ? $payment['deposit_id'] : '' ) . ':' . ( isset( $payment['status'] ) ? $payment['status'] : '' );
			}
		}
		return md5(
			implode(
				'|',
				array(
					self::value( $invoice, 'status' ),
					InvoiceRequest::normalize( self::value( $invoice, 'amount_paid' ) ),
					InvoiceRequest::normalize( self::value( $invoice, 'amount_pending' ) ),
					empty( $invoice['paid_late'] ) ? '0' : '1',
					empty( $invoice['paid_manually'] ) ? '0' : '1',
					implode( ',', $payments ),
				)
			)
		);
	}

	/**
	 * Payments of the invoice in one line: "12.5 USDT, TRON; 0.001 BTC, Bitcoin".
	 *
	 * @param array $invoice Invoice.
	 * @return string
	 */
	private static function payments_line( array $invoice ) {
		$lines = array();
		if ( ! empty( $invoice['payments'] ) && is_array( $invoice['payments'] ) ) {
			foreach ( $invoice['payments'] as $payment ) {
				$line = trim( ( isset( $payment['amount'] ) ? $payment['amount'] : '' ) . ' ' . ( isset( $payment['asset'] ) ? $payment['asset'] : '' ) );
				if ( ! empty( $payment['chain'] ) ) {
					$line .= ', ' . self::chain_name( (string) $payment['chain'] );
				}
				if ( ! empty( $payment['tx_hash'] ) ) {
					$line .= ', tx ' . $payment['tx_hash'];
				}
				$lines[] = $line;
			}
		}
		return empty( $lines ) ? __( 'no payment details', 'coinspay-for-woocommerce' ) : implode( '; ', $lines );
	}

	/**
	 * Human name of a CoinsPay network id.
	 *
	 * @param string $chain Network id, e.g. tron.
	 * @return string
	 */
	public static function chain_name( $chain ) {
		$names = array(
			'ethereum' => 'Ethereum',
			'bsc'      => 'BNB Smart Chain',
			'tron'     => 'TRON',
			'bitcoin'  => 'Bitcoin',
			'litecoin' => 'Litecoin',
			'solana'   => 'Solana',
		);
		return isset( $names[ $chain ] ) ? $names[ $chain ] : $chain;
	}

	/**
	 * Amount of the invoice with its currency: "12.5 USD".
	 *
	 * @param array  $invoice Invoice.
	 * @param string $field   Amount field.
	 * @return string
	 */
	private static function money( array $invoice, $field ) {
		return InvoiceRequest::normalize( self::value( $invoice, $field ) ) . ' ' . self::value( $invoice, 'currency' );
	}

	/**
	 * A field of the invoice as a string.
	 *
	 * @param array  $invoice Invoice.
	 * @param string $field   Field.
	 * @return string
	 */
	private static function value( array $invoice, $field ) {
		return isset( $invoice[ $field ] ) && is_scalar( $invoice[ $field ] ) ? (string) $invoice[ $field ] : '';
	}

	/**
	 * Whether a decimal string is above zero.
	 *
	 * @param string $amount Decimal string.
	 * @return bool
	 */
	private static function positive( $amount ) {
		return '' !== $amount && (bool) preg_match( '/[1-9]/', $amount );
	}

	/**
	 * Decision record.
	 *
	 * @param string $action   One of the constants.
	 * @param string $note     Order note.
	 * @param bool   $is_final The invoice will not change any more.
	 * @return array
	 */
	private static function result( $action, $note, $is_final ) {
		return array(
			'action' => $action,
			'note'   => $note,
			'final'  => (bool) $is_final,
		);
	}
}
