<?php
/**
 * The relay log: sessions sent from and received by this site.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * `{prefix}content_relay_log` (spec §16). One row per relay session and direction; the history screen and
 * `wp content-relay history` read it, rollback updates it.
 */
final class Log_Table {

	/**
	 * Schema version of the table.
	 */
	public const DB_VERSION = 1;

	/**
	 * Option holding the installed schema version.
	 */
	public const VERSION_OPTION = 'content_relay_db_version';

	/**
	 * Directions.
	 */
	public const OUT = 'out';
	public const IN  = 'in';

	/**
	 * Statuses.
	 */
	public const STATUS_PLANNED     = 'planned';
	public const STATUS_APPLIED     = 'applied';
	public const STATUS_ABORTED     = 'aborted';
	public const STATUS_ROLLED_BACK = 'rolled-back';
}
