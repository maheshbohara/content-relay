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

	/**
	 * Block scanner.
	 *
	 * @var Block_Scanner
	 */
	public Block_Scanner $scanner;

	/**
	 * Attribute codec.
	 *
	 * @var Attributes_Codec
	 */
	public Attributes_Codec $codec;

	/**
	 * Content editor.
	 *
	 * @var Content_Editor
	 */
	public Content_Editor $editor;

	/**
	 * Fingerprint hasher.
	 *
	 * @var Fingerprint
	 */
	public Fingerprint $fingerprint;

	/**
	 * Receiver state fingerprints.
	 *
	 * @var State_Fingerprint
	 */
	public State_Fingerprint $state;

	/**
	 * Sync state store.
	 *
	 * @var Sync_State
	 */
	public Sync_State $sync;
}
