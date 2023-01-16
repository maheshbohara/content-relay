<?php
/**
 * Stable identities for posts and terms, stored as meta on both sites.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, assigns and looks up Content Relay UUIDs.
 */
final class Uuid {

	/**
	 * Meta key holding the UUID on posts (attachments included) and terms.
	 */
	public const META_KEY = '_content_relay_uuid';

	/**
	 * Whether a string is a well-formed UUID.
	 *
	 * @param string $value Candidate.
	 */
	public static function is_valid( string $value ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value );
	}

	/**
	 * Returns a post's UUID without creating one.
	 *
	 * @param int $post_id Post id.
	 * @return string|null
	 */
	public function get_post( int $post_id ): ?string {
		$value = get_post_meta( $post_id, self::META_KEY, true );

		return is_string( $value ) && self::is_valid( $value ) ? $value : null;
	}
}
