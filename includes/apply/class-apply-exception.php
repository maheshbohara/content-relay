<?php
/**
 * Raised when applying a plan fails. The content commit is rolled back.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Apply;

defined( 'ABSPATH' ) || exit;

/**
 * Apply failure.
 */
final class Apply_Exception extends \RuntimeException {
}
