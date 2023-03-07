<?php
/**
 * Undoes a relay from its backup and session record.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Apply;

use ContentRelay\Receive\Receive_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Rollback (spec §11): restores replaced objects exactly, removes created posts, attachments, terms and files.
 */
final class Rollback {

	/**
	 * Receiver context.
	 *
	 * @var Receive_Context
	 */
	private Receive_Context $context;

	/**
	 * Row writer.
	 *
	 * @var Post_Writer
	 */
	private Post_Writer $writer;

	/**
	 * Backup store.
	 *
	 * @var Backup
	 */
	private Backup $backup;
}
