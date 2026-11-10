<?php
/**
 * Removes the plugin settings. Order data stays: notes and invoice ids are
 * part of the order history.
 *
 * @package CoinsPay\WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_coinspay_settings' );
delete_option( 'coinspay_wc_pending' );
delete_option( 'coinspay_wc_last_webhook_test' );
delete_transient( 'coinspay_wc_poll_scheduled' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'coinspay_wc_poll', array(), 'coinspay' );
}
wp_clear_scheduled_hook( 'coinspay_wc_poll' );
