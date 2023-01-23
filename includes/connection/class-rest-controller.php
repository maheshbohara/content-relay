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
}
