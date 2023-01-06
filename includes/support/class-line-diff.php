<?php
/**
 * Line diff for the review screen.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Unified-style line diff with context, bounded in time and size so a very long page cannot stall a request.
 *
 * Common leading and trailing lines are trimmed first; the rest is compared with an LCS table only when it is small
 * enough (`$max_cells`), otherwise it is shown as removed-then-added.
 */
final class Line_Diff {

	/**
	 * Largest LCS table (old lines × new lines) computed.
	 */
	public const MAX_CELLS = 250000;

	/**
	 * Unchanged lines shown around each change.
	 */
	public const CONTEXT = 2;

	/**
	 * Most lines returned across all hunks.
	 */
	public const MAX_LINES = 400;

	/**
	 * Longest line returned, in bytes; longer lines are cut.
	 */
	public const MAX_LINE_BYTES = 2000;
}
