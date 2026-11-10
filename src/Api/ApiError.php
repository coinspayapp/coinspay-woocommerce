<?php
/**
 * CoinsPay API error.
 *
 * @package CoinsPay\WooCommerce
 */

namespace CoinsPay\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

/**
 * A failed API call: transport error (status 0) or an `{ error: { code, message, details } }` answer.
 */
final class ApiError extends \RuntimeException {

	/**
	 * HTTP status; 0 when no answer came.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Stable error code of the API, e.g. VALIDATION_ERROR, or NETWORK.
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * Error details: invalid fields and the like.
	 *
	 * @var array
	 */
	private $details;

	/**
	 * Builds the error.
	 *
	 * @param int    $status     HTTP status, 0 without an answer.
	 * @param string $error_code API error code.
	 * @param string $message    Explanation in English.
	 */
	public function __construct( $status, $error_code, $message ) {
		parent::__construct( '' !== (string) $message ? (string) $message : (string) $error_code );
		$this->status     = (int) $status;
		$this->error_code = (string) $error_code;
		$this->details    = array();
	}

	/**
	 * Attaches the details of the API answer.
	 *
	 * @param array $details Error details.
	 * @return ApiError
	 */
	public function set_details( array $details ) {
		$this->details = $details;
		return $this;
	}

	/**
	 * HTTP status.
	 *
	 * @return int
	 */
	public function status() {
		return $this->status;
	}

	/**
	 * API error code.
	 *
	 * @return string
	 */
	public function error_code() {
		return $this->error_code;
	}

	/**
	 * Error details.
	 *
	 * @return array
	 */
	public function details() {
		return $this->details;
	}

	/**
	 * The invoice does not exist for this key: another shop, the other mode or a sample event.
	 *
	 * @return bool
	 */
	public function is_not_found() {
		return 404 === $this->status;
	}

	/**
	 * One line for the log, without secrets.
	 *
	 * @return string
	 */
	public function summary() {
		$line = sprintf( '%s (HTTP %d): %s', $this->error_code, $this->status, $this->getMessage() );
		if ( ! empty( $this->details ) ) {
			$line .= ' ' . wp_json_encode( $this->details );
		}
		return $line;
	}
}
