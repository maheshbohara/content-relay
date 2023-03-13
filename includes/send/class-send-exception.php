<?php
/**
 * Sending failure.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Send;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a relay cannot proceed. Carries a machine code and an HTTP status for the admin API.
 */
class Send_Exception extends \RuntimeException {

	/**
	 * Machine-readable code.
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * HTTP status for the admin API.
	 *
	 * @var int
	 */
	private int $status;

	/**
	 * Constructor.
	 *
	 * @param string          $message    Message for the administrator.
	 * @param string          $error_code Machine-readable code.
	 * @param int             $status     HTTP status.
	 * @param \Throwable|null $previous   Previous exception.
	 */
	public function __construct( string $message, string $error_code = 'relay_failed', int $status = 409, ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->error_code = $error_code;
		$this->status     = $status;
	}

	/**
	 * Machine-readable code.
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * HTTP status.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * The message as plain text (messages are HTML-escaped when thrown).
	 */
	public function plain_message(): string {
		return html_entity_decode( $this->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
