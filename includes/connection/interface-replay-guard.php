<?php
/**
 * Remembers request nonces so a captured request cannot be replayed.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Nonce replay guard.
 */
interface Replay_Guard {

	/**
	 * Records a nonce.
	 *
	 * @param string $kid     Key id.
	 * @param string $nonce   Request nonce.
	 * @param int    $seconds How long the nonce is remembered.
	 * @return bool False when the nonce was already seen within that time.
	 */
	public function remember( string $kid, string $nonce, int $seconds ): bool;
}
