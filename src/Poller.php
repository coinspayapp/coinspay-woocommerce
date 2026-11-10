<?php
/**
 * Polling of open invoices.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce;

use CoinsPay\WooCommerce\Api\ApiError;

defined( 'ABSPATH' ) || exit;

/**
 * The second way to learn about payments. CoinsPay drops events when the shop
 * has no webhook, and a webhook can fail, so every 5 minutes the open invoices
 * are fetched and applied to their orders.
 *
 * The list of invoices to poll is one option (invoice id → order id): no
 * order meta queries, which differ between WooCommerce 3.x and order tables.
 * Runs on Action Scheduler (WooCommerce 3.5+) every 5 minutes, on WP-Cron
 * every 15 minutes before it.
 */
final class Poller {

	const HOOK     = 'coinspay_wc_poll';
	const GROUP    = 'coinspay';
	const OPTION   = 'coinspay_wc_pending';
	const INTERVAL = 300;
	const BATCH    = 25;

	/**
	 * How long an expired or cancelled invoice is still polled: a late payment
	 * can still arrive.
	 */
	const AFTER_CLOSE = DAY_IN_SECONDS;

	/**
	 * An invoice is dropped after this long whatever its state.
	 */
	const MAX_AGE = 35 * DAY_IN_SECONDS;

	/**
	 * Hooks the poller in.
	 */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
	}

	/**
	 * WP-Cron interval of the fallback.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		// WP-Cron runs on page visits anyway: every 15 minutes is enough for the fallback.
		$schedules['coinspay_fifteen_minutes'] = array(
			'interval' => 900,
			'display'  => __( 'Every 15 minutes (CoinsPay)', 'coinspay-for-woocommerce' ),
		);
		return $schedules;
	}

	/**
	 * Makes sure the recurring run exists; checked once an hour, not on every request.
	 */
	public static function schedule() {
		if ( get_transient( 'coinspay_wc_poll_scheduled' ) ) {
			return;
		}
		if ( Compat::has_action_scheduler() ) {
			if ( false === as_next_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, self::HOOK, array(), self::GROUP );
			}
			wp_clear_scheduled_hook( self::HOOK );
		} elseif ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + self::INTERVAL, 'coinspay_fifteen_minutes', self::HOOK );
		}
		set_transient( 'coinspay_wc_poll_scheduled', 1, HOUR_IN_SECONDS );
	}

	/**
	 * Removes the recurring run: on plugin deactivation.
	 */
	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK );
		delete_transient( 'coinspay_wc_poll_scheduled' );
	}

	/**
	 * Fetches the invoices waiting longest and applies them.
	 */
	public static function run() {
		$pending = self::pending();
		if ( empty( $pending ) || ! Settings::configured() ) {
			return;
		}

		$now = time();
		foreach ( $pending as $invoice_id => $entry ) {
			if ( ( isset( $entry['until'] ) && $entry['until'] < $now ) || $entry['since'] < $now - self::MAX_AGE ) {
				self::forget( $invoice_id );
				unset( $pending[ $invoice_id ] );
			}
		}

		uasort(
			$pending,
			function ( $a, $b ) {
				return $a['checked'] - $b['checked'];
			}
		);

		$client   = Settings::client();
		$failures = 0;
		foreach ( array_slice( $pending, 0, self::BATCH, true ) as $invoice_id => $entry ) {
			self::touch( $invoice_id );
			$order = wc_get_order( $entry['order'] );
			if ( ! $order ) {
				self::forget( $invoice_id );
				continue;
			}
			try {
				OrderSync::apply( $order, $client->invoice( $invoice_id ) );
			} catch ( ApiError $e ) {
				if ( $e->is_not_found() ) {
					self::forget( $invoice_id );
					continue;
				}
				Log::error( sprintf( 'poll of invoice %s failed: %s', $invoice_id, $e->summary() ) );
				// CoinsPay is not answering: the next run tries again.
				if ( ++$failures >= 3 ) {
					break;
				}
			} catch ( \Exception $e ) {
				Log::error( sprintf( 'poll of invoice %s failed: %s', $invoice_id, $e->getMessage() ) );
			}
		}
	}

	/**
	 * Starts polling an invoice.
	 *
	 * @param string $invoice_id Invoice id.
	 * @param int    $order_id   Order id.
	 */
	public static function track( $invoice_id, $order_id ) {
		$pending                = self::pending();
		$pending[ $invoice_id ] = array(
			'order'   => (int) $order_id,
			'since'   => time(),
			'checked' => time(),
		);
		self::save( $pending );
	}

	/**
	 * The invoice reached a final state: a paid one is dropped, an expired or
	 * cancelled one is polled one more day for late payments.
	 *
	 * @param string $invoice_id Invoice id.
	 * @param bool   $paid       The invoice is paid.
	 */
	public static function settle( $invoice_id, $paid ) {
		$pending = self::pending();
		if ( ! isset( $pending[ $invoice_id ] ) ) {
			return;
		}
		if ( $paid ) {
			unset( $pending[ $invoice_id ] );
		} elseif ( ! isset( $pending[ $invoice_id ]['until'] ) ) {
			$pending[ $invoice_id ]['until'] = time() + self::AFTER_CLOSE;
		}
		self::save( $pending );
	}

	/**
	 * Order of a polled invoice.
	 *
	 * @param string $invoice_id Invoice id.
	 * @return int 0 when the invoice is not polled.
	 */
	public static function order_of( $invoice_id ) {
		$pending = self::pending();
		return isset( $pending[ $invoice_id ] ) ? (int) $pending[ $invoice_id ]['order'] : 0;
	}

	/**
	 * Stops polling an invoice.
	 *
	 * @param string $invoice_id Invoice id.
	 */
	private static function forget( $invoice_id ) {
		$pending = self::pending();
		unset( $pending[ $invoice_id ] );
		self::save( $pending );
	}

	/**
	 * Marks an invoice as just checked.
	 *
	 * @param string $invoice_id Invoice id.
	 */
	private static function touch( $invoice_id ) {
		$pending = self::pending();
		if ( isset( $pending[ $invoice_id ] ) ) {
			$pending[ $invoice_id ]['checked'] = time();
			self::save( $pending );
		}
	}

	/**
	 * Invoices being polled.
	 *
	 * @return array invoice id → {order, since, checked, until?}
	 */
	private static function pending() {
		$pending = get_option( self::OPTION, array() );
		if ( ! is_array( $pending ) ) {
			return array();
		}
		foreach ( $pending as $invoice_id => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['order'] ) ) {
				unset( $pending[ $invoice_id ] );
				continue;
			}
			$pending[ $invoice_id ] += array(
				'since'   => 0,
				'checked' => 0,
			);
		}
		return $pending;
	}

	/**
	 * Writes the list; not autoloaded, it is needed only here.
	 *
	 * @param array $pending Invoices being polled.
	 */
	private static function save( array $pending ) {
		update_option( self::OPTION, $pending, false );
	}
}
