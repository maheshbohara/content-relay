<?php
/**
 * Encrypts secrets stored in the database with key material that lives in wp-config.php.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Connection;

use ContentRelay\Support\Base64_Url;

defined( 'ABSPATH' ) || exit;

/**
 * Symmetric encryption (XSalsa20-Poly1305) for connection secrets.
 *
 * A database copied to another site carries the ciphertext but not the key material, so a stored connection cannot
 * be used there. Key material comes from constants only — never from wp_salt(), which falls back to salts stored in
 * the database when the constants are missing.
 */
final class Vault {

	/**
	 * Placeholder text of an unconfigured wp-config.php salt.
	 */
	private const PLACEHOLDER = 'put your unique phrase here';

	/**
	 * Nonce length for sodium_crypto_secretbox.
	 */
	private const NONCE_BYTES = 24;

	/**
	 * Authentication tag length for sodium_crypto_secretbox.
	 */
	private const MAC_BYTES = 16;

	/**
	 * Derived 32-byte key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Constructor.
	 *
	 * @param string $material Key material (at least 32 characters).
	 */
	public function __construct( string $material ) {
		$this->key = sodium_crypto_generichash( 'content-relay-vault|' . $material, '', 32 );
	}

	/**
	 * Builds a vault from wp-config.php constants.
	 *
	 * Uses CONTENT_RELAY_ENCRYPTION_KEY when defined, otherwise AUTH_KEY + SECURE_AUTH_KEY.
	 *
	 * @return self|null Null when no usable key material is configured.
	 */
	public static function from_config(): ?self {
		if ( defined( 'CONTENT_RELAY_ENCRYPTION_KEY' ) ) {
			$material = constant( 'CONTENT_RELAY_ENCRYPTION_KEY' );
			return ( is_string( $material ) && strlen( $material ) >= 32 ) ? new self( $material ) : null;
		}

		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'SECURE_AUTH_KEY' ) ) {
			return null;
		}

		$auth   = constant( 'AUTH_KEY' );
		$secure = constant( 'SECURE_AUTH_KEY' );
		if ( ! is_string( $auth ) || ! is_string( $secure ) || strlen( $auth ) < 32 || strlen( $secure ) < 32 ) {
			return null;
		}
		if ( false !== strpos( $auth . $secure, self::PLACEHOLDER ) ) {
			return null;
		}

		return new self( $auth . $secure );
	}

	/**
	 * Encrypts a secret.
	 *
	 * @param string $plain Secret.
	 * @return string base64url ciphertext (nonce + box).
	 */
	public function encrypt( string $plain ): string {
		$nonce = random_bytes( self::NONCE_BYTES );

		return Base64_Url::encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $this->key ) );
	}

	/**
	 * Decrypts a secret.
	 *
	 * @param string $cipher Result of encrypt().
	 * @return string|null Null when the ciphertext is malformed or was encrypted with other key material.
	 */
	public function decrypt( string $cipher ): ?string {
		$raw = Base64_Url::decode( $cipher );
		if ( null === $raw || strlen( $raw ) < self::NONCE_BYTES + self::MAC_BYTES ) {
			return null;
		}

		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, self::NONCE_BYTES ), substr( $raw, 0, self::NONCE_BYTES ), $this->key );
		} catch ( \SodiumException $e ) {
			return null;
		}

		return false === $plain ? null : $plain;
	}
}
