<?php
/**
 * Prints a plan in WP-CLI.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by `plan`, `apply` and `push`.
 */
final class Plan_Printer {

	/**
	 * Plan actions that write something.
	 */
	public const CHANGE_ACTIONS = array( 'create', 'create-new-file', 'replace-file', 'update', 'overwrite', 'adopt' );

	/**
	 * Prints a plan as a table followed by warnings and blockers.
	 *
	 * @param array<string, mixed> $plan Plan.
	 */
	public static function print( array $plan ): void {
		/* translators: 1: URL of the sending site, 2: URL of the receiving site. */
		\WP_CLI::log( sprintf( __( 'Relay from %1$s to %2$s', 'content-relay' ), $plan['source'], $plan['receiver'] ) );

		\WP_CLI\Utils\format_items( 'table', $plan['items'], array( 'action', 'label', 'id', 'via', 'role', 'note' ) );

		/* translators: %d: number of files to transfer. */
		\WP_CLI::log( sprintf( __( 'Files to transfer: %d', 'content-relay' ), count( $plan['files_needed'] ) ) );
		foreach ( $plan['warnings'] as $warning ) {
			\WP_CLI::warning( $warning );
		}
		foreach ( $plan['blockers'] as $blocker ) {
			\WP_CLI::log( \WP_CLI::colorize( '%R' . __( 'Blocked:', 'content-relay' ) . '%n ' ) . $blocker );
		}

		if ( array() === $plan['blockers'] ) {
			\WP_CLI::success( __( 'The plan can be applied.', 'content-relay' ) );
		} else {
			$count = count( $plan['blockers'] );
			/* translators: %d: number of blockers. */
			\WP_CLI::log( sprintf( _n( '%d blocker: the plan cannot be applied.', '%d blockers: the plan cannot be applied.', $count, 'content-relay' ), $count ) );
		}
	}

	/**
	 * Plan items that write something.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @return array<int, array<string, mixed>>
	 */
	public static function changes( array $plan ): array {
		return array_values(
			array_filter(
				$plan['items'],
				static function ( array $item ): bool {
					return in_array( $item['action'], self::CHANGE_ACTIONS, true );
				}
			)
		);
	}
}
