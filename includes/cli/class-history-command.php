<?php
/**
 * WP-CLI: relay history.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Cli;

use ContentRelay\Config;
use ContentRelay\Send\Send_Exception;
use ContentRelay\Send\Sender;
use ContentRelay\Storage\Log_Table;

defined( 'ABSPATH' ) || exit;

/**
 * Lists relay sessions from the log table.
 */
final class History_Command {

	/**
	 * Lists relay sessions sent from and received by this site, newest first.
	 *
	 * Sent relays still marked applied are confirmed with their receiving sites first, which may have rolled them back.
	 *
	 * ## OPTIONS
	 *
	 * [--direction=<direction>]
	 * : Only sessions sent (out) or received (in).
	 * ---
	 * options:
	 *   - out
	 *   - in
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Number of sessions.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--offline]
	 * : Don't ask receiving sites to confirm applied relays; show this site's log as it is.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp content-relay history --direction=out
	 *
	 * @param array<int, string>         $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$direction = isset( $assoc_args['direction'] ) ? (string) $assoc_args['direction'] : null;
		$rows      = ( new Log_Table() )->recent( $direction, max( 1, (int) ( $assoc_args['limit'] ?? 20 ) ) );

		if ( empty( $assoc_args['offline'] ) ) {
			$rows = self::confirm( $rows );
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'created_at', 'direction', 'root_title', 'languages', 'peer_url', 'user_login', 'status', 'session' ) );
	}
}
