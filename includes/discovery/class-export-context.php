<?php
/**
 * Shared state while discovering what a relay carries.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Discovery;

use ContentRelay\Content\Attributes_Codec;
use ContentRelay\Content\Block_Scanner;
use ContentRelay\Identity\Uuid;
use ContentRelay\Language\Adapter;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks referenced objects (and their strongest role), exported objects, requirements and warnings.
 *
 * Objects are keyed by their sender identity: `post:123` (attachments included), `term:45`, `user:6`.
 */
final class Export_Context {

	/**
	 * Created or overwritten on the receiver.
	 */
	public const ROLE_OWNED = 'owned';

	/**
	 * Must exist on the receiver; never modified.
	 */
	public const ROLE_REFERENCED = 'referenced';

	/**
	 * Only reported when missing (links).
	 */
	public const ROLE_SOFT = 'soft';
}
