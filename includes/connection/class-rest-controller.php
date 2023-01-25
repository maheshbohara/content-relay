<?php
/**
 * The receiving REST API.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Connection;

use ContentRelay\Apply\Applier;
use ContentRelay\Apply\Apply_Exception;
use ContentRelay\Apply\Rollback;
use ContentRelay\Config;
use ContentRelay\Language\Adapter_Factory;
use ContentRelay\Receive\Bundle;
use ContentRelay\Receive\Planner;
use ContentRelay\Receive\Receive_Context;
use ContentRelay\Receive\Differ;
use ContentRelay\Receive\Receive_Exception;
use ContentRelay\Storage\Log_Table;
use ContentRelay\Storage\Staging;
use ContentRelay\Storage\Staging_Exception;
use ContentRelay\Support\Ip_Matcher;

defined( 'ABSPATH' ) || exit;

/**
 * Routes of `content-relay/v1` (spec §13). Registered only when receiving is on, so otherwise they do not exist.
 *
 * Every route is signed (spec §5.3); the permission callback verifies the request and then acts as the administrator
 * who issued the key. Sessions are bound to the key that opened them. Responses are always
 * `{ ok, code, message, data }` and never carry paths, SQL or stack traces.
 */
final class Rest_Controller {

	/**
	 * Failed requests per IP per minute before further requests are refused.
	 */
	private const MAX_FAILURES_PER_MINUTE = 10;

	/**
	 * Largest chunk accepted by default (2 MiB).
	 */
	private const DEFAULT_MAX_CHUNK_BYTES = 2097152;

	/**
	 * Verified caller of the current request.
	 *
	 * @var array{kid: string, sender_user: string, user_id: int}|null
	 */
	private ?array $caller = null;

	/**
	 * Request whose verdict is cached in $verdict.
	 *
	 * @var \WP_REST_Request|null
	 */
	private ?\WP_REST_Request $checked = null;

	/**
	 * Verdict for $checked.
	 *
	 * @var true|\WP_Error
	 */
	private $verdict = true;

	/**
	 * Receiver keys.
	 *
	 * @var Receiver_Keys
	 */
	private Receiver_Keys $keys;

	/**
	 * Staging area.
	 *
	 * @var Staging
	 */
	private Staging $staging;

	/**
	 * Relay log.
	 *
	 * @var Log_Table
	 */
	private Log_Table $log;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->keys    = new Receiver_Keys();
		$this->staging = Staging::for_site();
		$this->log     = new Log_Table();
	}

	/**
	 * Hooks the controller into the REST API.
	 */
	public static function register(): void {
		$controller = new self();
		add_action( 'rest_api_init', array( $controller, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( $controller, 'shape_response' ), 10, 3 );
	}

	/**
	 * Largest chunk this site accepts.
	 */
	public static function max_chunk_bytes(): int {
		/**
		 * Filters the largest file chunk the receiver accepts, in bytes.
		 *
		 * @param int $bytes Bytes.
		 */
		return max( 65536, (int) apply_filters( 'content_relay_max_chunk_bytes', self::DEFAULT_MAX_CHUNK_BYTES ) );
	}

	/**
	 * Registers the routes.
	 */
	public function routes(): void {
		$session = '/sessions/(?P<id>[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})';
		$routes  = array(
			array( '/handshake', 'POST', 'handshake' ),
			array( '/sessions', 'POST', 'create_session' ),
			array( $session . '/files/(?P<sha1>[a-f0-9]{40})', 'PUT', 'upload' ),
			array( $session, 'GET', 'get_session' ),
			array( $session, 'DELETE', 'delete_session' ),
			array( $session . '/apply', 'POST', 'apply' ),
			array( $session . '/rollback', 'POST', 'rollback' ),
		);

		foreach ( $routes as $route ) {
			register_rest_route(
				Client::REST_NAMESPACE,
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( $this, $route[2] ),
					'permission_callback' => array( $this, 'authorize' ),
				)
			);
		}
	}

	/**
	 * Verifies the signature and switches to the key's issuing administrator.
	 *
	 * Parameters are validated inside the callbacks, not as route args: WordPress validates args before the permission
	 * callback, which would describe the API to unauthenticated callers.
	 *
	 * The verdict is cached per request object. After dispatch, core's rest_send_allow_header() calls the permission
	 * callback of every handler on the route again with the same request; verifying twice would reject the second pass
	 * as a nonce replay and count a failure against the sender's IP for every legitimate request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function authorize( \WP_REST_Request $request ) {
		if ( $request === $this->checked ) {
			return $this->verdict;
		}

		$this->caller  = null;
		$this->verdict = $this->verify_request( $request );
		$this->checked = $request;

		return $this->verdict;
	}

	/**
	 * Verifies one request (see authorize()).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	private function verify_request( \WP_REST_Request $request ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		$allowlist = Config::ip_allowlist();
		if ( null !== $allowlist && ! Ip_Matcher::matches( $ip, $allowlist ) ) {
			return $this->deny( $ip, 'ip', '' );
		}

		$failures = 'content_relay_fail_' . md5( $ip );
		if ( (int) get_transient( $failures ) >= self::MAX_FAILURES_PER_MINUTE ) {
			return new \WP_Error( 'content_relay_rate_limited', 'Too many failed requests. Try again later.', array( 'status' => 429 ) );
		}

		$headers = array();
		foreach ( array( Request_Verifier::HEADER_KID, Request_Verifier::HEADER_TIMESTAMP, Request_Verifier::HEADER_NONCE, Request_Verifier::HEADER_SIGNATURE ) as $name ) {
			$headers[ $name ] = trim( (string) $request->get_header( $name ) );
		}

		$query = $request->get_query_params();
		unset( $query['rest_route'] );

		$verifier = new Request_Verifier( new Signer(), $this->keys, new Transient_Replay_Guard() );
		$result   = $verifier->verify( $request->get_method(), $request->get_route(), $query, $headers, (string) $request->get_body(), time() );
		if ( ! $result['ok'] ) {
			return $this->deny( $ip, $result['reason'], $result['kid'] );
		}

		$key  = (array) $this->keys->find( $result['kid'] );
		$user = get_userdata( (int) ( $key['created_by'] ?? 0 ) );
		if ( ! $user instanceof \WP_User || ! user_can( $user, 'manage_options' ) ) {
			return $this->deny( $ip, 'issuer', $result['kid'] );
		}

		wp_set_current_user( $user->ID );
		$this->keys->touch( $result['kid'], time() );
		$this->caller = array(
			'kid'         => $result['kid'],
			'sender_user' => sanitize_user( (string) $request->get_header( Request_Verifier::HEADER_USER ) ),
			'user_id'     => $user->ID,
		);

		return true;
	}

	/**
	 * `POST /handshake` — environment report (§5.4).
	 *
	 * @return \WP_REST_Response
	 */
	public function handshake(): \WP_REST_Response {
		return self::ok( 'ready', 'Ready to receive.', self::environment() );
	}

	/**
	 * `POST /sessions` — stages a manifest and returns the preliminary plan and the files needed.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_session( \WP_REST_Request $request ): \WP_REST_Response {
		$manifest = $request->get_param( 'manifest' );
		if ( ! is_array( $manifest ) ) {
			return self::error( 'invalid_request', 'The request carries no manifest.', 400 );
		}

		try {
			Bundle::validate( $manifest );
		} catch ( Receive_Exception $e ) {
			return self::error( 'invalid_manifest', $this->public_message( $e->getMessage() ), 400 );
		}

		$files = array();
		foreach ( $manifest['objects'] as $record ) {
			if ( 'attachment' === $record['kind'] && 'owned' === $record['role'] && isset( $record['file']['sha1'] ) ) {
				$files[ (string) $record['file']['sha1'] ] = (int) ( $record['file']['bytes'] ?? 0 );
			}
		}

		$this->staging->purge( time(), Staging::TTL_SECONDS );

		$id     = wp_generate_uuid4();
		$record = array(
			'id'          => $id,
			'kid'         => $this->caller['kid'] ?? '',
			'sender_user' => $this->caller['sender_user'] ?? '',
			'user_id'     => $this->caller['user_id'] ?? 0,
			'source'      => (string) $manifest['source']['home_url'],
			'root'        => (string) $manifest['root'],
			'overwrite'   => true === $request->get_param( 'overwrite' ),
			'status'      => Staging::STATUS_PLANNED,
			'files'       => $files,
			'created_at'  => gmdate( 'c' ),
		);

		try {
			$this->staging->create( $id, $record, $manifest );
		} catch ( Staging_Exception $e ) {
			return self::error( 'storage_error', $e->getMessage(), 500 );
		}

		$root = (array) ( $manifest['objects'][ $manifest['root'] ] ?? array() );
		$this->log->insert(
			array(
				'session'    => $id,
				'direction'  => Log_Table::IN,
				'peer_url'   => $record['source'],
				'kid'        => $record['kid'],
				'root_uuid'  => (string) ( $root['uuid'] ?? '' ),
				'root_title' => (string) ( $root['title'] ?? '' ),
				'languages'  => implode( ',', self::languages( $manifest ) ),
				'user_login' => sprintf( '%1$s (as %2$s)', '' !== $record['sender_user'] ? $record['sender_user'] : '?', wp_get_current_user()->user_login ),
				'status'     => Log_Table::STATUS_PLANNED,
			)
		);

		return self::ok( 'session_created', 'Session created. Upload the files needed, then fetch the session for the final plan.', $this->session_data( $record, true ), 201 );
	}

	/**
	 * `PUT /sessions/{id}/files/{sha1}?offset=` — one chunk of a file.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function upload( \WP_REST_Request $request ): \WP_REST_Response {
		$record = $this->owned_session( $request );
		if ( $record instanceof \WP_REST_Response ) {
			return $record;
		}
		if ( Staging::STATUS_PLANNED !== $record['status'] ) {
			return self::error( 'session_closed', 'This session no longer accepts files.', 409 );
		}

		$sha1 = (string) $request['sha1'];
		if ( ! isset( $record['files'][ $sha1 ] ) ) {
			return self::error( 'unknown_file', 'That file is not part of this session.', 404 );
		}

		$offset = (string) $request->get_param( 'offset' );
		if ( 1 !== preg_match( '/^\d{1,15}$/', $offset ) ) {
			return self::error( 'invalid_offset', 'The offset must be a non-negative integer.', 400 );
		}

		$bytes = (string) $request->get_body();
		if ( strlen( $bytes ) > self::max_chunk_bytes() ) {
			return self::error( 'chunk_too_large', sprintf( 'Chunks may be at most %d bytes.', self::max_chunk_bytes() ), 413 );
		}

		$expected = (int) $record['files'][ $sha1 ];
		try {
			$result = $this->staging->write_chunk( (string) $record['id'], $sha1, (int) $offset, $bytes, $expected );
		} catch ( Staging_Exception $e ) {
			return self::error( 'storage_error', $e->getMessage(), 500 );
		}

		$data = array(
			'sha1'     => $sha1,
			'bytes'    => $expected,
			'received' => $result['received'],
			'complete' => 'complete' === $result['status'],
		);

		switch ( $result['status'] ) {
			case 'offset':
				return self::error( 'offset_mismatch', 'The offset does not match the bytes received; continue from data.received.', 409, $data );
			case 'too-large':
				return self::error( 'file_too_large', 'The chunk would make the file larger than the manifest says.', 413, $data );
			case 'mismatch':
				return self::error( 'hash_mismatch', 'The uploaded file does not match its SHA-1 and was discarded; upload it again from offset 0.', 422, $data );
			default:
				return self::ok( $data['complete'] ? 'file_complete' : 'chunk_stored', $data['complete'] ? 'File received and verified.' : 'Chunk stored.', $data );
		}
	}

	/**
	 * `GET /sessions/{id}` — session state; an open session is re-planned against the files received so far.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_session( \WP_REST_Request $request ): \WP_REST_Response {
		$record = $this->owned_session( $request );
		if ( $record instanceof \WP_REST_Response ) {
			return $record;
		}

		$overwrite = $request->get_param( 'overwrite' );
		$overwrite = null === $overwrite ? null : in_array( (string) $overwrite, array( '1', 'true' ), true );

		return self::ok( 'session', 'Session state.', $this->session_data( $record, Staging::STATUS_PLANNED === $record['status'], $overwrite ) );
	}

	/**
	 * `DELETE /sessions/{id}` — aborts an unapplied session and removes its staging files.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_session( \WP_REST_Request $request ): \WP_REST_Response {
		$record = $this->owned_session( $request );
		if ( $record instanceof \WP_REST_Response ) {
			return $record;
		}
		if ( Staging::STATUS_PLANNED !== $record['status'] ) {
			return self::error( 'session_not_open', 'Only an unapplied session can be aborted; an applied one is kept so it can be rolled back.', 409 );
		}

		$this->staging->delete( (string) $record['id'] );
		$this->log->update( (string) $record['id'], Log_Table::IN, array( 'status' => Log_Table::STATUS_ABORTED ) );

		return self::ok( 'aborted', 'Session aborted and its staging files removed.', array( 'session' => $record['id'] ) );
	}

	/**
	 * `POST /sessions/{id}/apply {plan_hash, overwrite, flush_cache}` — applies the approved plan; `flush_cache` also
	 * flushes the whole object cache afterwards (spec §8.5 step D).
	 *
	 * Runs to completion in one request and answers `status: done`; the Applier's steps are not resumable, so there is
	 * nothing to poll for.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function apply( \WP_REST_Request $request ): \WP_REST_Response {
		$record = $this->owned_session( $request );
		if ( $record instanceof \WP_REST_Response ) {
			return $record;
		}
		if ( Staging::STATUS_PLANNED !== $record['status'] ) {
			return self::error( 'session_not_open', sprintf( 'This session is %s.', $record['status'] ), 409 );
		}

		$hash = $request->get_param( 'plan_hash' );
		if ( ! is_string( $hash ) || '' === $hash ) {
			return self::error( 'invalid_request', 'plan_hash is required.', 400 );
		}

		$overwrite = $request->get_param( 'overwrite' );
		$overwrite = is_bool( $overwrite ) ? $overwrite : (bool) $record['overwrite'];

		$id     = (string) $record['id'];
		$bundle = $this->bundle( $id );
		if ( null === $bundle ) {
			return self::error( 'session_not_open', 'The session has no staged manifest.', 409 );
		}

		try {
			$this->staging->update( $id, array( 'status' => Staging::STATUS_APPLYING ) );
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A relay commit must not be cut off halfway through placing media.
			}
			$result = ( new Applier( $this->context() ) )->apply( $bundle, $overwrite, $hash );
		} catch ( Apply_Exception $e ) {
			$this->staging->update(
				$id,
				array(
					'status'     => Staging::STATUS_PLANNED,
					'last_error' => $this->public_message( $e->getMessage() ),
				)
			);
			$this->log->update( $id, Log_Table::IN, array( 'error' => $this->public_message( $e->getMessage() ) ) );
			return self::error( 'apply_failed', $this->public_message( $e->getMessage() ), 409 );
		} catch ( \Throwable $e ) {
			$this->staging->update(
				$id,
				array(
					'status'     => Staging::STATUS_PLANNED,
					'last_error' => 'Unexpected error.',
				)
			);
			return self::error( 'apply_error', 'The relay failed with an unexpected error; nothing was committed.', 500 );
		}

		$flush = true === $request->get_param( 'flush_cache' );
		if ( $flush ) {
			wp_cache_flush();
		}
		$result['object_cache_flushed'] = $flush;

		$record = $this->staging->update(
			$id,
			array(
				'status'        => Staging::STATUS_APPLIED,
				'apply_session' => $result['session'],
				'applied_at'    => gmdate( 'c' ),
				'result'        => $this->public_result( $result ),
				'last_error'    => null,
			)
		);
		$this->staging->remove_files( $id );

		$root_id = 0;
		foreach ( (array) $result['written'] as $written ) {
			if ( ( $written['key'] ?? '' ) === ( $record['root'] ?? '' ) ) {
				$root_id = (int) $written['id'];
			}
		}
		$this->log->update(
			$id,
			Log_Table::IN,
			array(
				'status'        => Log_Table::STATUS_APPLIED,
				'apply_session' => (string) $result['session'],
				'root_post_id'  => $root_id,
				'result'        => $record['result'],
				'error'         => null,
			)
		);

		return self::ok( 'done', array() === $result['problems'] ? 'Relay applied.' : 'Relay applied, but verification found problems.', $this->session_data( $record, false ) );
	}

	/**
	 * `POST /sessions/{id}/rollback` — undoes an applied session.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rollback( \WP_REST_Request $request ): \WP_REST_Response {
		$record = $this->owned_session( $request );
		if ( $record instanceof \WP_REST_Response ) {
			return $record;
		}
		if ( Staging::STATUS_APPLIED !== $record['status'] || ! is_string( $record['apply_session'] ?? null ) ) {
			return self::error( 'not_applied', sprintf( 'This session is %s; only an applied session can be rolled back.', $record['status'] ), 409 );
		}

		$overwrite = true === $request->get_param( 'overwrite' );

		try {
			$summary = ( new Rollback( $this->context() ) )->rollback( $record['apply_session'], $overwrite );
		} catch ( Apply_Exception $e ) {
			return self::error( 'rollback_failed', $this->public_message( $e->getMessage() ), 409 );
		}

		$record = $this->staging->update(
			(string) $record['id'],
			array(
				'status'         => Staging::STATUS_ROLLED_BACK,
				'rolled_back_at' => gmdate( 'c' ),
				'rollback'       => $summary,
			)
		);

		$row = $this->log->find_session( (string) $record['id'], Log_Table::IN );
		$this->log->update(
			(string) $record['id'],
			Log_Table::IN,
			array(
				'status' => Log_Table::STATUS_ROLLED_BACK,
				'result' => array_merge( (array) ( $row['result'] ?? array() ), array( 'rollback' => $summary ) ),
			)
		);

		return self::ok( 'rolled_back', 'Relay rolled back.', $this->session_data( $record, false ) );
	}

	/**
	 * Gives WordPress's own errors on these routes (bad JSON, rate limit, 401) the Content Relay response shape.
	 *
	 * @param mixed            $response Response.
	 * @param \WP_REST_Server  $server   Server.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function shape_response( $response, \WP_REST_Server $server, \WP_REST_Request $request ) {
		unset( $server );
		if ( ! $response instanceof \WP_REST_Response || 0 !== strpos( $request->get_route(), '/' . Client::REST_NAMESPACE . '/' ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( is_array( $data ) && array_key_exists( 'ok', $data ) ) {
			return $response;
		}

		if ( $response->is_error() ) {
			$response->set_data(
				array(
					'ok'      => false,
					'code'    => is_array( $data ) ? (string) ( $data['code'] ?? 'error' ) : 'error',
					'message' => is_array( $data ) ? (string) ( $data['message'] ?? '' ) : '',
					'data'    => array(),
				)
			);
		}

		return $response;
	}

	/**
	 * Environment report (§5.4).
	 *
	 * @return array<string, mixed>
	 */
	public static function environment(): array {
		global $wp_version;

		$theme    = wp_get_theme();
		$language = Adapter_Factory::create();

		return array(
			'plugin'       => CONTENT_RELAY_VERSION,
			'schema'       => CONTENT_RELAY_SCHEMA,
			'php'          => PHP_VERSION,
			'wp'           => (string) $wp_version,
			'home_url'     => untrailingslashit( (string) get_option( 'home' ) ),
			'uploads_url'  => untrailingslashit( (string) wp_get_upload_dir()['baseurl'] ),
			'theme'        => array(
				'stylesheet' => get_stylesheet(),
				'version'    => (string) $theme->get( 'Version' ),
			),
			'language'     => array(
				'adapter' => $language->id(),
				'default' => $language->default_language(),
				'active'  => $language->active_languages(),
			),
			'plugins'      => array(
				'acf'   => defined( 'ACF_VERSION' ) ? (string) constant( 'ACF_VERSION' ) : null,
				'wpml'  => defined( 'ICL_SITEPRESS_VERSION' ) ? (string) constant( 'ICL_SITEPRESS_VERSION' ) : null,
				'yoast' => defined( 'WPSEO_VERSION' ) ? (string) constant( 'WPSEO_VERSION' ) : null,
			),
			'limits'       => array(
				'max_chunk_bytes'    => self::max_chunk_bytes(),
				'max_execution_time' => (int) ini_get( 'max_execution_time' ),
			),
			'object_cache' => wp_using_ext_object_cache(),
			'server_time'  => time(),
		);
	}

	/**
	 * Language codes of a manifest's roots.
	 *
	 * @param array<string, mixed> $manifest Manifest.
	 * @return string[]
	 */
	private static function languages( array $manifest ): array {
		$languages = array();
		foreach ( (array) $manifest['roots'] as $key ) {
			$language = $manifest['objects'][ $key ]['language'] ?? null;
			if ( is_string( $language ) ) {
				$languages[] = $language;
			}
		}

		return $languages;
	}

	/**
	 * Loads a session that belongs to the calling key.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_REST_Response Record, or a 404 response (also for another key's session).
	 */
	private function owned_session( \WP_REST_Request $request ) {
		$record = $this->staging->read( (string) $request['id'] );
		if ( null === $record || ! hash_equals( (string) ( $record['kid'] ?? '' ), (string) ( $this->caller['kid'] ?? '-' ) ) ) {
			return self::error( 'unknown_session', 'There is no such session for this key.', 404 );
		}

		return $record;
	}

	/**
	 * Response data for a session.
	 *
	 * Plan items the relay would replace carry a `diff` (see Differ). The diff is added after the plan hash is taken, so
	 * it never affects approval.
	 *
	 * @param array<string, mixed> $record    Session record.
	 * @param bool                 $plan      Whether to plan the staged manifest now.
	 * @param bool|null            $overwrite Plan with or without overwrite; null uses the session's setting.
	 * @return array<string, mixed>
	 */
	private function session_data( array $record, bool $plan, ?bool $overwrite = null ): array {
		$overwrite = null === $overwrite ? (bool) $record['overwrite'] : $overwrite;
		$id        = (string) $record['id'];
		$data      = array(
			'session'       => $id,
			'status'        => $record['status'],
			'overwrite'     => $overwrite,
			'apply_session' => $record['apply_session'] ?? null,
			'last_error'    => $record['last_error'] ?? null,
			'result'        => $record['result'] ?? null,
			'rollback'      => $record['rollback'] ?? null,
		);

		$bundle = $plan ? $this->bundle( $id ) : null;
		if ( null === $bundle ) {
			return $data;
		}

		$context = $this->context();
		$planned = ( new Planner( $context ) )->plan( $bundle, $overwrite );
		unset( $planned['matches'] );

		$diffs = ( new Differ( $context ) )->diffs( $bundle, $planned );
		foreach ( $planned['items'] as $index => $item ) {
			if ( isset( $diffs[ $item['key'] ] ) ) {
				$planned['items'][ $index ]['diff'] = $diffs[ $item['key'] ];
			}
		}

		$files   = array();
		$pending = 0;
		foreach ( $planned['files_needed'] as $sha1 ) {
			$bytes    = (int) ( $record['files'][ $sha1 ] ?? 0 );
			$received = $this->staging->received( $id, $sha1 );
			$complete = null !== $bundle->file_path( $sha1 );
			$files[]  = array(
				'sha1'     => $sha1,
				'bytes'    => $bytes,
				'received' => $received,
				'complete' => $complete,
			);
			$pending += $complete ? 0 : 1;
		}

		$data['plan']          = $planned;
		$data['files']         = $files;
		$data['files_pending'] = $pending;

		return $data;
	}

	/**
	 * Bundle view of a staged session.
	 *
	 * @param string $id Session id.
	 */
	private function bundle( string $id ): ?Bundle {
		$manifest = $this->staging->manifest( $id );

		return null === $manifest ? null : new Bundle( $manifest, $this->staging->files_dir( $id ) );
	}

	/**
	 * Receiver context.
	 */
	private function context(): Receive_Context {
		return new Receive_Context( Adapter_Factory::create() );
	}

	/**
	 * Apply result as it may leave this site: no backup path, created files relative to uploads.
	 *
	 * @param array<string, mixed> $result Applier result.
	 * @return array<string, mixed>
	 */
	private function public_result( array $result ): array {
		unset( $result['backup'] );

		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( (string) $uploads['basedir'] );
		$files   = array();
		foreach ( (array) ( $result['created']['files'] ?? array() ) as $path ) {
			$files[] = 0 === strpos( (string) $path, $base ) ? substr( (string) $path, strlen( $base ) ) : basename( (string) $path );
		}
		$result['created']['files'] = $files;

		return $result;
	}

	/**
	 * Strips filesystem locations from an engine message.
	 *
	 * @param string $message Message.
	 */
	private function public_message( string $message ): string {
		$uploads = wp_get_upload_dir();

		return str_replace( array( untrailingslashit( (string) $uploads['basedir'] ), untrailingslashit( ABSPATH ) ), array( 'uploads', '' ), $message );
	}

	/**
	 * Refuses a request with a generic 401, counts the failure and reports it.
	 *
	 * @param string $ip     Client IP.
	 * @param string $reason Internal reason (never sent).
	 * @param string $kid    Claimed key id.
	 */
	private function deny( string $ip, string $reason, string $kid ): \WP_Error {
		$failures = 'content_relay_fail_' . md5( $ip );
		set_transient( $failures, (int) get_transient( $failures ) + 1, MINUTE_IN_SECONDS );

		/**
		 * Fires when a relay request is refused.
		 *
		 * @param string $reason `ip`, `headers`, `query`, `kid`, `skew`, `signature`, `replay` or `issuer`.
		 * @param string $ip     Client IP.
		 * @param string $kid    Claimed key id (unverified).
		 */
		do_action( 'content_relay_request_denied', $reason, $ip, $kid );

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Refused requests are logged for the site owner (spec §5.3) only when the debug log is on.
			error_log( sprintf( 'Content Relay: refused request from %1$s (%2$s, kid %3$s).', $ip, $reason, '' === $kid ? '-' : $kid ) );
		}

		return new \WP_Error( 'content_relay_unauthorized', 'The request could not be authenticated.', array( 'status' => 401 ) );
	}

	/**
	 * Success response.
	 *
	 * @param string               $code    Code.
	 * @param string               $message Message.
	 * @param array<string, mixed> $data    Data.
	 * @param int                  $status  HTTP status.
	 */
	private static function ok( string $code, string $message, array $data, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'code'    => $code,
				'message' => $message,
				'data'    => $data,
			),
			$status
		);
	}

	/**
	 * Error response.
	 *
	 * @param string               $code    Code.
	 * @param string               $message Message.
	 * @param int                  $status  HTTP status.
	 * @param array<string, mixed> $data    Data.
	 */
	private static function error( string $code, string $message, int $status, array $data = array() ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'ok'      => false,
				'code'    => $code,
				'message' => $message,
				'data'    => $data,
			),
			$status
		);
	}
}
