<?php
/**
 * CoinsPay in the block checkout.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use CoinsPay\WooCommerce\Plugin;
use CoinsPay\WooCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The payment method of the Cart and Checkout blocks. Loaded only when
 * WooCommerce has the blocks (Plugin::register_block_method). Paying goes
 * through Gateway::process_payment, as in the classic checkout.
 */
final class PaymentMethod extends AbstractPaymentMethodType {

	/**
	 * Same id as the gateway.
	 *
	 * @var string
	 */
	protected $name = Plugin::GATEWAY_ID;

	/**
	 * Loads the settings.
	 */
	public function initialize() {
		$this->settings = Settings::all();
	}

	/**
	 * Whether the method is switched on; the rest of the availability is
	 * Gateway::is_available, which WooCommerce asks as well.
	 *
	 * @return bool
	 */
	public function is_active() {
		return isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	/**
	 * Script registering the method in the checkout. Plain JavaScript, no build step.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'coinspay-wc-blocks',
			plugins_url( 'assets/js/checkout-block.js', COINSPAY_WC_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			COINSPAY_WC_VERSION,
			true
		);
		return array( 'coinspay-wc-blocks' );
	}

	/**
	 * Data for the script: wc.wcSettings.getSetting( 'coinspay_data' ).
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => Settings::get( 'title', __( 'Cryptocurrency', 'coinspay-for-woocommerce' ) ),
			'description' => Settings::get( 'description' ),
			'supports'    => array( 'products' ),
		);
	}
}
