<?php
/**
 * Fingerprints of what a site currently holds, and of what a manifest carries.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Identity;

use ContentRelay\Discovery\Exporter;
use ContentRelay\Language\Adapter;

defined( 'ABSPATH' ) || exit;

/**
 * Site-local state hashes.
 *
 * A receiver compares its own current fingerprint with the one it stored at the last relay, so the hash never needs
 * to be comparable across sites and can include local ids.
 */
final class State_Fingerprint {

	/**
	 * Manifest keys that describe how an object was reached, not what it contains. An attachment's `upload_parent` is
	 * bookkeeping too: changing only where an image was uploaded does not make it a changed image.
	 */
	private const NON_CONTENT_KEYS = array( 'relations', 'role', 'on_missing', 'upload_parent' );

	/**
	 * Hasher.
	 *
	 * @var Fingerprint
	 */
	private Fingerprint $fingerprint;

	/**
	 * Language adapter.
	 *
	 * @var Adapter
	 */
	private Adapter $language;

	/**
	 * Constructor.
	 *
	 * @param Fingerprint $fingerprint Hasher.
	 * @param Adapter     $language    Language adapter.
	 */
	public function __construct( Fingerprint $fingerprint, Adapter $language ) {
		$this->fingerprint = $fingerprint;
		$this->language    = $language;
	}

	/**
	 * Fingerprint of a post or attachment as stored on this site.
	 *
	 * @param int $post_id Post id.
	 * @return string|null Null when the post does not exist.
	 */
	public function post( int $post_id ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conflict detection must hash the stored row, not a cached or filtered copy.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_author, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, post_parent, menu_order, post_mime_type FROM {$wpdb->posts} WHERE ID = %d", $post_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conflict detection must hash the stored rows.
		$meta_rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key, meta_id", $post_id ), ARRAY_A );

		$denied = Exporter::denied_meta();
		$meta   = array();
		foreach ( (array) $meta_rows as $meta_row ) {
			$meta_key = (string) $meta_row['meta_key'];
			if ( in_array( $meta_key, $denied, true ) || 0 === strpos( $meta_key, '_content_relay_' ) ) {
				continue;
			}
			$meta[] = array( $meta_key, (string) $meta_row['meta_value'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conflict detection must hash the stored rows.
		$terms = $wpdb->get_col( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d ORDER BY term_taxonomy_id", $post_id ) );

		return $this->fingerprint->of(
			array(
				'row'      => $row,
				'meta'     => $meta,
				'terms'    => array_map( 'intval', $terms ),
				'language' => $this->language->post_language( $post_id ),
			)
		);
	}

	/**
	 * Fingerprint of an object as carried by a manifest, together with the receiver ids its references resolve to.
	 *
	 * Including the resolved ids means an object is rewritten when something it points at resolves differently (for
	 * example a term that had to be created), even though its own content did not change.
	 *
	 * @param array<string, mixed>      $record   Manifest object.
	 * @param array<string, int|string> $resolved Referenced key => receiver id (or `new`).
	 * @return string
	 */
	public function incoming( array $record, array $resolved = array() ): string {
		foreach ( self::NON_CONTENT_KEYS as $key ) {
			unset( $record[ $key ] );
		}
		ksort( $resolved );
		$record['resolved'] = $resolved;

		return $this->fingerprint->of( $record );
	}

	/**
	 * Keys of every object an owned object references by id: location refs, parent, author and terms.
	 *
	 * @param array<string, mixed> $record Manifest object.
	 * @return string[] Sorted, unique.
	 */
	public static function ref_keys( array $record ): array {
		$keys = array();
		foreach ( (array) ( $record['locations'] ?? array() ) as $location ) {
			foreach ( (array) ( $location['refs'] ?? array() ) as $ref ) {
				$keys[] = (string) $ref;
			}
		}
		foreach ( array( 'parent', 'author' ) as $field ) {
			if ( is_string( $record[ $field ] ?? null ) ) {
				$keys[] = $record[ $field ];
			}
		}
		foreach ( (array) ( $record['terms'] ?? array() ) as $term_keys ) {
			foreach ( (array) $term_keys as $key ) {
				$keys[] = (string) $key;
			}
		}

		$keys = array_values( array_unique( $keys ) );
		sort( $keys );

		return $keys;
	}
}
