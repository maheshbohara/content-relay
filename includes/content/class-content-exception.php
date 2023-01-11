<?php
/**
 * Raised when content cannot be edited without changing bytes the relay did not intend to change.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Content editing failure. Always a blocker: the relay never writes a silently altered page.
 */
final class Content_Exception extends \RuntimeException {
}
