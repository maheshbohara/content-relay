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

	/**
	 * Public keys.
	 *
	 * @var Key_Store
	 */
	private Key_Store $keys;

	/**
	 * Nonce store.
	 *
	 * @var Replay_Guard
	 */
	private Replay_Guard $replay;

	/**
	 * Constructor.
	 *
	 * @param Signer       $signer Signer.
	 * @param Key_Store    $keys   Public keys.
	 * @param Replay_Guard $replay Nonce store.
	 */
	public function __construct( Signer $signer, Key_Store $keys, Replay_Guard $replay ) {
		$this->signer = $signer;
		$this->keys   = $keys;
		$this->replay = $replay;
	}

	/**
	 * Verifies a request.
	 *
	 * The nonce is recorded only after the signature verifies, so unsigned traffic cannot fill the store or burn a
	 * legitimate sender's nonces.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $route   REST route, e.g. `/content-relay/v1/sessions`.
	 * @param array<string, mixed>  $query   Query parameters (without `rest_route`).
	 * @param array<string, string> $headers Header name (lowercase) => value.
	 * @param string                $body    Raw body.
	 * @param int                   $now     This site's Unix time.
	 * @return array{ok: bool, reason: string, kid: string} `reason` is for logs only, never for the response.
	 */
	public function verify( string $method, string $route, array $query, array $headers, string $body, int $now ): array {
		$kid       = (string) ( $headers[ self::HEADER_KID ] ?? '' );
		$timestamp = (string) ( $headers[ self::HEADER_TIMESTAMP ] ?? '' );
		$nonce     = (string) ( $headers[ self::HEADER_NONCE ] ?? '' );
		$signature = (string) ( $headers[ self::HEADER_SIGNATURE ] ?? '' );

		if ( ! Key_Codec::is_valid_kid( $kid ) || 1 !== preg_match( '/^\d{1,12}$/', $timestamp ) || ! self::is_valid_nonce( $nonce ) || '' === $signature ) {
			return self::result( false, 'headers', $kid );
		}

		foreach ( $query as $value ) {
			if ( ! is_scalar( $value ) ) {
				return self::result( false, 'query', $kid );
			}
		}

		$public_key = $this->keys->public_key_for( $kid );
		if ( null === $public_key ) {
			return self::result( false, 'kid', $kid );
		}

		if ( abs( $now - (int) $timestamp ) > self::MAX_SKEW_SECONDS ) {
			return self::result( false, 'skew', $kid );
		}

		/**
		 * Scalar query values, checked above.
		 *
		 * @var array<string, string|int|float> $query
		 */
		$canonical = $this->signer->canonical( $method, $route, $query, (int) $timestamp, $nonce, $body );
		if ( ! $this->signer->verify( $canonical, $signature, $public_key ) ) {
			return self::result( false, 'signature', $kid );
		}

		if ( ! $this->replay->remember( $kid, $nonce, self::NONCE_SECONDS ) ) {
			return self::result( false, 'replay', $kid );
		}

		return self::result( true, '', $kid );
	}
}
