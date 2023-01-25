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
}
