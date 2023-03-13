<?php
/**
 * Relays many root posts, one translation group at a time.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Send;

use ContentRelay\Language\Adapter_Factory;

defined( 'ABSPATH' ) || exit;

/**
 * Selects translation groups and relays (or plans) each one as its own session.
 *
 * Each group is independent: a failure is recorded and the batch moves on, so one bad post never stops the rest.
 */
final class Batch_Relay {

	/**
	 * Plan actions that write something on the receiver.
	 */
	public const CHANGE_ACTIONS = array( 'create', 'create-new-file', 'replace-file', 'update', 'overwrite', 'adopt' );

	/**
	 * Seconds each upload step may run before progress is saved and the next step starts.
	 */
	private const UPLOAD_BUDGET_SECONDS = 30.0;

	/**
	 * Sender.
	 *
	 * @var Sender
	 */
	private Sender $sender;

	/**
	 * Constructor.
	 *
	 * @param Sender $sender Sender.
	 */
	public function __construct( Sender $sender ) {
		$this->sender = $sender;
	}
}
