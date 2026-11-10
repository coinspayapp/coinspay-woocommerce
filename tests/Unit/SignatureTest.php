<?php
/**
 * Webhook signature.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce\Tests\Unit;

use CoinsPay\WooCommerce\Api\Signature;
use PHPUnit\Framework\TestCase;

/**
 * The vector is made by the CoinsPay server code (packages/core-contract,
 * signPayload): the plugin must accept exactly what CoinsPay sends.
 */
final class SignatureTest extends TestCase {

	const BODY   = '{"id":"0b0e1c1e-6f0c-4c56-9a8e-2d6c1e1f0a01","type":"invoice.paid","created_at":"2026-10-28T08:00:00.000Z","shop_id":"5a1f6b9e-1c2d-4e3f-8a9b-0c1d2e3f4a5b","test":true,"data":{"invoice":{"id":"7c9e6679-7425-40de-944b-e07fc1f90ae7","status":"paid"}}}';
	const SECRET = 'whsec_plugin_test_secret';
	const TIME   = 1761638400;
	const HEADER = 't=1761638400,v1=34c05e780e334839328753e5d1b2fa20f7705b67b4489d2f29abda8695af92d9';

	public function test_accepts_the_signature_of_coinspay() {
		$this->assertTrue( Signature::verify( self::BODY, self::HEADER, self::SECRET, self::TIME ) );
		// Clocks may differ up to five minutes either way.
		$this->assertTrue( Signature::verify( self::BODY, self::HEADER, self::SECRET, self::TIME + 300 ) );
		$this->assertTrue( Signature::verify( self::BODY, self::HEADER, self::SECRET, self::TIME - 300 ) );
		$this->assertTrue( Signature::verify( self::BODY, ' t=1761638400 , v1=34c05e780e334839328753e5d1b2fa20f7705b67b4489d2f29abda8695af92d9 ', self::SECRET, self::TIME ) );
	}

	public function test_rejects_a_stale_timestamp() {
		$this->assertFalse( Signature::verify( self::BODY, self::HEADER, self::SECRET, self::TIME + 301 ) );
		$this->assertFalse( Signature::verify( self::BODY, self::HEADER, self::SECRET, self::TIME - 301 ) );
	}

	public function test_rejects_another_secret_or_body() {
		$this->assertFalse( Signature::verify( self::BODY, self::HEADER, 'whsec_other', self::TIME ) );
		$this->assertFalse( Signature::verify( str_replace( '"paid"', '"open"', self::BODY ), self::HEADER, self::SECRET, self::TIME ) );
		// Re-encoded JSON is other bytes: only the raw body verifies.
		$this->assertFalse( Signature::verify( json_encode( json_decode( self::BODY, true ), JSON_PRETTY_PRINT ), self::HEADER, self::SECRET, self::TIME ) );
	}

	public function test_rejects_malformed_headers() {
		foreach ( array( '', 'v1=34c05e78', 't=abc,v1=34c05e780e334839328753e5d1b2fa20f7705b67b4489d2f29abda8695af92d9', 't=1761638400', 't=1761638400,v1=XYZ', '=1,t=1761638400,v1=34c05e780e334839328753e5d1b2fa20f7705b67b4489d2f29abda8695af92d9', 'garbage' ) as $header ) {
			$this->assertFalse( Signature::verify( self::BODY, $header, self::SECRET, self::TIME ), $header );
		}
		$this->assertFalse( Signature::verify( self::BODY, self::HEADER, '', self::TIME ) );
	}
}
