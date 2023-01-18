<?php
/**
 * Signed HTTP client for a receiver's REST API.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Connection;

use ContentRelay\Support\Base64_Url;

defined( 'ABSPATH' ) || exit;

/**
 * Sends signed requests to `content-relay/v1` on a receiver (spec §5.3, §13).
 *
 * The signature covers the REST route (`/content-relay/v1/…`), not the URL path, so it holds whether the receiver serves
 * the API under `/wp-json/` or `?rest_route=`, in a subdirectory, or behind a path-rewriting proxy.
 */
final class Client {

	/**
	 * REST namespace.
	 */
	public const REST_NAMESPACE = 'content-relay/v1';

	/**
	 * Receiver home URL without trailing slash.
	 *
	 * @var string
	 */
	private string $url;

	/**
	 * Key id.
	 *
	 * @var string
	 */
	private string $kid;
}
