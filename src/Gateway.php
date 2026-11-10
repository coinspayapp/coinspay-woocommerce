<?php
/**
 * CoinsPay payment gateway.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce;

use CoinsPay\WooCommerce\Api\ApiError;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a CoinsPay invoice for the order and sends the buyer to its payment
 * page; the order is updated by the webhook and the poller (OrderSync).
 */
class Gateway extends \WC_Payment_Gateway {

	/**
	 * Sets the gateway up.
	 */
	public function __construct() {
		$this->id                 = Plugin::GATEWAY_ID;
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->method_title       = __( 'CoinsPay', 'coinspay-for-woocommerce' );
		$this->method_description = __( 'Crypto payments with CoinsPay. The buyer picks a coin and a network on the CoinsPay payment page; the order is marked paid on its own.', 'coinspay-for-woocommerce' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Settings form.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'            => array(
				'title'   => __( 'Enable', 'coinspay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Accept crypto payments with CoinsPay', 'coinspay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'              => array(
				'title'       => __( 'Title', 'coinspay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'The payment method name the buyer sees at checkout.', 'coinspay-for-woocommerce' ),
				'default'     => __( 'Cryptocurrency', 'coinspay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'        => array(
				'title'       => __( 'Description', 'coinspay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the payment method at checkout.', 'coinspay-for-woocommerce' ),
				'default'     => __( 'Pay with USDT, BTC, ETH and other coins. You will choose the coin and the network on the next page.', 'coinspay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'connection'         => array(
				'title'       => __( 'Connection', 'coinspay-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'In the CoinsPay dashboard open your shop: issue an API key under "API keys", then under "Webhook" enter the address below and copy the secret.', 'coinspay-for-woocommerce' ),
			),
			'api_url'            => array(
				'title'       => __( 'API address', 'coinspay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Leave empty to use https://coinspay.app/api/v1. Change it only if CoinsPay gives you another address.', 'coinspay-for-woocommerce' ),
				'default'     => '',
				'placeholder' => 'https://coinspay.app/api/v1',
			),
			'api_key'            => array(
				'title'       => __( 'API key', 'coinspay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'cpk_… for real payments, cpk_test_… for test mode: test invoices are paid with a button on the payment page, without coins.', 'coinspay-for-woocommerce' ),
				'default'     => '',
			),
			'webhook_url'        => array(
				'title' => __( 'Webhook address', 'coinspay-for-woocommerce' ),
				'type'  => 'coinspay_webhook',
			),
			'webhook_secret'     => array(
				'title'       => __( 'Webhook secret', 'coinspay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'The whsec_… secret the dashboard shows when you save the webhook.', 'coinspay-for-woocommerce' ),
				'default'     => '',
			),
			'connection_check'   => array(
				'title' => __( 'Check', 'coinspay-for-woocommerce' ),
				'type'  => 'coinspay_check',
			),
			'invoice'            => array(
				'title' => __( 'Invoice', 'coinspay-for-woocommerce' ),
				'type'  => 'title',
			),
			'lifetime'           => array(
				'title'             => __( 'Time to pay, minutes', 'coinspay-for-woocommerce' ),
				'type'              => 'number',
				'default'           => (string) Settings::DEFAULT_LIFETIME,
				'description'       => sprintf(
					/* translators: 1: minimum minutes, 2: maximum minutes */
					__( 'From %1$d to %2$d minutes. The order stays pending meanwhile and is not cancelled by the "Hold stock" setting of WooCommerce.', 'coinspay-for-woocommerce' ),
					Settings::MIN_LIFETIME,
					Settings::MAX_LIFETIME
				),
				'custom_attributes' => array(
					'min'  => Settings::MIN_LIFETIME,
					'max'  => Settings::MAX_LIFETIME,
					'step' => 1,
				),
			),
			'cancel_on_expiry'   => array(
				'title'   => __( 'Expired invoice', 'coinspay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Cancel the order when its invoice expires unpaid', 'coinspay-for-woocommerce' ),
				'default' => 'yes',
			),
			'auto_return'        => array(
				'title'       => __( 'Return to the shop', 'coinspay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Take the buyer back to the shop automatically after payment', 'coinspay-for-woocommerce' ),
				'description' => __( 'Five seconds after the payment page shows "Paid". Otherwise the buyer comes back with the "Back to the shop" button.', 'coinspay-for-woocommerce' ),
				'default'     => 'yes',
			),
			'stablecoins_only'   => array(
				'title'   => __( 'Coins', 'coinspay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Accept stablecoins only (USDT and USDC)', 'coinspay-for-woocommerce' ),
				'default' => 'no',
			),
			'buyer_pays_fee'     => array(
				'title'       => __( 'Fees', 'coinspay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'The buyer pays the CoinsPay fee', 'coinspay-for-woocommerce' ),
				'description' => __( 'Added to the amount in coins on the payment page; the order total stays the same.', 'coinspay-for-woocommerce' ),
				'default'     => 'no',
			),
			'buyer_pays_network' => array(
				'type'    => 'checkbox',
				'label'   => __( 'The buyer pays the network fee of your payout', 'coinspay-for-woocommerce' ),
				'default' => 'no',
			),
			'advanced'           => array(
				'title' => __( 'Advanced', 'coinspay-for-woocommerce' ),
				'type'  => 'title',
			),
			'test_admin_only'    => array(
				'title'       => __( 'Test mode', 'coinspay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'With a test key, show the payment method to shop managers only', 'coinspay-for-woocommerce' ),
				'description' => __( 'A test payment completes only orders placed by a shop manager; other orders paid this way stay on hold.', 'coinspay-for-woocommerce' ),
				'default'     => 'yes',
			),
			'debug'              => array(
				'title'       => __( 'Debug log', 'coinspay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log what the plugin does', 'coinspay-for-woocommerce' ),
				'description' => __( 'WooCommerce → Status → Logs, source "coinspay". Errors are logged always; keys and secrets never.', 'coinspay-for-woocommerce' ),
				'default'     => 'no',
			),
		);
	}

	/**
	 * The method is offered when it is set up, the store currency is accepted by
	 * CoinsPay and, in test mode, the visitor manages the shop.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() || ! Settings::configured() ) {
			return false;
		}
		if ( Settings::is_test_mode() && Settings::flag( 'test_admin_only' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}
		// Unknown (CoinsPay not answering right now) is not a reason to hide the method.
		return false !== Currencies::supported( get_woocommerce_currency() );
	}

	/**
	 * Creates or reuses the invoice and sends the buyer to the payment page.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		try {
			$invoice = Payment::invoice_for( $order );
		} catch ( ApiError $e ) {
			Log::error( sprintf( 'invoice for order %d not created: %s', $order->get_id(), $e->summary() ) );
			wc_add_notice( Payment::error_message( $e ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( 'paid' === $invoice['status'] ) {
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}
		return array(
			'result'   => 'success',
			'redirect' => add_query_arg( 'lang', Compat::pay_page_language(), $invoice['pay_url'] ),
		);
	}

	/**
	 * Cleans the API address: no spaces, no trailing slash.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 */
	public function validate_api_url_field( $key, $value ) {
		$value = untrailingslashit( trim( (string) $value ) );
		if ( '' !== $value && ! preg_match( '#^https?://#i', $value ) ) {
			\WC_Admin_Settings::add_error( __( 'The API address must start with https:// (or http:// for a local test).', 'coinspay-for-woocommerce' ) );
			return $this->get_option( 'api_url' );
		}
		Currencies::flush();
		return esc_url_raw( $value );
	}

	/**
	 * Checks the API key format.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 */
	public function validate_api_key_field( $key, $value ) {
		$value = trim( (string) $value );
		if ( '' !== $value && ! preg_match( '/^cpk_(test_)?[A-Za-z0-9]{40}$/', $value ) ) {
			\WC_Admin_Settings::add_error( __( 'This does not look like a CoinsPay API key: it starts with cpk_ or cpk_test_.', 'coinspay-for-woocommerce' ) );
			return $this->get_option( 'api_key' );
		}
		Currencies::flush();
		return $value;
	}

	/**
	 * Checks the webhook secret format.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 */
	public function validate_webhook_secret_field( $key, $value ) {
		$value = trim( (string) $value );
		if ( '' !== $value && 0 !== strpos( $value, 'whsec_' ) ) {
			\WC_Admin_Settings::add_error( __( 'The webhook secret starts with whsec_.', 'coinspay-for-woocommerce' ) );
			return $this->get_option( 'webhook_secret' );
		}
		return $value;
	}

	/**
	 * Keeps the time to pay within the range.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 */
	public function validate_lifetime_field( $key, $value ) {
		return (string) Settings::clamp_lifetime( $value );
	}

	/**
	 * The webhook address, read-only, with a copy button and the last test event.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field definition.
	 * @return string
	 */
	public function generate_coinspay_webhook_html( $key, $data ) {
		$last = (int) get_option( Webhook::LAST_TEST_OPTION, 0 );
		$hint = $last
			? sprintf(
				/* translators: %s: date and time */
				__( 'Last test event from the dashboard: %s.', 'coinspay-for-woocommerce' ),
				date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) )
			)
			: __( 'Enter this address in the dashboard, then press "Send test event" there: the time it arrived will show here.', 'coinspay-for-woocommerce' );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<input type="text" class="regular-input" id="coinspay-webhook-url" readonly value="<?php echo esc_attr( Webhook::url() ); ?>" />
				<button type="button" class="button" data-coinspay-copy="coinspay-webhook-url"><?php esc_html_e( 'Copy', 'coinspay-for-woocommerce' ); ?></button>
				<p class="description"><?php echo esc_html( $hint ); ?></p>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * "Check connection" button.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field definition.
	 * @return string
	 */
	public function generate_coinspay_check_html( $key, $data ) {
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<button type="button" class="button" id="coinspay-check"><?php esc_html_e( 'Check connection', 'coinspay-for-woocommerce' ); ?></button>
				<p class="description" id="coinspay-check-result" aria-live="polite"><?php esc_html_e( 'Uses the address and key entered above, saved or not.', 'coinspay-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * The custom rows post nothing; nothing to save for them.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 */
	public function validate_coinspay_webhook_field( $key, $value ) {
		return '';
	}

	/**
	 * See validate_coinspay_webhook_field().
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 */
	public function validate_coinspay_check_field( $key, $value ) {
		return '';
	}

	/**
	 * Settings screen, with a note in test mode.
	 */
	public function admin_options() {
		if ( Settings::is_test_mode() ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'Test mode: the key is a test one (cpk_test_). Invoices are paid with a button on the payment page, no coins move. Orders paid this way are marked "[Test]" in their notes.', 'coinspay-for-woocommerce' )
			);
		}
		parent::admin_options();
	}
}
