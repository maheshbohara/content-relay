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
}
