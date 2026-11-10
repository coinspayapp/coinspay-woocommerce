<?php
/**
 * CoinsPay API client.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the merchant API over the WordPress HTTP API: no libraries, works on
 * any hosting WordPress itself works on.
 */
final class Client {

	/**
	 * Seconds to wait for an answer.
	 */
	const TIMEOUT = 15;

	/**
	 * Invoice ids are UUIDs; anything else never reaches the URL.
	 */
	const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * API base without the trailing slash.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Shop API key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Builds the client.
	 *
	 * @param string $base API base, e.g. https://dashboard.example/api/v1.
	 * @param string $key  Shop API key.
	 */
	public function __construct( $base, $key ) {
		$this->base = untrailingslashit( trim( (string) $base ) );
		$this->key  = trim( (string) $key );
	}

	/**
	 * The shop the key belongs to and the key itself.
	 *
	 * @return array {shop: {id, name, created_at}, key: {test, access}}; "key" is missing
	 *               from servers that predate key access.
	 */
	public function shop() {
		$data = $this->request( 'GET', '/shop' );
		$this->field( $data, 'shop' );
		return $data;
	}

	/**
	 * Price currencies with their precision.
	 *
	 * @return array {fiat: [{code, symbol, scale}], crypto: [{code, scale}]}
	 */
	public function currencies() {
		return $this->request( 'GET', '/currencies' );
	}

	/**
	 * Creates an invoice; a repeat with the same key and body returns the same invoice.
	 *
	 * @param array  $body            Invoice request.
	 * @param string $idempotency_key Idempotency-Key header.
	 * @return array Invoice.
	 */
	public function create_invoice( array $body, $idempotency_key ) {
		return $this->field( $this->request( 'POST', '/invoices', $body, array( 'Idempotency-Key' => $idempotency_key ) ), 'invoice' );
	}

	/**
	 * One invoice.
	 *
	 * @param string $id Invoice id.
	 * @return array Invoice.
	 * @throws ApiError Not an invoice id, or the API failed.
	 */
	public function invoice( $id ) {
		if ( ! preg_match( self::UUID, (string) $id ) ) {
			throw new ApiError( 404, 'NOT_FOUND', 'Not an invoice id' );
		}
		return $this->field( $this->request( 'GET', '/invoices/' . strtolower( $id ) ), 'invoice' );
	}

	/**
	 * POST /invoices/{id}/cancel: an open invoice can no longer be paid.
	 *
	 * @param string $id Invoice id.
	 * @return array The cancelled invoice.
	 * @throws ApiError The invoice is unknown or not open any more.
	 */
	public function cancel_invoice( $id ) {
		if ( ! preg_match( self::UUID, (string) $id ) ) {
			throw new ApiError( 404, 'NOT_FOUND', 'Not an invoice id' );
		}
		// An empty JSON object: the API refuses a JSON request without a body.
		return $this->field( $this->request( 'POST', '/invoices/' . strtolower( $id ) . '/cancel', new \stdClass() ), 'invoice' );
	}

	/**
	 * Sends a request and decodes the JSON answer.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path after the base.
	 * @param array|null $body    JSON body.
	 * @param array      $headers Extra headers.
	 * @return array
	 * @throws ApiError Transport error or non-2xx answer.
	 */
	private function request( $method, $path, $body = null, array $headers = array() ) {
		if ( '' === $this->base || '' === $this->key ) {
			throw new ApiError( 0, 'NOT_CONFIGURED', 'The CoinsPay API address or key is not set' );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => self::TIMEOUT,
			// A redirect would resend the key to another address.
			'redirection' => 0,
			'user-agent'  => sprintf(
				'CoinsPay-WooCommerce/%s; WordPress/%s; WooCommerce/%s',
				COINSPAY_WC_VERSION,
				get_bloginfo( 'version' ),
				defined( 'WC_VERSION' ) ? WC_VERSION : '-'
			),
			'headers'     => array_merge(
				array(
					'Authorization' => 'Bearer ' . $this->key,
					'Accept'        => 'application/json',
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base . $path, $args );
		if ( is_wp_error( $response ) ) {
			throw new ApiError( 0, 'NETWORK', esc_html( $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status >= 200 && $status < 300 && is_array( $data ) ) {
			return $data;
		}

		// Exception texts go to the log only; buyers see Payment::error_message().
		$error     = is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
		$exception = new ApiError(
			$status,
			isset( $error['code'] ) ? (string) $error['code'] : 'HTTP_' . $status,
			isset( $error['message'] ) ? (string) $error['message'] : ''
		);
		$exception->set_details( isset( $error['details'] ) && is_array( $error['details'] ) ? $error['details'] : array() );
		throw $exception;
	}

	/**
	 * The object under a key of the answer.
	 *
	 * @param array  $data Decoded answer.
	 * @param string $key  Key, e.g. invoice.
	 * @return array
	 * @throws ApiError The answer has no such object.
	 */
	private function field( array $data, $key ) {
		if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
			throw new ApiError( 0, 'BAD_RESPONSE', esc_html( sprintf( 'The answer has no "%s"', $key ) ) );
		}
		return $data[ $key ];
	}
}
