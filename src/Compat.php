<?php
/**
 * What the running WordPress and WooCommerce can do.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Feature checks and fallbacks for WooCommerce 3.0+ and WordPress 4.7+.
 */
final class Compat {

	/**
	 * WooCommerce is active and new enough (3.0: order CRUD, getters, meta API).
	 *
	 * @return bool
	 */
	public static function woocommerce_ready() {
		return defined( 'WC_VERSION' ) && class_exists( 'WC_Payment_Gateway' ) && version_compare( WC_VERSION, COINSPAY_WC_MIN_WC, '>=' );
	}

	/**
	 * Action Scheduler ships with WooCommerce 3.5+; older versions get WP-Cron.
	 *
	 * @return bool
	 */
	public static function has_action_scheduler() {
		return function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' );
	}

	/**
	 * Language of the payment page: Russian for a Russian site, English otherwise.
	 *
	 * @return string ru or en.
	 */
	public static function pay_page_language() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return 0 === strpos( strtolower( (string) $locale ), 'ru' ) ? 'ru' : 'en';
	}

	/**
	 * Order edit screens: the posts screen and, with order tables (WooCommerce 7.1+),
	 * the orders page.
	 *
	 * @return string[]
	 */
	public static function order_screens() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		return array_values( array_unique( array_filter( $screens ) ) );
	}

	/**
	 * The order as it is in the database now.
	 *
	 * The copy from wc_get_order() may be cached earlier in this request: the
	 * order cache of order tables, the post cache before them. After waiting
	 * for the lock that copy can be stale; on WooCommerce 8.5 even with the
	 * order cache cleared. A new object of the same class reads the database,
	 * and its meta is read past the meta cache of WC_Data, which every version
	 * keeps per request.
	 *
	 * @param int $order_id Order id.
	 * @return \WC_Order|false
	 */
	public static function fresh_order( $order_id ) {
		$order_id = (int) $order_id;
		if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
			try {
				wc_get_container()->get( '\Automattic\WooCommerce\Caches\OrderCache' )->remove( $order_id );
			} catch ( \Exception $e ) {
				// No order cache in this version: nothing to clear.
				unset( $e );
			}
		}
		clean_post_cache( $order_id );

		$cached = wc_get_order( $order_id );
		if ( ! $cached ) {
			return false;
		}
		$class = get_class( $cached );
		try {
			$fresh = new $class( $order_id );
		} catch ( \Exception $e ) {
			$fresh = $cached;
		}
		$fresh->read_meta_data( true );
		return $fresh;
	}

	/**
	 * Takes the per-order lock: a webhook and the poller may handle one order at
	 * the same moment, and payment_complete() must not run twice.
	 *
	 * @param int $order_id Order id.
	 * @return bool False when the lock was not acquired within 10 seconds.
	 */
	public static function lock( $order_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a MySQL named lock, not data.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name( $order_id ), 10 ) );
	}

	/**
	 * Releases the per-order lock.
	 *
	 * @param int $order_id Order id.
	 */
	public static function unlock( $order_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a MySQL named lock, not data.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $order_id ) ) );
	}

	/**
	 * Lock name, unique per site: several sites may share one database server.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private static function lock_name( $order_id ) {
		global $wpdb;
		return 'coinspay_' . substr( md5( $wpdb->prefix . home_url() ), 0, 12 ) . '_' . (int) $order_id;
	}
}
