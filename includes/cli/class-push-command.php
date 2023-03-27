<?php
/**
 * WP-CLI: relay a page to a connected site.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Cli;

use ContentRelay\Identity\Uuid;
use ContentRelay\Send\Send_Exception;
use ContentRelay\Send\Sender;

defined( 'ABSPATH' ) || exit;

/**
 * Export → open a session → upload the files the receiver needs → show the receiver's plan → apply. The steps are the
 * Sender's, shared with the admin relay screen.
 */
final class Push_Command {

	/**
	 * Seconds each upload step may run before progress is saved and the next step starts.
	 */
	private const UPLOAD_BUDGET_SECONDS = 30.0;

	/**
	 * Relays a page — every language version, its media and what it needs — to a connected site.
	 *
	 * Without --yes the receiver's plan is shown and confirmation is asked before anything is written there.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : Id of the page (any language version).
	 *
	 * --to=<connection>
	 * : Connection label.
	 *
	 * [--overwrite]
	 * : Replace changes made on the receiver since the last relay (the receiver keeps a backup).
	 *
	 * [--yes]
	 * : Apply without asking for confirmation.
	 *
	 * [--resume=<session>]
	 * : Continue an interrupted push: uploads only what the receiver does not have yet.
	 *
	 * [--chunk-bytes=<bytes>]
	 * : Upload chunk size. Default 1048576, capped by the receiver.
	 *
	 * [--media-fallback=<url>]
	 * : Base URL of another copy of this site's uploads (e.g. https://example.com/wp-content/uploads). A media file
	 * missing on this site is downloaded from there at relay time (a dry run included) and saved into this site's
	 * uploads, instead of blocking the relay. The host must be public: private and local addresses are refused.
	 *
	 * [--skip-missing-media]
	 * : Relay a post even when one of its media files is missing here and from the fallback: that media is left out
	 * and references to it are dropped.
	 *
	 * [--flush-cache]
	 * : Flush the receiver's whole object cache after applying (for sites with Redis or Memcached).
	 *
	 * ## EXAMPLES
	 *
	 *     wp content-relay push 4356 --to=prod
	 *     wp content-relay push 4356 --to=prod --resume=0f8e…
	 *
	 * @param array<int, string>         $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$label     = (string) ( $assoc_args['to'] ?? '' );
		$overwrite = (bool) ( $assoc_args['overwrite'] ?? false );
		$resume    = (string) ( $assoc_args['resume'] ?? '' );
		$id        = '';

		if ( isset( $assoc_args['media-fallback'] ) ) {
			$fallback = (string) $assoc_args['media-fallback'];
			add_filter(
				'content_relay_media_fallback_url',
				static function () use ( $fallback ): string {
					return $fallback;
				}
			);
		}

		if ( ! empty( $assoc_args['skip-missing-media'] ) ) {
			add_filter( 'content_relay_skip_missing_media', '__return_true' );
		}

		try {
			$sender = Sender::for_site();

			if ( '' !== $resume ) {
				if ( ! Uuid::is_valid( $resume ) ) {
					\WP_CLI::error( __( 'That is not a relay session id.', 'content-relay' ) );
				}
				$state = $sender->resume( $resume );
				if ( $overwrite !== (bool) $state['overwrite'] ) {
					\WP_CLI::warning( $state['overwrite'] ? __( 'The session was opened with --overwrite; that setting is kept.', 'content-relay' ) : __( 'The session was opened without --overwrite; that setting is kept.', 'content-relay' ) );
				}
				foreach ( (array) $state['files'] as $file ) {
					if ( empty( $file['complete'] ) && (int) $file['received'] > 0 ) {
						/* translators: 1: file name, 2: bytes already received, 3: file size in bytes. */
						\WP_CLI::log( sprintf( __( 'Resuming %1$s from byte %2$d of %3$d', 'content-relay' ), $file['name'], (int) $file['received'], (int) $file['bytes'] ) );
					}
				}
			} else {
				$state = $sender->start( (int) $args[0], $label, $overwrite, (int) ( $assoc_args['chunk-bytes'] ?? Sender::DEFAULT_CHUNK_BYTES ) );
			}

			$id = (string) $state['session'];
			/* translators: 1: relay session id, 2: URL of the receiving site. */
			\WP_CLI::log( sprintf( __( 'Session %1$s on %2$s', 'content-relay' ), $id, $state['receiver'] ) );

			$uploaded = 0;
			while ( empty( $state['progress']['done'] ) ) {
				$state = $sender->upload(
					$id,
					self::UPLOAD_BUDGET_SECONDS,
					static function ( array $file ) use ( &$uploaded ): void {
						++$uploaded;
						/* translators: 1: file name, 2: human-readable file size. */
						\WP_CLI::log( sprintf( __( 'Uploaded %1$s (%2$s)', 'content-relay' ), $file['name'], size_format( (int) $file['bytes'] ) ) );
					}
				);
			}
			/* translators: %d: number of files uploaded. */
			\WP_CLI::log( sprintf( __( 'Files uploaded: %d', 'content-relay' ), $uploaded ) );

			$session = $sender->plan( $id );
		} catch ( Send_Exception $e ) {
			/* translators: %s: relay session id. */
			\WP_CLI::error( $e->plain_message() . ( '' !== $id ? ' ' . sprintf( __( 'Resume with --resume=%s.', 'content-relay' ), $id ) : '' ) );
			return;
		}

		$plan = (array) ( $session['plan'] ?? array() );
		if ( array() === $plan ) {
			/* translators: 1: relay session id, 2: session status. */
			\WP_CLI::error( sprintf( __( 'Session %1$s is %2$s and has no plan.', 'content-relay' ), $id, $session['status'] ?? '?' ) );
		}

		Plan_Printer::print( $plan );

		$pending = (int) ( $session['files_pending'] ?? 0 );
		if ( $pending > 0 ) {
			/* translators: 1: number of files, 2: relay session id. */
			\WP_CLI::error( sprintf( _n( '%1$d file is still missing on the receiver. Resume with --resume=%2$s.', '%1$d files are still missing on the receiver. Resume with --resume=%2$s.', $pending, 'content-relay' ), $pending, $id ) );
		}
		if ( array() !== $plan['blockers'] ) {
			self::abort( $sender, $id );
			\WP_CLI::halt( 1 );
		}

		$changes = Plan_Printer::changes( $plan );
		if ( array() === $changes ) {
			self::abort( $sender, $id );
			\WP_CLI::success( __( 'Nothing to do: the receiver already matches this content.', 'content-relay' ) );
			return;
		}

		if ( ! ( $assoc_args['yes'] ?? false ) ) {
			$count = count( $changes );
			/* translators: 1: number of changes, 2: URL of the receiving site. */
			$prompt = sprintf( _n( 'Apply %1$d change to %2$s?', 'Apply %1$d changes to %2$s?', $count, 'content-relay' ), $count, $plan['receiver'] );
			fwrite( STDOUT, $prompt . ' [y/n] ' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Interactive CLI prompt.
			$answer = strtolower( trim( (string) fgets( STDIN ) ) );
			if ( 'y' !== $answer ) {
				self::abort( $sender, $id );
				\WP_CLI::log( __( 'Cancelled; the session was aborted on the receiver.', 'content-relay' ) );
				return;
			}
		}

		try {
			$applied = $sender->apply( $id, (string) $plan['hash'], null, (bool) ( $assoc_args['flush-cache'] ?? false ) );
		} catch ( Send_Exception $e ) {
			/* translators: 1: error message, 2: relay session id. */
			\WP_CLI::error( sprintf( __( '%1$s The session stays open; review it again with --resume=%2$s.', 'content-relay' ), $e->plain_message(), $id ) );
			return;
		}

		$result = (array) $applied['result'];
		\WP_CLI\Utils\format_items( 'table', (array) $result['written'], array( 'action', 'key', 'id', 'url' ) );
		foreach ( (array) $result['problems'] as $problem ) {
			\WP_CLI::warning( $problem );
		}
		foreach ( (array) ( $result['warnings'] ?? array() ) as $warning ) {
			\WP_CLI::warning( $warning );
		}
		if ( array() !== (array) ( $result['caches'] ?? array() ) ) {
			/* translators: %s: comma-separated list of page caches. */
			\WP_CLI::log( sprintf( __( 'Page caches purged on the receiver: %s', 'content-relay' ), implode( ', ', (array) $result['caches'] ) ) );
		}
		/* translators: 1: relay session id, 2: apply session id on the receiving site. */
		\WP_CLI::log( sprintf( __( 'Relay session %1$s (receiver apply session %2$s).', 'content-relay' ), $id, (string) $applied['apply_session'] ) );

		\WP_CLI::log( __( 'Checking the relayed pages on the receiver…', 'content-relay' ) );
		$verified = false;
		try {
			$report   = $sender->verify( $id );
			$verified = $report['ok'];
			Verify_Command::print( $report );
		} catch ( Send_Exception $e ) {
			\WP_CLI::warning( $e->plain_message() );
		}

		/* translators: 1: relay session id, 2: connection label. */
		\WP_CLI::log( sprintf( __( 'Roll back with: wp content-relay rollback %1$s --to=%2$s', 'content-relay' ), $id, $state['connection'] ) );

		if ( array() !== $result['problems'] ) {
			\WP_CLI::error( __( 'Relay applied, but the receiver found integrity problems.', 'content-relay' ) );
		}
		if ( ! $verified ) {
			/* translators: %s: relay session id. */
			\WP_CLI::error( sprintf( __( 'Relay applied, but checks on the receiver failed. Nothing was rolled back; check again with: wp content-relay verify %s', 'content-relay' ), $id ) );
		}
		\WP_CLI::success( __( 'Relay applied and verified.', 'content-relay' ) );
	}

	/**
	 * Aborts a session, reporting (not stopping on) a failure.
	 *
	 * @param Sender $sender Sender.
	 * @param string $id     Session id.
	 */
	private static function abort( Sender $sender, string $id ): void {
		try {
			$sender->abort( $id );
		} catch ( Send_Exception $e ) {
			\WP_CLI::warning( $e->plain_message() );
		}
	}
}
