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

	/**
	 * Binary Ed25519 secret key.
	 *
	 * @var string
	 */
	private string $secret_key;

	/**
	 * Signer.
	 *
	 * @var Signer
	 */
	private Signer $signer;

	/**
	 * Whether the receiver needs `?rest_route=` because it has no pretty permalinks.
	 *
	 * @var bool
	 */
	private bool $query_routes = false;

	/**
	 * Constructor.
	 *
	 * @param array{url: string, kid: string, secret_key: string} $connection Decrypted connection.
	 */
	public function __construct( array $connection ) {
		$this->url        = rtrim( $connection['url'], '/' );
		$this->kid        = $connection['kid'];
		$this->secret_key = $connection['secret_key'];
		$this->signer     = new Signer();
	}

	/**
	 * Receiver environment (§5.4).
	 *
	 * @return array{status: int, ok: bool, code: string, message: string, data: array<string, mixed>}
	 */
	public function handshake(): array {
		return $this->json( 'POST', '/handshake', array() );
	}
}
