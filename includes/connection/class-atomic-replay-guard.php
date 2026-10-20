<?php
/**
 * Replay guard that claims each nonce atomically.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Stores seen nonces keyed by kid + nonce. Checking and recording are one operation, so of two concurrent requests
 * with the same nonce exactly one is accepted: an `add` in a persistent object cache, otherwise an `INSERT IGNORE`
 * against the unique option name (as core's WP_Upgrader::create_lock() does).
 */
final class Atomic_Replay_Guard implements Replay_Guard {

	/**
	 * Prefix of the option rows holding seen nonces; the value is the Unix time the nonce may be forgotten.
	 */
	public const OPTION_PREFIX = 'content_relay_nonce_';

	/**
	 * Object cache group.
	 */
	private const CACHE_GROUP = 'content_relay_nonces';

	/**
	 * Records a nonce.
	 *
	 * @param string $kid     Key id.
	 * @param string $nonce   Request nonce.
	 * @param int    $seconds How long the nonce is remembered.
	 * @return bool False when the nonce was already seen within that time.
	 */
	public function remember( string $kid, string $nonce, int $seconds ): bool {
		$hash = hash( 'sha256', $kid . '|' . $nonce );

		if ( wp_using_ext_object_cache() ) {
			return wp_cache_add( $hash, 1, self::CACHE_GROUP, $seconds );
		}

		global $wpdb;

		$now = time();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- add_option() reads before it writes; only a single INSERT IGNORE on the unique option_name is atomic.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d', $wpdb->options, $wpdb->esc_like( self::OPTION_PREFIX ) . '%', $now ) );
		$claimed = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, self::OPTION_PREFIX . $hash, (string) ( $now + $seconds ), 'no' ) );
		// phpcs:enable

		return 1 === $claimed;
	}
}
