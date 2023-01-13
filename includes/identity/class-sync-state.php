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
}
