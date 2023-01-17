<?php
/**
 * Connection or key failure.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a connection or receiver key cannot be stored or used.
 */
class Connection_Exception extends \RuntimeException {
}
