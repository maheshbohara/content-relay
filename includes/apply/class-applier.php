<?php
/**
 * Applies an approved plan.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Apply;

use ContentRelay\Identity\State_Fingerprint;
use ContentRelay\Identity\Sync_State;
use ContentRelay\Identity\Uuid;
use ContentRelay\Receive\Bundle;
use ContentRelay\Receive\Planner;
use ContentRelay\Receive\Receive_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Apply sequence (spec §8.5): re-plan and compare with the approved plan, place media, back up, commit content in one
 * transaction, then refresh caches and verify what was written.
 */
final class Applier {

	/**
	 * Plan actions that write an owned object.
	 */
	private const WRITE_ACTIONS = array( 'create', 'create-new-file', 'replace-file', 'update', 'overwrite', 'adopt' );

	/**
	 * Plan actions whose object keeps its existing receiver id.
	 */
	private const EXISTING_ACTIONS = array( 'reuse', 'map', 'update', 'overwrite', 'adopt', 'unchanged' );

	/**
	 * Plan actions that place a new file and create a new attachment.
	 */
	private const NEW_FILE_ACTIONS = array( 'create', 'create-new-file', 'replace-file' );
}
