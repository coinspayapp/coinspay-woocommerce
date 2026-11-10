<?php
/**
 * Plugin Name:          CoinsPay for WooCommerce
 * Description:          Accept cryptocurrency in WooCommerce with CoinsPay: the buyer picks a coin and a network on the CoinsPay payment page, and the order is marked paid on its own.
 * Version:              0.1.0
 * Requires at least:    4.7
 * Requires PHP:         7.0
 * Requires Plugins:     woocommerce
 * Author:               CoinsPay
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          coinspay-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 3.0
 * WC tested up to:      11.2
 *
 * @package CoinsPay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'COINSPAY_WC_VERSION', '0.1.0' );
define( 'COINSPAY_WC_FILE', __FILE__ );
define( 'COINSPAY_WC_DIR', plugin_dir_path( __FILE__ ) );
define( 'COINSPAY_WC_MIN_WC', '3.0' );

spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'CoinsPay\\WooCommerce\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = COINSPAY_WC_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

add_action( 'before_woocommerce_init', array( 'CoinsPay\\WooCommerce\\Plugin', 'declare_compatibility' ) );
add_action( 'plugins_loaded', array( 'CoinsPay\\WooCommerce\\Plugin', 'boot' ), 11 );
register_deactivation_hook( __FILE__, array( 'CoinsPay\\WooCommerce\\Poller', 'unschedule' ) );
