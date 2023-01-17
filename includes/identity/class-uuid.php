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

	/**
	 * Returns a post's UUID, assigning one when it has none.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function ensure_post( int $post_id ): string {
		$uuid = $this->get_post( $post_id );
		if ( null === $uuid ) {
			$uuid = wp_generate_uuid4();
			update_post_meta( $post_id, self::META_KEY, $uuid );
		}

		return $uuid;
	}

	/**
	 * Returns a term's UUID without creating one.
	 *
	 * @param int $term_id Term id.
	 * @return string|null
	 */
	public function get_term( int $term_id ): ?string {
		$value = get_term_meta( $term_id, self::META_KEY, true );

		return is_string( $value ) && self::is_valid( $value ) ? $value : null;
	}

	/**
	 * Returns a term's UUID, assigning one when it has none.
	 *
	 * @param int $term_id Term id.
	 * @return string
	 */
	public function ensure_term( int $term_id ): string {
		$uuid = $this->get_term( $term_id );
		if ( null === $uuid ) {
			$uuid = wp_generate_uuid4();
			update_term_meta( $term_id, self::META_KEY, $uuid );
		}

		return $uuid;
	}

	/**
	 * Finds every post carrying a UUID. More than one result means a duplicated identity.
	 *
	 * @param string $uuid UUID.
	 * @return int[]
	 */
	public function find_posts( string $uuid ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Identity lookup must see the database as it is now, not a cached view.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", self::META_KEY, $uuid ) );

		return array_map( 'intval', $ids );
	}

	/**
	 * Finds every term carrying a UUID.
	 *
	 * @param string $uuid UUID.
	 * @return int[]
	 */
	public function find_terms( string $uuid ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Identity lookup must see the database as it is now, not a cached view.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s", self::META_KEY, $uuid ) );

		return array_map( 'intval', $ids );
	}
}
