<?php
/**
 * Plugin bootstrap.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress and WooCommerce.
 *
 * Every WooCommerce feature the plugin uses is detected by its presence, not by
 * version number (see Compat): the same code runs from WooCommerce 3.0 on PHP 7.0
 * to the newest releases.
 */
final class Plugin {

	/**
	 * Gateway id: settings live in the woocommerce_coinspay_settings option.
	 */
	const GATEWAY_ID = 'coinspay';

	/**
	 * Hooks the plugin in, or explains in the admin why it cannot run.
	 */
	public static function boot() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 1 );

		if ( ! Compat::woocommerce_ready() ) {
			add_action( 'admin_notices', array( __CLASS__, 'requirements_notice' ) );
			return;
		}

		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( COINSPAY_WC_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( __CLASS__, 'register_block_method' ) );

		Webhook::register();
		Poller::register();
		Checkout::register();
		Admin\OrderPanel::register();
		Admin\ConnectionCheck::register();
	}

	/**
	 * Translations shipped with the plugin. Loaded on init: WordPress 6.7+ warns
	 * about translations requested earlier.
	 */
	public static function load_textdomain() {
		// The translations ship with the plugin and it is installed from a zip, not from WordPress.org: nothing else loads them.
		load_plugin_textdomain( 'coinspay-for-woocommerce', false, dirname( plugin_basename( COINSPAY_WC_FILE ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	}

	/**
	 * Declares support for order tables (HPOS) and the block checkout where
	 * WooCommerce knows about them (7.1+); older versions have nothing to declare.
	 */
	public static function declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', COINSPAY_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', COINSPAY_WC_FILE, true );
		}
	}

	/**
	 * Adds the gateway to WooCommerce.
	 *
	 * @param array $gateways Gateway class names.
	 * @return array
	 */
	public static function register_gateway( $gateways ) {
		$gateways[] = Gateway::class;
		return $gateways;
	}

	/**
	 * Registers the payment method of the block checkout.
	 *
	 * @param object $registry Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry.
	 */
	public static function register_block_method( $registry ) {
		if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			$registry->register( new Blocks\PaymentMethod() );
		}
	}

	/**
	 * "Settings" link next to the plugin on the Plugins screen.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::settings_url() ),
				esc_html__( 'Settings', 'coinspay-for-woocommerce' )
			)
		);
		return $links;
	}

	/**
	 * Gateway settings screen.
	 *
	 * @return string
	 */
	public static function settings_url() {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . self::GATEWAY_ID );
	}

	/**
	 * Shown when WooCommerce is missing or too old.
	 */
	public static function requirements_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: minimal WooCommerce version, e.g. 3.0 */
					__( 'CoinsPay for WooCommerce needs WooCommerce %s or newer. Install or update WooCommerce to accept crypto payments.', 'coinspay-for-woocommerce' ),
					COINSPAY_WC_MIN_WC
				)
			)
		);
	}
}
