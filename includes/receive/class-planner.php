<?php
/**
 * Turns a bundle into a plan: what would be created, updated, reused or refused. Writes nothing.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Receive;

use ContentRelay\Identity\State_Fingerprint;

defined( 'ABSPATH' ) || exit;

/**
 * Planner (spec §8.2, §10).
 */
final class Planner {

	/**
	 * Seconds an edit lock is considered active.
	 */
	private const EDIT_LOCK_SECONDS = 150;

	/**
	 * Receiver context.
	 *
	 * @var Receive_Context
	 */
	private Receive_Context $context;

	/**
	 * Matches of the plan being built.
	 *
	 * @var array<string, array{id: int|null, via: string, candidates: int[], path_conflict: bool}>
	 */
	private array $matches = array();

	/**
	 * Keys of objects that will get a new receiver id in the plan being built (created or replaced).
	 *
	 * @var array<string, bool>
	 */
	private array $new_keys = array();
}
