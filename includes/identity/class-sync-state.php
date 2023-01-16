<?php
/**
 * What a site recorded about the last relay of an object, per peer.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and encodes `_content_relay_sync`: `{ "<peer home url>": {incoming, local, session, at, direction} }`.
 */
final class Sync_State {

	/**
	 * Meta key.
	 */
	public const META_KEY = '_content_relay_sync';

	/**
	 * State recorded for a post and peer.
	 *
	 * @param int    $post_id Post id.
	 * @param string $peer    Peer home URL.
	 * @return array{incoming: string, local: string, session: string, at: string, direction: string}|null
	 */
	public function get_post( int $post_id, string $peer ): ?array {
		$all   = $this->all_post( $post_id );
		$state = $all[ $peer ] ?? null;

		if ( ! is_array( $state ) || ! is_string( $state['incoming'] ?? null ) || ! is_string( $state['local'] ?? null ) ) {
			return null;
		}

		return array(
			'incoming'  => $state['incoming'],
			'local'     => $state['local'],
			'session'   => (string) ( $state['session'] ?? '' ),
			'at'        => (string) ( $state['at'] ?? '' ),
			'direction' => (string) ( $state['direction'] ?? '' ),
		);
	}

	/**
	 * Every peer's state for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array<string, mixed>
	 */
	public function all_post( int $post_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Sync state is written directly during apply; the meta cache may be stale.
		$raw     = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1", $post_id, self::META_KEY ) );
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Encodes a post's state after adding or replacing one peer.
	 *
	 * @param int                   $post_id Post id.
	 * @param string                $peer    Peer home URL.
	 * @param array<string, string> $state   incoming, local, session, at, direction.
	 * @return string JSON.
	 */
	public function encode_with( int $post_id, string $peer, array $state ): string {
		$all          = $this->all_post( $post_id );
		$all[ $peer ] = $state;

		return (string) wp_json_encode( $all, JSON_UNESCAPED_SLASHES );
	}
}
