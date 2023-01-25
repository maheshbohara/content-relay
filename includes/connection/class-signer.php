<?php
/**
 * Ed25519 request signing.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Connection;

use ContentRelay\Support\Base64_Url;

defined( 'ABSPATH' ) || exit;

/**
 * Signs and verifies relay requests.
 *
 * The receiver stores only public keys, so its database never holds anything that can sign a request. Sodium is
 * always available: PHP ships ext-sodium from 7.2 and WordPress bundles sodium_compat.
 */
final class Signer {

	/**
	 * Ed25519 public key length.
	 */
	private const PUBLIC_KEY_BYTES = 32;

	/**
	 * Ed25519 signature length.
	 */
	private const SIGNATURE_BYTES = 64;

	/**
	 * Generates a new key pair.
	 *
	 * @return array{public_key: string, secret_key: string} Binary keys.
	 */
	public static function generate_keypair(): array {
		$pair = sodium_crypto_sign_keypair();

		return array(
			'public_key' => sodium_crypto_sign_publickey( $pair ),
			'secret_key' => sodium_crypto_sign_secretkey( $pair ),
		);
	}

	/**
	 * Builds the canonical string that is signed.
	 *
	 * @param string                          $method    HTTP method.
	 * @param string                          $path      Request path, e.g. `/wp-json/content-relay/v1/sessions`.
	 * @param array<string, string|int|float> $query     Query parameters.
	 * @param int                             $timestamp Unix time.
	 * @param string                          $nonce     Request nonce.
	 * @param string                          $body      Raw request body.
	 * @return string
	 */
	public function canonical( string $method, string $path, array $query, int $timestamp, string $nonce, string $body ): string {
		ksort( $query, SORT_STRING );
		$pairs = array();
		foreach ( $query as $name => $value ) {
			$pairs[] = rawurlencode( (string) $name ) . '=' . rawurlencode( (string) $value );
		}

		return implode(
			"\n",
			array(
				strtoupper( $method ),
				'/' . ltrim( $path, '/' ),
				implode( '&', $pairs ),
				(string) $timestamp,
				$nonce,
				hash( 'sha256', $body ),
			)
		);
	}

	/**
	 * Signs a canonical string.
	 *
	 * @param string $canonical  Result of canonical().
	 * @param string $secret_key Binary Ed25519 secret key.
	 * @return string base64url signature.
	 */
	public function sign( string $canonical, string $secret_key ): string {
		return Base64_Url::encode( sodium_crypto_sign_detached( $canonical, $secret_key ) );
	}

	/**
	 * Verifies a signature.
	 *
	 * @param string $canonical  Result of canonical().
	 * @param string $signature  base64url signature.
	 * @param string $public_key Binary Ed25519 public key.
	 */
	public function verify( string $canonical, string $signature, string $public_key ): bool {
		$raw = Base64_Url::decode( $signature );
		if ( null === $raw || self::SIGNATURE_BYTES !== strlen( $raw ) || self::PUBLIC_KEY_BYTES !== strlen( $public_key ) ) {
			return false;
		}

		try {
			return sodium_crypto_sign_verify_detached( $raw, $canonical, $public_key );
		} catch ( \SodiumException $e ) {
			return false;
		}
	}
}
