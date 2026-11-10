<?php
/**
 * Price currencies CoinsPay accepts.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce;

use CoinsPay\WooCommerce\Api\ApiError;

defined( 'ABSPATH' ) || exit;

/**
 * The list of GET /currencies, cached for 12 hours: the CoinsPay admin can add
 * or switch off currencies, so it is not hardcoded.
 */
final class Currencies {

	/**
	 * How long a loaded list is kept.
	 */
	const TTL = 43200;

	/**
	 * After a failed load, how long to wait before asking again.
	 */
	const RETRY = 300;

	/**
	 * Decimals CoinsPay takes for the currency.
	 *
	 * @param string $code Currency code.
	 * @return int
	 * @throws ApiError The currency is not accepted or the list cannot be loaded.
	 */
	public static function scale( $code ) {
		$list = self::load( true );
		$code = strtoupper( (string) $code );
		if ( ! isset( $list[ $code ] ) ) {
			throw new ApiError( 400, 'CURRENCY_NOT_SUPPORTED', esc_html( sprintf( 'CoinsPay does not accept prices in %s', $code ) ) );
		}
		return $list[ $code ];
	}

	/**
	 * Whether CoinsPay accepts the currency.
	 *
	 * @param string $code Currency code.
	 * @return bool|null Null when the list is unknown: the API is not reachable now.
	 */
	public static function supported( $code ) {
		try {
			$list = self::load( false );
		} catch ( ApiError $e ) {
			return null;
		}
		return isset( $list[ strtoupper( (string) $code ) ] );
	}

	/**
	 * Currency code → decimals.
	 *
	 * @param bool $force Ask the API even right after a failure.
	 * @return array
	 * @throws ApiError The list cannot be loaded.
	 */
	private static function load( $force ) {
		$key    = self::cache_key();
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( ! $force && get_transient( $key . '_failed' ) ) {
			throw new ApiError( 0, 'NETWORK', 'The currency list is not available' );
		}

		try {
			$data = Settings::client()->currencies();
		} catch ( ApiError $e ) {
			set_transient( $key . '_failed', 1, self::RETRY );
			Log::error( 'currency list not loaded: ' . $e->summary() );
			throw $e;
		}

		$list = array();
		foreach ( array( 'fiat', 'crypto' ) as $kind ) {
			if ( empty( $data[ $kind ] ) || ! is_array( $data[ $kind ] ) ) {
				continue;
			}
			foreach ( $data[ $kind ] as $currency ) {
				if ( isset( $currency['code'], $currency['scale'] ) ) {
					$list[ strtoupper( (string) $currency['code'] ) ] = (int) $currency['scale'];
				}
			}
		}
		set_transient( $key, $list, self::TTL );
		delete_transient( $key . '_failed' );
		return $list;
	}

	/**
	 * Forgets the cached list: after the API address or key changes.
	 */
	public static function flush() {
		delete_transient( self::cache_key() );
		delete_transient( self::cache_key() . '_failed' );
	}

	/**
	 * Cache per API address and mode: a test and a live setup may differ.
	 *
	 * @return string
	 */
	private static function cache_key() {
		return 'coinspay_wc_cur_' . substr( md5( Settings::api_url() . '|' . ( Settings::is_test_mode() ? 'test' : 'live' ) ), 0, 12 );
	}
}
