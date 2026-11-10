<?php
/**
 * Gateway settings outside the gateway object.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the gateway option directly: the webhook, the poller and the block
 * checkout run where WooCommerce has not built its gateway objects.
 */
final class Settings {

	/**
	 * Option written by WC_Settings_API for the gateway.
	 */
	const OPTION = 'woocommerce_coinspay_settings';

	/**
	 * Shortest and longest time to pay: the CoinsPay API takes 15–43200 minutes,
	 * a shop rarely holds stock longer than a day.
	 */
	const MIN_LIFETIME     = 15;
	const MAX_LIFETIME     = 1440;
	const DEFAULT_LIFETIME = 60;

	/**
	 * The API address when the field is left empty.
	 */
	const DEFAULT_API_URL = 'https://coinspay.app/api/v1';

	/**
	 * Checkbox defaults; they match the form fields of the gateway.
	 *
	 * @var array
	 */
	private static $flags = array(
		'stablecoins_only'   => false,
		'buyer_pays_fee'     => false,
		'buyer_pays_network' => false,
		'auto_return'        => true,
		'cancel_on_expiry'   => true,
		'test_admin_only'    => true,
		'debug'              => false,
	);

	/**
	 * All saved settings.
	 *
	 * @return array
	 */
	public static function all() {
		$settings = get_option( self::OPTION, array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * One setting as a string.
	 *
	 * @param string $key      Setting key.
	 * @param string $fallback Value when the setting is missing.
	 * @return string
	 */
	public static function get( $key, $fallback = '' ) {
		$settings = self::all();
		return isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ? (string) $settings[ $key ] : $fallback;
	}

	/**
	 * A checkbox setting.
	 *
	 * @param string $key Setting key from self::$flags.
	 * @return bool
	 */
	public static function flag( $key ) {
		$default = isset( self::$flags[ $key ] ) ? self::$flags[ $key ] : false;
		return 'yes' === self::get( $key, $default ? 'yes' : 'no' );
	}

	/**
	 * API base without the trailing slash; DEFAULT_API_URL when the field is empty.
	 *
	 * @return string
	 */
	public static function api_url() {
		return self::api_url_or_default( self::get( 'api_url' ) );
	}

	/**
	 * An API address as typed, or the default one when nothing is typed.
	 *
	 * @param string $url Address from the settings or the settings form.
	 * @return string
	 */
	public static function api_url_or_default( $url ) {
		$url = untrailingslashit( trim( (string) $url ) );
		return '' === $url ? self::DEFAULT_API_URL : $url;
	}

	/**
	 * Shop API key: cpk_… or cpk_test_….
	 *
	 * @return string
	 */
	public static function api_key() {
		return trim( self::get( 'api_key' ) );
	}

	/**
	 * Webhook secret: whsec_….
	 *
	 * @return string
	 */
	public static function webhook_secret() {
		return trim( self::get( 'webhook_secret' ) );
	}

	/**
	 * Test mode follows the key: a cpk_test_ key creates test invoices.
	 *
	 * @return bool
	 */
	public static function is_test_mode() {
		return self::key_is_test( self::api_key() );
	}

	/**
	 * Whether a key is a test key.
	 *
	 * @param string $key API key.
	 * @return bool
	 */
	public static function key_is_test( $key ) {
		return 0 === strpos( trim( (string) $key ), 'cpk_test_' );
	}

	/**
	 * Time to pay in minutes, within the allowed range.
	 *
	 * @return int
	 */
	public static function lifetime() {
		return self::clamp_lifetime( self::get( 'lifetime', (string) self::DEFAULT_LIFETIME ) );
	}

	/**
	 * Brings any input into the allowed range of minutes.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function clamp_lifetime( $value ) {
		$minutes = is_numeric( $value ) ? (int) $value : self::DEFAULT_LIFETIME;
		return max( self::MIN_LIFETIME, min( self::MAX_LIFETIME, $minutes ) );
	}

	/**
	 * The plugin can talk to CoinsPay.
	 *
	 * @return bool
	 */
	public static function configured() {
		return '' !== self::api_url() && '' !== self::api_key();
	}

	/**
	 * API client with the saved address and key.
	 *
	 * @return Api\Client
	 */
	public static function client() {
		return new Api\Client( self::api_url(), self::api_key() );
	}
}
