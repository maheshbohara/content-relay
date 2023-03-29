<?php
/**
 * WP-CLI commands for the receiving side.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Cli;

use ContentRelay\Language\Adapter_Factory;
use ContentRelay\Receive\Bundle;
use ContentRelay\Receive\Planner;
use ContentRelay\Receive\Receive_Context;
use ContentRelay\Receive\Receive_Exception;
use ContentRelay\Send\Send_Exception;
use ContentRelay\Send\Sender;

defined( 'ABSPATH' ) || exit;

/**
 * Plans and applies relay bundles on this site.
 */
final class Receive_Command {

	/**
	 * Shows what applying a bundle on this site — or relaying a page to a connected site — would do. Writes nothing.
	 *
	 * With --to, the page is exported and sent to that connection's receiving site, which plans it; the session is then
	 * cancelled there. No file is uploaded and nothing is written.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Bundle directory (manifest.json + files/), or with --to the id of a page on this site.
	 *
	 * [--to=<connection>]
	 * : Ask this connection's receiving site for its plan of the page.
	 *
	 * [--overwrite]
	 * : Plan as if changes made on the receiving site since the last relay may be overwritten.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp content-relay plan /tmp/vietnam
	 *     wp content-relay plan 4356 --to=prod
	 *
	 * @param array<int, string>         $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Named arguments.
	 */
	public function plan( array $args, array $assoc_args ): void {
		$overwrite = (bool) ( $assoc_args['overwrite'] ?? false );
		if ( isset( $assoc_args['to'] ) ) {
			$plan = $this->remote_plan( (int) $args[0], (string) $assoc_args['to'], $overwrite );
		} else {
			$plan = ( new Planner( new Receive_Context( Adapter_Factory::create() ) ) )->plan( $this->bundle( (string) $args[0] ), $overwrite );
		}

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		Plan_Printer::print( $plan );
	}

	/**
	 * Applies a bundle on this site after showing its plan.
	 *
	 * ## OPTIONS
	 *
	 * <dir>
	 * : Bundle directory (manifest.json + files/).
	 *
	 * [--overwrite]
	 * : Replace changes made on this site since the last relay (a backup is kept).
	 *
	 * [--yes]
	 * : Apply without asking for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp content-relay apply /tmp/vietnam
	 *
	 * @param array<int, string>         $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Named arguments.
	 */
	public function apply( array $args, array $assoc_args ): void {
		$bundle    = $this->bundle( (string) $args[0] );
		$context   = new Receive_Context( Adapter_Factory::create() );
		$overwrite = (bool) ( $assoc_args['overwrite'] ?? false );
		$plan      = ( new Planner( $context ) )->plan( $bundle, $overwrite );

		Plan_Printer::print( $plan );
		if ( array() !== $plan['blockers'] ) {
			\WP_CLI::halt( 1 );
		}

		$changes = Plan_Printer::changes( $plan );
		if ( array() === $changes ) {
			\WP_CLI::success( __( 'Nothing to do: this site already matches the bundle.', 'content-relay' ) );
			return;
		}

		$count = count( $changes );
		/* translators: 1: number of changes, 2: URL of the receiving site. */
		\WP_CLI::confirm( sprintf( _n( 'Apply %1$d change to %2$s?', 'Apply %1$d changes to %2$s?', $count, 'content-relay' ), $count, $plan['receiver'] ), $assoc_args );

		try {
			$result = ( new \ContentRelay\Apply\Applier( $context ) )->apply( $bundle, $overwrite, (string) $plan['hash'] );
		} catch ( \ContentRelay\Apply\Apply_Exception $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $result['written'], array( 'action', 'key', 'id', 'url' ) );
		foreach ( $result['problems'] as $problem ) {
			\WP_CLI::warning( $problem );
		}
		foreach ( $result['warnings'] as $warning ) {
			\WP_CLI::warning( $warning );
		}
		if ( array() !== $result['caches'] ) {
			/* translators: %s: comma-separated list of page caches. */
			\WP_CLI::log( sprintf( __( 'Page caches purged: %s', 'content-relay' ), implode( ', ', $result['caches'] ) ) );
		}
		/* translators: 1: apply session id, 2: backup location. */
		\WP_CLI::log( sprintf( __( 'Session %1$s. Backup: %2$s', 'content-relay' ), $result['session'], $result['backup'] ) );

		if ( array() === $result['problems'] ) {
			\WP_CLI::success( __( 'Relay applied.', 'content-relay' ) );
		} else {
			\WP_CLI::error( __( 'Relay applied, but verification found problems.', 'content-relay' ) );
		}
	}
}
