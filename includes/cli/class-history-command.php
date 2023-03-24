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

	/**
	 * Confirms sent relays marked applied with their receivers. A rollback found there updates the log; a status the
	 * receiver did not confirm is shown as `applied (unknown)` or `applied (unreachable)`.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function confirm( array $rows ): array {
		if ( ! Config::can_send() ) {
			return $rows;
		}
		try {
			$sender = Sender::for_site();
		} catch ( Send_Exception $e ) {
			return $rows;
		}

		$unreachable = array();
		foreach ( $rows as $index => $row ) {
			if ( Log_Table::OUT !== $row['direction'] || Log_Table::STATUS_APPLIED !== $row['status'] ) {
				continue;
			}

			$connection = (string) $row['connection'];
			$state      = isset( $unreachable[ $connection ] ) ? 'unreachable' : $sender->refresh_status( (string) $row['session'] );
			if ( 'unreachable' === $state ) {
				$unreachable[ $connection ] = true;
			}

			if ( Log_Table::STATUS_APPLIED !== $state ) {
				$rows[ $index ]['status'] = Log_Table::STATUS_ROLLED_BACK === $state ? $state : sprintf( 'applied (%s)', $state );
			}
		}

		return $rows;
	}
}
