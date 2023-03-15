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
}
