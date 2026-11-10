<?php
/**
 * API address of the plugin.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce\Tests\Unit;

use CoinsPay\WooCommerce\Settings;
use PHPUnit\Framework\TestCase;

/**
 * An empty API address means coinspay.app; a typed one is kept.
 */
final class SettingsTest extends TestCase {

	public function test_an_empty_api_address_means_coinspay_app() {
		foreach ( array( '', '   ', '/' ) as $typed ) {
			$this->assertSame( 'https://coinspay.app/api/v1', Settings::api_url_or_default( $typed ) );
		}
	}

	public function test_a_typed_api_address_is_kept_without_the_trailing_slash() {
		$this->assertSame( 'https://pay.example/api/v1', Settings::api_url_or_default( ' https://pay.example/api/v1/ ' ) );
	}
}
