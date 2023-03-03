<?php
/**
 * Places relayed files in uploads and creates their attachment rows.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Apply;

use ContentRelay\Receive\Bundle;
use ContentRelay\Receive\Receive_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Media writer (spec §8.4).
 *
 * One physical file per SHA-1: WPML keeps one attachment row per language pointing at the same file, so sub-sizes
 * are generated once and later language rows copy the file meta. Existing files are never overwritten; a different
 * file with the same name gets a unique name and the URL map records the rename.
 */
final class Media_Writer {

	/**
	 * Marks attachments created by an unfinished relay.
	 */
	public const PENDING_META = '_content_relay_pending';

	/**
	 * Receiver context.
	 *
	 * @var Receive_Context
	 */
	private Receive_Context $context;

	/**
	 * Bundle holding the files.
	 *
	 * @var Bundle
	 */
	private Bundle $bundle;
}
