<?php
/**
 * Unit tests run the pure parts of the plugin without WordPress: the few
 * WordPress functions they touch are stubbed here.
 *
 * @package CoinsPay\WooCommerce
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'COINSPAY_WC_VERSION', 'test' );
define( 'COINSPAY_WC_DIR', dirname( __DIR__ ) . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

function __( $text, $domain = 'default' ) {
	return $text;
}

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'CoinsPay\\WooCommerce\\';
		if ( 0 === strpos( $class_name, $prefix ) ) {
			$file = COINSPAY_WC_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	}
);
