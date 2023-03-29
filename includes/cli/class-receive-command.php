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

	/**
	 * Undoes a relay on this site: restores what it replaced and removes what it created.
	 *
	 * ## OPTIONS
	 *
	 * <session>
	 * : Session id printed by `wp content-relay apply` — or, with --to, the relay session id printed by `wp content-relay push`.
	 *
	 * [--to=<connection>]
	 * : Roll back a relay this site pushed to a connected site, instead of a relay received here.
	 *
	 * [--overwrite]
	 * : Roll back even if the relayed objects were changed after the relay.
	 *
	 * [--yes]
	 * : Roll back without asking for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp content-relay rollback 71171611-8199-44be-be66-bde437e58202
	 *     wp content-relay rollback 0f8e7c1a-1b2c-4d5e-8f90-a1b2c3d4e5f6 --to=prod
	 *
	 * @param array<int, string>         $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Named arguments.
	 */
	public function rollback( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['to'] ) ) {
			$this->remote_rollback( (string) $args[0], (string) $assoc_args['to'], $assoc_args );
			return;
		}

		/* translators: %s: session id. */
		\WP_CLI::confirm( sprintf( __( 'Roll back relay session %s?', 'content-relay' ), $args[0] ), $assoc_args );

		try {
			$summary = ( new \ContentRelay\Apply\Rollback( new Receive_Context( Adapter_Factory::create() ) ) )->rollback( (string) $args[0], (bool) ( $assoc_args['overwrite'] ?? false ) );
		} catch ( \ContentRelay\Apply\Apply_Exception $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}

		/* translators: 1: session id, 2: objects restored, 3: objects removed, 4: terms removed, 5: files removed. */
		\WP_CLI::success( sprintf( __( 'Rolled back %1$s: %2$d restored, %3$d removed, %4$d terms removed, %5$d files removed.', 'content-relay' ), $summary['session'], count( $summary['restored'] ), count( $summary['deleted'] ), count( $summary['terms'] ), $summary['files'] ) );
	}

	/**
	 * Rolls back a session on a connected receiver.
	 *
	 * @param string                     $session    Relay session id.
	 * @param string                     $label      Connection label.
	 * @param array<string, string|bool> $assoc_args Named arguments.
	 */
	private function remote_rollback( string $session, string $label, array $assoc_args ): void {
		/* translators: 1: relay session id, 2: connection label. */
		\WP_CLI::confirm( sprintf( __( 'Roll back relay session %1$s on "%2$s"?', 'content-relay' ), $session, $label ), $assoc_args );

		try {
			$data = Sender::for_site()->rollback( $session, (bool) ( $assoc_args['overwrite'] ?? false ), $label );
		} catch ( Send_Exception $e ) {
			\WP_CLI::error( $e->plain_message() );
			return;
		}

		$summary = (array) $data['rollback'];
		/* translators: 1: relay session id, 2: connection label, 3: objects restored, 4: objects removed, 5: terms removed, 6: files removed. */
		\WP_CLI::success( sprintf( __( 'Rolled back %1$s on "%2$s": %3$d restored, %4$d removed, %5$d terms removed, %6$d files removed.', 'content-relay' ), $session, $label, count( (array) $summary['restored'] ), count( (array) $summary['deleted'] ), count( (array) $summary['terms'] ), (int) $summary['files'] ) );
	}

	/**
	 * Asks a connected site for its plan of a page, then cancels the session there.
	 *
	 * @param int    $post_id   Page id.
	 * @param string $label     Connection label.
	 * @param bool   $overwrite Plan with overwrite.
	 * @return array<string, mixed> Plan; items that would replace something carry a `diff`.
	 */
	private function remote_plan( int $post_id, string $label, bool $overwrite ): array {
		$sender  = null;
		$session = '';

		try {
			$sender  = Sender::for_site();
			$session = (string) $sender->start( $post_id, $label, $overwrite )['session'];
			$data    = $sender->plan( $session );
		} catch ( Send_Exception $e ) {
			self::cancel( $sender, $session );
			\WP_CLI::error( $e->plain_message() );
			return array();
		}

		self::cancel( $sender, $session );

		return (array) ( $data['plan'] ?? array() );
	}
}
