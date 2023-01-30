<?php
/**
 * The receiver's staging area for sessions arriving over REST.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Storage;

use ContentRelay\Identity\Uuid;

defined( 'ABSPATH' ) || exit;

/**
 * One directory per session: `session.json` (state), `manifest.json` and `files/<sha1>` — the same bundle layout the
 * planner and applier read from `wp content-relay bundle` output. Files arrive in chunks as `files/<sha1>.part` and are
 * renamed only once their SHA-1 verifies, so the planner never sees a partial file.
 */
final class Staging {

	/**
	 * Seconds after the last activity when an unapplied session is removed.
	 */
	public const TTL_SECONDS = 86400;

	/**
	 * Session states.
	 */
	public const STATUS_PLANNED     = 'planned';
	public const STATUS_APPLYING    = 'applying';
	public const STATUS_APPLIED     = 'applied';
	public const STATUS_ROLLED_BACK = 'rolled-back';

	/**
	 * Staging root directory without trailing slash.
	 *
	 * @var string
	 */
	private string $root;
}
