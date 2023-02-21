<?php
/**
 * What a relay would change on this site, per object, for the review screen.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Receive;

use ContentRelay\Apply\Apply_Exception;
use ContentRelay\Apply\Rewriter;
use ContentRelay\Apply\Url_Map;
use ContentRelay\Discovery\Exporter;
use ContentRelay\Support\Line_Diff;

defined( 'ABSPATH' ) || exit;

/**
 * Compares each object a plan would replace with what this site holds now. Reads only.
 *
 * Incoming values are rewritten with the receiver ids the plan already knows, so an unchanged reference does not show
 * up as a change. Objects the relay would create have no id yet; references to them show as `0`.
 */
final class Differ {

	/**
	 * Plan actions whose existing object is replaced, and therefore diffed.
	 */
	public const ACTIONS = array( 'update', 'overwrite', 'adopt', 'conflict' );

	/**
	 * Plan actions whose object keeps a known receiver id.
	 */
	private const KNOWN_ID_ACTIONS = array( 'reuse', 'map', 'update', 'overwrite', 'adopt', 'unchanged', 'conflict' );

	/**
	 * Post fields compared (the fields a relay writes).
	 */
	private const FIELDS = array( 'post_title', 'post_name', 'post_status', 'post_excerpt', 'post_date', 'post_date_gmt', 'post_password', 'menu_order', 'comment_status', 'ping_status', 'post_mime_type' );
}
