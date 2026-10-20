<?php
/**
 * The receiver's nonce store.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Integration;

use ContentRelay\Connection\Atomic_Replay_Guard;

/**
 * A nonce is accepted once per key and forgotten after its lifetime.
 */
final class Replay_Guard_Test extends Test_Case {

	/**
	 * The second use of a nonce is refused; the same nonce under another key is not.
	 */
	public function test_accepts_a_nonce_once_per_key(): void {
		$guard = new Atomic_Replay_Guard();

		$this->assertTrue( $guard->remember( 'kid-a', 'nonce', 60 ) );
		$this->assertFalse( $guard->remember( 'kid-a', 'nonce', 60 ) );
		$this->assertTrue( $guard->remember( 'kid-b', 'nonce', 60 ) );
	}

	/**
	 * Expired nonces are removed the next time one is recorded.
	 */
	public function test_forgets_expired_nonces(): void {
		global $wpdb;

		$guard = new Atomic_Replay_Guard();
		$this->assertTrue( $guard->remember( 'kid-a', 'old', -1 ) );
		$this->assertTrue( $guard->remember( 'kid-a', 'new', 60 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Counts the guard's own rows.
		$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( Atomic_Replay_Guard::OPTION_PREFIX ) . '%' ) );
		$this->assertSame( 1, $rows );
	}
}
