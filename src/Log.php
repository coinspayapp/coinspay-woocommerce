<?php
/**
 * Plugin log.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to the WooCommerce log, source "coinspay": WooCommerce → Status → Logs.
 * Errors are always written, the rest only with the debug setting. Keys and
 * secrets are never passed here.
 */
final class Log {

	/**
	 * Something went wrong.
	 *
	 * @param string $message Message.
	 */
	public static function error( $message ) {
		self::write( 'error', $message );
	}

	/**
	 * What the plugin did; only with the debug setting.
	 *
	 * @param string $message Message.
	 */
	public static function info( $message ) {
		if ( Settings::flag( 'debug' ) ) {
			self::write( 'info', $message );
		}
	}

	/**
	 * Writes one line.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 */
	private static function write( $level, $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, (string) $message, array( 'source' => 'coinspay' ) );
		}
	}
}
