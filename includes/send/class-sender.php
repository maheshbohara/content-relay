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
}
