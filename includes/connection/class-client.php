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

	/**
	 * Opens a session: sends the manifest, receives the preliminary plan and the files the receiver needs.
	 *
	 * @param array<string, mixed> $manifest  Manifest.
	 * @param bool                 $overwrite Plan with overwrite.
	 * @return array{status: int, ok: bool, code: string, message: string, data: array<string, mixed>}
	 */
	public function create_session( array $manifest, bool $overwrite ): array {
		return $this->json(
			'POST',
			'/sessions',
			array(
				'manifest'  => $manifest,
				'overwrite' => $overwrite,
			),
			120
		);
	}

	/**
	 * Uploads one chunk of a file.
	 *
	 * @param string $session Session id.
	 * @param string $sha1    File SHA-1.
	 * @param int    $offset  Offset of the chunk.
	 * @param string $bytes   Chunk.
	 * @return array{status: int, ok: bool, code: string, message: string, data: array<string, mixed>}
	 */
	public function upload( string $session, string $sha1, int $offset, string $bytes ): array {
		return $this->request( 'PUT', '/sessions/' . $session . '/files/' . $sha1, array( 'offset' => (string) $offset ), $bytes, 'application/octet-stream', 120 );
	}

	/**
	 * Session state; re-plans while the session is still open.
	 *
	 * @param string    $session   Session id.
	 * @param bool|null $overwrite Plan with or without overwrite; null keeps the session's setting.
	 * @param int       $timeout   Seconds; an open session is re-planned, which can take a while.
	 * @return array{status: int, ok: bool, code: string, message: string, data: array<string, mixed>}
	 */
	public function session( string $session, ?bool $overwrite = null, int $timeout = 120 ): array {
		$query = null === $overwrite ? array() : array( 'overwrite' => $overwrite ? '1' : '0' );

		return $this->request( 'GET', '/sessions/' . $session, $query, '', 'application/json', $timeout );
	}
}
