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
}
