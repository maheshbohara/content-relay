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
}
