<?php
/**
 * Replay guard backed by transients.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Stores seen nonces as transients keyed by kid + nonce.
 */
final class Transient_Replay_Guard implements Replay_Guard {

	/**
	 * Records a nonce.
	 *
	 * @param string $kid     Key id.
	 * @param string $nonce   Request nonce.
	 * @param int    $seconds How long the nonce is remembered.
	 * @return bool False when the nonce was already seen within that time.
	 */
	public function remember( string $kid, string $nonce, int $seconds ): bool {
		$name = 'content_relay_nonce_' . md5( $kid . '|' . $nonce );
		if ( false !== get_transient( $name ) ) {
			return false;
		}

		set_transient( $name, 1, $seconds );

		return true;
	}
}
