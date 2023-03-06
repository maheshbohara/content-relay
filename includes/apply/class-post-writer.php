<?php
/**
 * Writes posts, attachment rows, meta and term assignments directly.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Apply;

use ContentRelay\Discovery\Exporter;

defined( 'ABSPATH' ) || exit;

/**
 * Direct row writes.
 *
 * WordPress's wp_insert_post()/wp_update_post() are not used: WPML's save hooks copy fields onto translation twins and
 * de-duplicate translated slugs, and WPML's add_attachment hook duplicates new media into every language. The relay
 * writes rows itself and performs the side effects it needs (language links, caches, indexables) explicitly.
 */
final class Post_Writer {

	/**
	 * Attachment meta generated on this site from the file, never replaced from the manifest.
	 */
	private const ATTACHMENT_META = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' );

	/**
	 * Inserts a minimal row to reserve an id; update() fills it in.
	 *
	 * @param string $post_type Post type.
	 * @return int Post id.
	 *
	 * @throws Apply_Exception When the insert fails.
	 */
	public function reserve( string $post_type ): int {
		global $wpdb;

		$now     = current_time( 'mysql' );
		$now_gmt = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- See class docblock: WordPress save APIs trigger WPML sync.
		$inserted = $wpdb->insert(
			$wpdb->posts,
			array(
				'post_author'           => 0,
				'post_date'             => $now,
				'post_date_gmt'         => $now_gmt,
				'post_content'          => '',
				'post_title'            => '',
				'post_excerpt'          => '',
				'post_status'           => 'draft',
				'post_name'             => '',
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => $now,
				'post_modified_gmt'     => $now_gmt,
				'post_content_filtered' => '',
				'post_type'             => $post_type,
				'guid'                  => '',
			)
		);

		if ( false === $inserted ) {
			throw new Apply_Exception( esc_html( sprintf( 'Could not create a %1$s row: %2$s', $post_type, $wpdb->last_error ) ) );
		}

		return (int) $wpdb->insert_id;
	}
}
