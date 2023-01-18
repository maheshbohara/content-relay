<?php
/**
 * Looks up the public key a request claims to be signed with.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Receiver-side public key lookup.
 */
interface Key_Store {

	/**
	 * Binary Ed25519 public key of an active (not revoked) key.
	 *
	 * @param string $kid Key id.
	 * @return string|null Null when the key is unknown or revoked.
	 */
	public function public_key_for( string $kid ): ?string;
}
