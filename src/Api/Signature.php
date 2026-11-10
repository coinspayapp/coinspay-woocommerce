<?php
/**
 * Webhook signature.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Checks `x-cpay-signature: t=<unix>,v1=<hex>`: hex is HMAC-SHA256 of
 * "<t>.<raw body>" with the whsec_ secret of the shop. The timestamp is signed
 * too, so an intercepted event cannot be sent again later than the tolerance.
 */
final class Signature {

	/**
	 * Allowed clock difference either way, seconds.
	 */
	const TOLERANCE = 300;

	/**
	 * Whether the header signs this body with this secret.
	 *
	 * @param string   $body   Raw request body, exactly as received.
	 * @param string   $header Value of x-cpay-signature.
	 * @param string   $secret Webhook secret.
	 * @param int|null $now    Current unix time; for tests.
	 * @return bool
	 */
	public static function verify( $body, $header, $secret, $now = null ) {
		$header = trim( (string) $header );
		$secret = (string) $secret;
		if ( '' === $header || '' === $secret ) {
			return false;
		}

		$parts = array();
		foreach ( explode( ',', $header ) as $piece ) {
			$eq = strpos( $piece, '=' );
			if ( false === $eq || 0 === $eq ) {
				return false;
			}
			$parts[ trim( substr( $piece, 0, $eq ) ) ] = trim( substr( $piece, $eq + 1 ) );
		}

		if ( ! isset( $parts['t'], $parts['v1'] ) || ! preg_match( '/^\d{1,12}$/', $parts['t'] ) || ! preg_match( '/^[0-9a-f]{64}$/', $parts['v1'] ) ) {
			return false;
		}

		$now = null === $now ? time() : (int) $now;
		if ( abs( $now - (int) $parts['t'] ) > self::TOLERANCE ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $parts['t'] . '.' . $body, $secret );
		return hash_equals( $expected, $parts['v1'] );
	}
}
