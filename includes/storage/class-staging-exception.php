<?php
/**
 * Staging failure.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when the staging area cannot be written. Messages never contain paths.
 */
class Staging_Exception extends \RuntimeException {
}
