<?php
/**
 * Services the receiver needs while planning and applying.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Receive;

use ContentRelay\Content\Attributes_Codec;
use ContentRelay\Content\Block_Scanner;
use ContentRelay\Content\Content_Editor;
use ContentRelay\Identity\Fingerprint;
use ContentRelay\Identity\State_Fingerprint;
use ContentRelay\Identity\Sync_State;
use ContentRelay\Identity\Uuid;
use ContentRelay\Language\Adapter;

defined( 'ABSPATH' ) || exit;

/**
 * Receiver context.
 */
final class Receive_Context {

	/**
	 * Language adapter.
	 *
	 * @var Adapter
	 */
	public Adapter $language;

	/**
	 * UUID service.
	 *
	 * @var Uuid
	 */
	public Uuid $uuid;
}
