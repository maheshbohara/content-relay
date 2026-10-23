<?php
/**
 * Sends a page to a connected site, step by step.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Send;

use ContentRelay\Config;
use ContentRelay\Connection\Client;
use ContentRelay\Connection\Connection_Exception;
use ContentRelay\Connection\Connections;
use ContentRelay\Connection\Vault;
use ContentRelay\Discovery\Export_Context;
use ContentRelay\Discovery\Export_Exception;
use ContentRelay\Discovery\Exporter;
use ContentRelay\Identity\Uuid;
use ContentRelay\Language\Adapter_Factory;
use ContentRelay\Storage\Log_Table;

defined( 'ABSPATH' ) || exit;

/**
 * The sending half of a relay, shared by `wp content-relay push` and the admin relay screen.
 *
 * Every step is a separate call so a browser can drive it without long requests: start (export, handshake, open the
 * receiver session) → upload (time-boxed, resumable) → plan → apply, with abort and rollback. The manifest a session
 * was opened with is kept in `uploads/content-relay/outgoing/{session}/` so later steps upload exactly those files, and
 * the log table records the session for the history screen.
 */
final class Sender {

	/**
	 * Default upload chunk size (1 MiB); the receiver's limit wins when it is lower.
	 */
	public const DEFAULT_CHUNK_BYTES = 1048576;

	/**
	 * How often an upload may restart from the receiver's offset or after a hash mismatch before giving up.
	 */
	private const MAX_RETRIES = 3;

	/**
	 * Seconds a status confirmed by the receiver is trusted before it is asked again.
	 */
	private const STATUS_TTL = 300;

	/**
	 * Connections.
	 *
	 * @var Connections
	 */
	private Connections $connections;

	/**
	 * Log.
	 *
	 * @var Log_Table
	 */
	private Log_Table $log;

	/**
	 * Outgoing directory without trailing slash.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Constructor.
	 *
	 * @param Connections $connections Connections.
	 * @param Log_Table   $log         Log.
	 * @param string      $root        Outgoing directory, e.g. `…/uploads/content-relay/outgoing`.
	 */
	public function __construct( Connections $connections, Log_Table $log, string $root ) {
		$this->connections = $connections;
		$this->log         = $log;
		$this->root        = rtrim( $root, '/' );
	}

	/**
	 * Sender of this site.
	 *
	 * @throws Send_Exception When sending is off or connection keys cannot be decrypted here.
	 */
	public static function for_site(): self {
		if ( ! Config::can_send() ) {
			throw new Send_Exception( esc_html__( 'Sending is off on this site. Switch it on under Settings → Content Relay.', 'content-relay' ), 'sending_off', 403 );
		}

		$vault = Vault::from_config();
		if ( null === $vault ) {
			throw new Send_Exception( esc_html__( 'This site has no key material for connection keys: define CONTENT_RELAY_ENCRYPTION_KEY, or real AUTH_KEY and SECURE_AUTH_KEY salts, in wp-config.php.', 'content-relay' ), 'no_key_material', 500 );
		}

		$uploads = wp_get_upload_dir();

		return new self( new Connections( $vault ), new Log_Table(), untrailingslashit( (string) $uploads['basedir'] ) . '/content-relay/outgoing' );
	}

	/**
	 * Connection store.
	 */
	public function connections(): Connections {
		return $this->connections;
	}

	/**
	 * Runs a handshake with a stored connection.
	 *
	 * @param string $label Connection label.
	 * @return array<string, mixed> Receiver environment.
	 *
	 * @throws Send_Exception When the connection is unknown, the handshake fails or the schema differs.
	 */
	public function handshake( string $label ): array {
		return self::handshake_client( $this->client( $label ) );
	}

	/**
	 * Runs a handshake with any client (also used before a connection is stored).
	 *
	 * @param Client $client Client.
	 * @return array<string, mixed> Receiver environment.
	 *
	 * @throws Send_Exception When the handshake fails or the receiver reads another manifest schema.
	 */
	public static function handshake_client( Client $client ): array {
		$environment = self::expect( $client->handshake(), esc_html__( 'Handshake failed', 'content-relay' ) );
		if ( CONTENT_RELAY_SCHEMA !== ( $environment['schema'] ?? null ) ) {
			throw new Send_Exception(
				sprintf(
					/* translators: 1: manifest schema the receiver reads, 2: manifest schema this site writes. */
					esc_html__( 'The receiver reads manifest schema %1$s; this site writes schema %2$d. Update the plugin on both sites.', 'content-relay' ),
					esc_html( (string) wp_json_encode( $environment['schema'] ?? null ) ),
					(int) CONTENT_RELAY_SCHEMA
				),
				'schema_mismatch'
			);
		}

		return $environment;
	}

	/**
	 * Exports a page and opens a session on the receiver.
	 *
	 * @param int    $post_id     Page id (any language version).
	 * @param string $label       Connection label.
	 * @param bool   $overwrite   Plan with overwrite.
	 * @param int    $chunk_bytes Upload chunk size.
	 * @return array<string, mixed> Session state with progress.
	 *
	 * @throws Send_Exception When anything fails; nothing is left open on the receiver in that case.
	 */
	public function start( int $post_id, string $label, bool $overwrite, int $chunk_bytes = self::DEFAULT_CHUNK_BYTES ): array {
		$connection  = $this->connection( $label );
		$client      = new Client( $connection );
		$environment = self::handshake_client( $client );

		try {
			$manifest = ( new Exporter( new Export_Context( Adapter_Factory::create() ) ) )->export( $post_id );
		} catch ( Export_Exception $e ) {
			throw new Send_Exception( esc_html( html_entity_decode( $e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), 'export_failed', 400, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped; the previous exception is chained, not output.
		}

		$data    = self::expect( $client->create_session( $manifest, $overwrite ), esc_html__( 'The receiver refused the relay', 'content-relay' ) );
		$session = (string) ( $data['session'] ?? '' );
		if ( ! Uuid::is_valid( $session ) ) {
			throw new Send_Exception( esc_html__( 'The receiver returned no valid session id.', 'content-relay' ), 'bad_response', 502 );
		}

		$root      = (array) ( $manifest['objects'][ $manifest['root'] ] ?? array() );
		$languages = array();
		foreach ( (array) $manifest['roots'] as $key ) {
			$language = $manifest['objects'][ $key ]['language'] ?? null;
			if ( is_string( $language ) ) {
				$languages[] = $language;
			}
		}

		$state = array(
			'session'      => $session,
			'connection'   => $label,
			'receiver'     => (string) ( $environment['home_url'] ?? $connection['url'] ),
			'post_id'      => $post_id,
			'title'        => (string) ( $root['title'] ?? '' ),
			'languages'    => $languages,
			'overwrite'    => $overwrite,
			'uploads_url'  => (string) ( $environment['uploads_url'] ?? '' ),
			'object_cache' => ! empty( $environment['object_cache'] ),
			'chunk_bytes'  => max( 1, min( $chunk_bytes, (int) ( $environment['limits']['max_chunk_bytes'] ?? self::DEFAULT_CHUNK_BYTES ) ) ),
			'files'        => self::files_state( $manifest, (array) ( $data['files'] ?? array() ) ),
			'created_at'   => gmdate( 'c' ),
		);

		$this->write( $session, 'manifest.json', $manifest );
		$this->write( $session, 'state.json', $state );

		$user = wp_get_current_user();
		$this->log->insert(
			array(
				'session'      => $session,
				'direction'    => Log_Table::OUT,
				'connection'   => $label,
				'peer_url'     => $state['receiver'],
				'kid'          => $connection['kid'],
				'root_uuid'    => (string) ( $root['uuid'] ?? '' ),
				'root_title'   => $state['title'],
				'root_post_id' => $post_id,
				'languages'    => implode( ',', $languages ),
				'user_login'   => $user->exists() ? $user->user_login : 'wp-cli',
				'status'       => Log_Table::STATUS_PLANNED,
			)
		);

		return self::with_progress( $state );
	}

	/**
	 * Refreshes an open session's file progress from the receiver (after an interruption).
	 *
	 * @param string $session Session id.
	 * @return array<string, mixed> Session state with progress.
	 *
	 * @throws Send_Exception When the session is unknown here or no longer open on the receiver.
	 */
	public function resume( string $session ): array {
		$state = $this->state( $session );
		$data  = self::expect( $this->client( (string) $state['connection'] )->session( $session ), esc_html__( 'Cannot resume the session', 'content-relay' ) );
		if ( 'planned' !== ( $data['status'] ?? '' ) ) {
			throw new Send_Exception(
				sprintf(
					/* translators: %s: session status on the receiver, e.g. applied. */
					esc_html__( 'The session is %s on the receiver and can no longer be resumed.', 'content-relay' ),
					esc_html( (string) ( $data['status'] ?? '?' ) )
				),
				'session_not_open'
			);
		}

		$state['files'] = self::files_state( $this->manifest( $session ), (array) ( $data['files'] ?? array() ) );
		$this->write( $session, 'state.json', $state );

		return self::with_progress( $state );
	}

	/**
	 * Uploads what the receiver still needs, for at most `$budget_seconds` (at least one chunk per call).
	 *
	 * @param string        $session        Session id.
	 * @param float         $budget_seconds Time budget.
	 * @param callable|null $on_complete    Called with each file that completes during this call.
	 * @return array<string, mixed> Session state with progress; `progress.done` once every file is on the receiver.
	 *
	 * @throws Send_Exception When a file changed here or the receiver refuses an upload.
	 */
	public function upload( string $session, float $budget_seconds, ?callable $on_complete = null ): array {
		$state    = $this->state( $session );
		$client   = $this->client( (string) $state['connection'] );
		$uploads  = wp_get_upload_dir();
		$base     = untrailingslashit( (string) $uploads['basedir'] );
		$deadline = microtime( true ) + $budget_seconds;
		$sent     = false;

		foreach ( $state['files'] as $index => $file ) {
			if ( ! empty( $file['complete'] ) ) {
				continue;
			}

			$path = $base . '/' . $file['source'];
			if ( '' === (string) $file['source'] || ! is_readable( $path ) || sha1_file( $path ) !== $file['sha1'] ) {
				throw new Send_Exception(
					sprintf(
						/* translators: %s: file name. */
						esc_html__( 'The file %s changed or disappeared on this site after the relay started. Cancel the relay and start again.', 'content-relay' ),
						esc_html( (string) $file['name'] )
					),
					'file_changed'
				);
			}

			$retries = 0;
			while ( empty( $state['files'][ $index ]['complete'] ) ) {
				if ( $sent && microtime( true ) >= $deadline ) {
					$this->write( $session, 'state.json', $state );
					return self::with_progress( $state );
				}

				$offset   = (int) $state['files'][ $index ]['received'];
				$chunk    = (string) file_get_contents( $path, false, null, $offset, (int) $state['chunk_bytes'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads one chunk of a local uploads file.
				$response = $client->upload( $session, (string) $file['sha1'], $offset, $chunk );
				$sent     = true;

				if ( $response['ok'] ) {
					$state['files'][ $index ]['received'] = (int) $response['data']['received'];
					$state['files'][ $index ]['complete'] = ! empty( $response['data']['complete'] );
				} elseif ( in_array( $response['code'], array( 'offset_mismatch', 'hash_mismatch' ), true ) && $retries < self::MAX_RETRIES ) {
					++$retries;
					$state['files'][ $index ]['received'] = (int) ( $response['data']['received'] ?? 0 );
				} else {
					$this->write( $session, 'state.json', $state );
					self::expect(
						$response,
						sprintf(
							/* translators: 1: file name, 2: byte offset. */
							esc_html__( 'Upload of %1$s failed at byte %2$d', 'content-relay' ),
							esc_html( (string) $file['name'] ),
							$offset
						)
					);
				}
			}

			$this->write( $session, 'state.json', $state );
			if ( null !== $on_complete ) {
				$on_complete( $state['files'][ $index ] );
			}
		}

		return self::with_progress( $state );
	}

	/**
	 * The receiver's plan for the session (re-planned against the files it has).
	 *
	 * @param string    $session   Session id.
	 * @param bool|null $overwrite Plan with or without overwrite; null keeps the session's setting.
	 * @return array<string, mixed> Receiver session data: plan (items may carry a `diff`), files, files_pending.
	 *
	 * @throws Send_Exception When the receiver cannot be reached or refuses.
	 */
	public function plan( string $session, ?bool $overwrite = null ): array {
		$state = $this->state( $session );
		$data  = self::expect( $this->client( (string) $state['connection'] )->session( $session, $overwrite ), esc_html__( 'Cannot read the plan', 'content-relay' ) );

		if ( is_array( $data['plan'] ?? null ) ) {
			$plan = $data['plan'];
			foreach ( array_keys( (array) $plan['items'] ) as $index ) {
				unset( $plan['items'][ $index ]['diff'] );
			}
			$this->log->update( $session, Log_Table::OUT, array( 'plan' => $plan ) );
		}

		return $data;
	}

	/**
	 * Applies the approved plan on the receiver.
	 *
	 * @param string    $session   Session id.
	 * @param string    $plan_hash Hash of the approved plan.
	 * @param bool|null $overwrite   The overwrite setting the plan was made with; null keeps the session's setting.
	 * @param bool      $flush_cache Flush the receiver's whole object cache after applying.
	 * @return array<string, mixed> Receiver session data with `result` and `apply_session`.
	 *
	 * @throws Send_Exception When the receiver does not apply; the session stays open.
	 */
	public function apply( string $session, string $plan_hash, ?bool $overwrite = null, bool $flush_cache = false ): array {
		$state = $this->state( $session );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Waits for the receiver to place media and commit.
		}

		$response = $this->client( (string) $state['connection'] )->apply( $session, $plan_hash, $overwrite, $flush_cache );
		if ( ! $response['ok'] ) {
			$this->log->update( $session, Log_Table::OUT, array( 'error' => $response['message'] ) );
		}
		$data = self::expect( $response, esc_html__( 'Relay not applied', 'content-relay' ) );

		$this->log->update(
			$session,
			Log_Table::OUT,
			array(
				'status'        => Log_Table::STATUS_APPLIED,
				'apply_session' => (string) ( $data['apply_session'] ?? '' ),
				'result'        => $this->with_verification_plan( $session, $state, $data['result'] ?? null ),
				'error'         => null,
			)
		);
		$this->remove( $session );

		return $data;
	}

	/**
	 * Aborts an unapplied session on the receiver and forgets it here.
	 *
	 * @param string $session Session id.
	 *
	 * @throws Send_Exception When the session was applied or the receiver refuses.
	 */
	public function abort( string $session ): void {
		$row   = $this->log->find_session( $session, Log_Table::OUT );
		$label = (string) ( $row['connection'] ?? '' );
		if ( '' === $label ) {
			$label = (string) $this->state( $session )['connection'];
		}
		if ( in_array( $row['status'] ?? '', array( Log_Table::STATUS_APPLIED, Log_Table::STATUS_ROLLED_BACK ), true ) ) {
			throw new Send_Exception( esc_html__( 'This relay was already applied; roll it back instead.', 'content-relay' ), 'session_applied' );
		}

		$response = $this->client( $label )->abort( $session );
		if ( ! $response['ok'] && 'unknown_session' !== $response['code'] ) {
			self::expect( $response, esc_html__( 'The receiver did not abort the session', 'content-relay' ) );
		}

		$this->log->update( $session, Log_Table::OUT, array( 'status' => Log_Table::STATUS_ABORTED ) );
		$this->remove( $session );
	}

	/**
	 * Rolls back an applied session on the receiver.
	 *
	 * @param string $session   Session id.
	 * @param bool   $overwrite Roll back even if the relayed objects changed since.
	 * @param string $label     Connection label, needed only for sessions this site has no log row for.
	 * @return array<string, mixed> Receiver session data with `rollback`.
	 *
	 * @throws Send_Exception When the receiver refuses.
	 */
	public function rollback( string $session, bool $overwrite, string $label = '' ): array {
		$row   = $this->log->find_session( $session, Log_Table::OUT );
		$label = '' !== $label ? $label : (string) ( $row['connection'] ?? '' );
		if ( '' === $label ) {
			throw new Send_Exception( esc_html__( 'This site has no record of that relay session; name the connection to roll it back.', 'content-relay' ), 'unknown_session', 404 );
		}

		try {
			$data = self::expect( $this->client( $label )->rollback( $session, $overwrite ), esc_html__( 'Rollback refused', 'content-relay' ) );
		} catch ( Send_Exception $e ) {
			if ( 'not_applied' === $e->error_code() && null !== $row ) {
				// The receiver rolled it back itself; bring this site's log up to date before reporting.
				$this->refresh_status( $session, true );
			}
			throw $e;
		}

		if ( null !== $row ) {
			$this->log->update(
				$session,
				Log_Table::OUT,
				array(
					'status' => Log_Table::STATUS_ROLLED_BACK,
					'result' => array_merge( (array) ( $row['result'] ?? array() ), array( 'rollback' => $data['rollback'] ?? null ) ),
					'error'  => null,
				)
			);
		}

		return $data;
	}

	/**
	 * Checks an applied relay's pages over HTTP on the receiver (spec §12) and stores the report in the log.
	 *
	 * @param string $session Relay session id.
	 * @return array{checked_at: string, ok: bool, pages: array<int, array<string, mixed>>, problems: string[]}
	 *
	 * @throws Send_Exception When this site has no applied relay with that id.
	 */
	public function verify( string $session ): array {
		$row = $this->log->find_session( $session, Log_Table::OUT );
		if ( null === $row || ! is_array( $row['result']['verify'] ?? null ) ) {
			throw new Send_Exception( esc_html__( 'This site has no applied relay with that session id to check.', 'content-relay' ), 'unknown_session', 404 );
		}
		if ( Log_Table::STATUS_APPLIED !== $row['status'] ) {
			throw new Send_Exception(
				sprintf(
					/* translators: %s: relay status, e.g. rolled-back. */
					esc_html__( 'Only an applied relay can be checked; this one is %s.', 'content-relay' ),
					esc_html( (string) $row['status'] )
				),
				'not_applied'
			);
		}

		$plan   = $row['result']['verify'];
		$report = ( new Verifier() )->verify( (array) ( $plan['targets'] ?? array() ), (string) ( $plan['sender_home'] ?? '' ), (string) ( $plan['uploads_url'] ?? '' ) );

		$this->log->update( $session, Log_Table::OUT, array( 'result' => array_merge( (array) $row['result'], array( 'verification' => $report ) ) ) );

		return $report;
	}

	/**
	 * Confirms an applied relay's status with its receiver, which may have rolled it back since: keys let the sender
	 * ask, never the receiver tell. A rollback found there is written to this site's log.
	 *
	 * @param string $session Relay session id.
	 * @param bool   $force   Ask even when the status was confirmed recently.
	 * @return string This site's status when it is not `applied`; otherwise `applied`, `rolled-back`, `unknown` (the
	 *                receiver has no such session for this key, or the connection is gone) or `unreachable`.
	 */
	public function refresh_status( string $session, bool $force = false ): string {
		$row = $this->log->find_session( $session, Log_Table::OUT );
		if ( null === $row ) {
			return 'unknown';
		}
		if ( Log_Table::STATUS_APPLIED !== $row['status'] ) {
			return (string) $row['status'];
		}

		$confirmed = 'content_relay_status_' . md5( $session );
		if ( ! $force && false !== get_transient( $confirmed ) ) {
			return Log_Table::STATUS_APPLIED;
		}

		try {
			$response = $this->client( (string) $row['connection'] )->session( $session, null, 10 );
		} catch ( Send_Exception $e ) {
			return 'unknown';
		}
		if ( ! $response['ok'] ) {
			return 0 === $response['status'] ? 'unreachable' : 'unknown';
		}

		if ( Log_Table::STATUS_ROLLED_BACK === ( $response['data']['status'] ?? '' ) ) {
			$this->log->update(
				$session,
				Log_Table::OUT,
				array(
					'status' => Log_Table::STATUS_ROLLED_BACK,
					'result' => array_merge(
						(array) ( $row['result'] ?? array() ),
						array(
							'rollback'                => $response['data']['rollback'] ?? null,
							'rolled_back_on_receiver' => true,
						)
					),
				)
			);
			delete_transient( $confirmed );

			return Log_Table::STATUS_ROLLED_BACK;
		}

		set_transient( $confirmed, 1, self::STATUS_TTL );

		return Log_Table::STATUS_APPLIED;
	}

	/**
	 * Local state of an open session.
	 *
	 * @param string $session Session id.
	 * @return array<string, mixed>
	 *
	 * @throws Send_Exception When this site has no open session with that id.
	 */
	public function state( string $session ): array {
		$state = Uuid::is_valid( $session ) ? $this->read( $session, 'state.json' ) : null;
		if ( null === $state ) {
			throw new Send_Exception(
				sprintf(
					/* translators: %s: relay session id. */
					esc_html__( 'This site has no open relay session %s.', 'content-relay' ),
					esc_html( $session )
				),
				'unknown_session',
				404
			);
		}

		return $state;
	}

	/**
	 * Adds upload progress to a state.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	public static function with_progress( array $state ): array {
		$progress = array(
			'files_total'    => 0,
			'files_complete' => 0,
			'bytes_total'    => 0,
			'bytes_received' => 0,
			'done'           => true,
		);
		foreach ( (array) $state['files'] as $file ) {
			++$progress['files_total'];
			$progress['bytes_total']    += (int) $file['bytes'];
			$progress['bytes_received'] += (int) $file['received'];
			if ( ! empty( $file['complete'] ) ) {
				++$progress['files_complete'];
			} else {
				$progress['done'] = false;
			}
		}
		$state['progress'] = $progress;

		return $state;
	}

	/**
	 * Returns a response's data, or throws its error.
	 *
	 * @param array{status: int, ok: bool, code: string, message: string, data: array<string, mixed>} $response Response.
	 * @param string                                                                                  $context  What was attempted (escaped).
	 * @return array<string, mixed>
	 *
	 * @throws Send_Exception When the response is not ok.
	 */
	private static function expect( array $response, string $context ): array {
		if ( $response['ok'] ) {
			return $response['data'];
		}

		$status = (int) $response['status'];
		throw new Send_Exception(
			sprintf( '%1$s (HTTP %2$d, %3$s): %4$s', esc_html( $context ), (int) $status, esc_html( $response['code'] ), esc_html( $response['message'] ) ),
			'' === $response['code'] ? 'remote_error' : esc_html( $response['code'] ),
			0 === $status || $status >= 500 ? 502 : 409
		);
	}

	/**
	 * A stored connection with its key.
	 *
	 * @param string $label Label.
	 * @return array{label: string, url: string, kid: string, secret_key: string}
	 *
	 * @throws Send_Exception When it does not exist or cannot be decrypted.
	 */
	private function connection( string $label ): array {
		try {
			return $this->connections->get( $label );
		} catch ( Connection_Exception $e ) {
			throw new Send_Exception( esc_html( html_entity_decode( $e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), 'unknown_connection', 404, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped; the previous exception is chained, not output.
		}
	}

	/**
	 * Client for a stored connection.
	 *
	 * @param string $label Label.
	 *
	 * @throws Send_Exception When the connection cannot be used.
	 */
	private function client( string $label ): Client {
		return new Client( $this->connection( $label ) );
	}

	/**
	 * Manifest of an open session.
	 *
	 * @param string $session Session id.
	 * @return array<string, mixed>
	 *
	 * @throws Send_Exception When it is missing.
	 */
	private function manifest( string $session ): array {
		$manifest = $this->read( $session, 'manifest.json' );
		if ( null === $manifest ) {
			throw new Send_Exception( esc_html__( 'The manifest of this relay session is missing on this site.', 'content-relay' ), 'unknown_session', 404 );
		}

		return $manifest;
	}

	/**
	 * An apply result with what verify() needs later: the relayed pages, the sender and the receiver's uploads URL.
	 *
	 * @param string               $session Session id.
	 * @param array<string, mixed> $state   Session state.
	 * @param mixed                $result  Receiver apply result.
	 * @return array<string, mixed>|null
	 */
	private function with_verification_plan( string $session, array $state, $result ): ?array {
		if ( ! is_array( $result ) ) {
			return null;
		}

		$manifest = $this->read( $session, 'manifest.json' ) ?? array();
		$objects  = (array) ( $manifest['objects'] ?? array() );
		$targets  = array();
		foreach ( (array) ( $result['written'] ?? array() ) as $row ) {
			$key    = (string) ( $row['key'] ?? '' );
			$record = $objects[ $key ] ?? null;
			if ( ! is_array( $record ) || 'post' !== ( $record['kind'] ?? '' ) ) {
				continue;
			}
			$targets[] = array(
				'key'          => $key,
				'url'          => (string) ( $row['url'] ?? '' ),
				'language'     => $record['language'] ?? null,
				'status'       => (string) ( $record['fields']['post_status'] ?? '' ),
				'translations' => array_values( (array) ( $record['translations'] ?? array() ) ),
			);
		}

		$uploads_url      = (string) ( $state['uploads_url'] ?? '' );
		$result['verify'] = array(
			'sender_home' => (string) ( $manifest['source']['home_url'] ?? home_url() ),
			'uploads_url' => '' !== $uploads_url ? $uploads_url : untrailingslashit( (string) $state['receiver'] ) . '/wp-content/uploads',
			'targets'     => $targets,
		);

		return $result;
	}

	/**
	 * Upload progress per file, from the receiver's list of files it needs.
	 *
	 * @param array<string, mixed>             $manifest Manifest.
	 * @param array<int, array<string, mixed>> $remote   Receiver's files: sha1, bytes, received, complete.
	 * @return array<int, array{sha1: string, bytes: int, received: int, complete: bool, source: string, name: string}>
	 */
	private static function files_state( array $manifest, array $remote ): array {
		$sources = array();
		foreach ( (array) $manifest['objects'] as $record ) {
			if ( isset( $record['file']['sha1'], $record['file']['source'] ) ) {
				$sources[ (string) $record['file']['sha1'] ] = (string) $record['file']['source'];
			}
		}

		$files = array();
		foreach ( $remote as $file ) {
			$sha1    = (string) ( $file['sha1'] ?? '' );
			$source  = $sources[ $sha1 ] ?? '';
			$files[] = array(
				'sha1'     => $sha1,
				'bytes'    => (int) ( $file['bytes'] ?? 0 ),
				'received' => (int) ( $file['received'] ?? 0 ),
				'complete' => ! empty( $file['complete'] ),
				'source'   => $source,
				'name'     => basename( $source ),
			);
		}

		return $files;
	}

	/**
	 * Writes a JSON file of a session, protecting the outgoing directory.
	 *
	 * @param string               $session Session id.
	 * @param string               $name    File name.
	 * @param array<string, mixed> $data    Data.
	 *
	 * @throws Send_Exception When the file cannot be written.
	 */
	private function write( string $session, string $name, array $data ): void {
		$dir = $this->root . '/' . $session;
		if ( ! Uuid::is_valid( $session ) || ! wp_mkdir_p( $dir ) ) {
			throw new Send_Exception( esc_html__( 'Cannot create the outgoing relay directory in uploads.', 'content-relay' ), 'storage_error', 500 );
		}

		$parent = dirname( $this->root );
		$guards = array(
			$parent . '/index.php'     => "<?php\n// Silence is golden.\n",
			$parent . '/.htaccess'     => "Require all denied\n",
			$this->root . '/index.php' => "<?php\n// Silence is golden.\n",
		);
		foreach ( $guards as $guard => $contents ) {
			if ( ! file_exists( $guard ) ) {
				file_put_contents( $guard, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes inside uploads during a request; WP_Filesystem may require credentials.
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- See above.
		if ( false === file_put_contents( $dir . '/' . $name, (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), LOCK_EX ) ) {
			throw new Send_Exception( esc_html__( 'Cannot write the outgoing relay state in uploads.', 'content-relay' ), 'storage_error', 500 );
		}
	}

	/**
	 * Reads a JSON file of a session.
	 *
	 * @param string $session Session id.
	 * @param string $name    File name.
	 * @return array<string, mixed>|null
	 */
	private function read( string $session, string $name ): ?array {
		$path = $this->root . '/' . $session . '/' . $name;
		if ( ! is_readable( $path ) ) {
			return null;
		}

		$data = wp_json_file_decode( $path, array( 'associative' => true ) );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Removes a session's outgoing directory.
	 *
	 * @param string $session Session id.
	 */
	private function remove( string $session ): void {
		$dir = $this->root . '/' . $session;
		if ( ! Uuid::is_valid( $session ) || ! is_dir( $dir ) ) {
			return;
		}

		foreach ( array( 'manifest.json', 'state.json' ) as $name ) {
			if ( file_exists( $dir . '/' . $name ) ) {
				wp_delete_file( $dir . '/' . $name );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes the emptied session directory.
	}
}
