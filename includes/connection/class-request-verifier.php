<?php
/**
 * Verifies a signed relay request.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Checks headers, key, clock skew, signature and nonce replay (spec §5.3). Knows nothing about WordPress requests, so
 * it can be tested without one; the REST controller feeds it and turns a failure into a generic 401.
 */
final class Request_Verifier {

	/**
	 * Maximum difference between the sender's timestamp and this site's clock.
	 */
	public const MAX_SKEW_SECONDS = 300;

	/**
	 * How long a nonce is remembered. Longer than twice the skew window, so a replay cannot outlive it.
	 */
	public const NONCE_SECONDS = 600;

	/**
	 * Request headers, lowercase.
	 */
	public const HEADER_KID       = 'x-content-relay-kid';
	public const HEADER_TIMESTAMP = 'x-content-relay-timestamp';
	public const HEADER_NONCE     = 'x-content-relay-nonce';
	public const HEADER_USER      = 'x-content-relay-user';
	public const HEADER_SIGNATURE = 'x-content-relay-signature';

	/**
	 * Signer.
	 *
	 * @var Signer
	 */
	private Signer $signer;
}
