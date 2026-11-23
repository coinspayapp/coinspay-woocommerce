<?php
/**
 * "Check connection" on the settings screen.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce\Admin;

use CoinsPay\WooCommerce\Api\ApiError;
use CoinsPay\WooCommerce\Api\Client;
use CoinsPay\WooCommerce\Plugin;
use CoinsPay\WooCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the address and key typed in the form, before they are saved: which
 * shop the key belongs to, test or live, whether the store currency is accepted
 * and whether the key has more access than invoices.
 */
final class ConnectionCheck {

	const ACTION = 'coinspay_wc_check';

	/**
	 * Hooks in.
	 */
	public static function register() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Script of the settings screen: the check and the copy button.
	 */
	public static function enqueue() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the screen.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		if ( Plugin::GATEWAY_ID !== $section ) {
			return;
		}
		wp_enqueue_script( 'coinspay-wc-admin', plugins_url( 'assets/js/admin.js', COINSPAY_WC_FILE ), array( 'jquery' ), COINSPAY_WC_VERSION, true );
		wp_localize_script(
			'coinspay-wc-admin',
			'coinspayAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'action'   => self::ACTION,
				'nonce'    => wp_create_nonce( self::ACTION ),
				'checking' => __( 'Checking…', 'coins-auto-pay' ),
				'failed'   => __( 'The check failed: the site did not answer.', 'coins-auto-pay' ),
				'copied'   => __( 'Copied', 'coins-auto-pay' ),
			)
		);
	}

	/**
	 * Runs the check and answers with one line of text.
	 */
	public static function handle() {
		check_ajax_referer( self::ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'coins-auto-pay' ) ), 403 );
		}

		$url = Settings::api_url_or_default( isset( $_POST['api_url'] ) ? esc_url_raw( wp_unslash( $_POST['api_url'] ) ) : '' );
		$key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Enter the API key first.', 'coins-auto-pay' ) ) );
		}

		$client = new Client( $url, $key );
		try {
			$answer     = $client->shop();
			$shop       = $answer['shop'];
			$currencies = $client->currencies();
		} catch ( ApiError $e ) {
			wp_send_json_error( array( 'message' => self::error_text( $e ) ) );
		}

		$currency  = get_woocommerce_currency();
		$supported = false;
		foreach ( array( 'fiat', 'crypto' ) as $kind ) {
			foreach ( isset( $currencies[ $kind ] ) && is_array( $currencies[ $kind ] ) ? $currencies[ $kind ] : array() as $item ) {
				if ( isset( $item['code'] ) && strtoupper( (string) $item['code'] ) === $currency ) {
					$supported = true;
				}
			}
		}

		$message = sprintf(
			/* translators: 1: CoinsPay shop name, 2: "test mode" or "live mode" */
			__( 'Connected to the shop "%1$s", %2$s.', 'coins-auto-pay' ),
			isset( $shop['name'] ) ? (string) $shop['name'] : '',
			Settings::key_is_test( $key ) ? __( 'test mode', 'coins-auto-pay' ) : __( 'live mode', 'coins-auto-pay' )
		);
		$message .= ' ' . ( $supported
			/* translators: %s: currency code */
			? sprintf( __( 'Prices in %s are accepted.', 'coins-auto-pay' ), $currency )
			/* translators: %s: currency code */
			: sprintf( __( 'Prices in %s are not accepted by CoinsPay: the payment method will be hidden at checkout.', 'coins-auto-pay' ), $currency ) );

		// A full-access key can withdraw the shop's money, and every store manager can read it here.
		$full_access = isset( $answer['key']['access'] ) && 'full' === $answer['key']['access'];
		if ( $full_access ) {
			$message .= ' ' . __( 'This key has full access, payouts included, while the plugin needs invoices only. Issue an "Invoices only" key in the CoinsPay dashboard, put it here and revoke this one.', 'coins-auto-pay' );
		}

		if ( $supported && ! $full_access ) {
			wp_send_json_success( array( 'message' => $message ) );
		}
		wp_send_json_error( array( 'message' => $message ) );
	}

	/**
	 * Explains a failed check.
	 *
	 * @param ApiError $e Error.
	 * @return string
	 */
	private static function error_text( ApiError $e ) {
		switch ( $e->error_code() ) {
			case 'UNAUTHORIZED':
				return __( 'CoinsPay does not know this key. Copy it from the dashboard again: the full key is shown only once, when it is issued.', 'coins-auto-pay' );
			case 'IP_NOT_ALLOWED':
				$details = $e->details();
				return sprintf(
					/* translators: %s: IP address */
					__( 'The key has an IP allowlist without this server. Add %s to the key in the dashboard.', 'coins-auto-pay' ),
					isset( $details['ip'] ) ? (string) $details['ip'] : '?'
				);
			case 'SHOP_DISABLED':
				return __( 'The shop is blocked in CoinsPay.', 'coins-auto-pay' );
			case 'NETWORK':
				/* translators: %s: error from the HTTP library */
				return sprintf( __( 'No answer from the API address: %s', 'coins-auto-pay' ), $e->getMessage() );
			default:
				/* translators: 1: HTTP status, 2: error code */
				return sprintf( __( 'CoinsPay answered with an error: HTTP %1$d, %2$s. Check the API address: it ends with /api/v1.', 'coins-auto-pay' ), $e->status(), $e->error_code() );
		}
	}
}
