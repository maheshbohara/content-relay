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

	/**
	 * Writes a post row from manifest fields.
	 *
	 * @param int                  $post_id   Post id.
	 * @param array<string, mixed> $record    Manifest object.
	 * @param array<string, mixed> $fields    Rewritten fields.
	 * @param string               $content   Rewritten content.
	 * @param int                  $author_id Receiver author id.
	 * @param int                  $parent_id Receiver parent id.
	 * @param string               $guid      GUID to set when the row has none.
	 *
	 * @throws Apply_Exception When the update fails.
	 */
	public function update( int $post_id, array $record, array $fields, string $content, int $author_id, int $parent_id, string $guid ): void {
		global $wpdb;

		$data = array(
			'post_author'       => $author_id,
			'post_date'         => (string) $fields['post_date'],
			'post_date_gmt'     => (string) $fields['post_date_gmt'],
			'post_content'      => $content,
			'post_title'        => (string) $fields['post_title'],
			'post_excerpt'      => (string) $fields['post_excerpt'],
			'post_status'       => (string) $fields['post_status'],
			'comment_status'    => (string) $fields['comment_status'],
			'ping_status'       => (string) $fields['ping_status'],
			'post_password'     => (string) $fields['post_password'],
			'post_name'         => (string) $fields['post_name'],
			'post_modified'     => current_time( 'mysql' ),
			'post_modified_gmt' => current_time( 'mysql', true ),
			'post_parent'       => $parent_id,
			'menu_order'        => (int) $fields['menu_order'],
			'post_type'         => (string) $record['post_type'],
			'post_mime_type'    => (string) $fields['post_mime_type'],
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the row being written in this transaction.
		$current_guid = (string) $wpdb->get_var( $wpdb->prepare( "SELECT guid FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
		if ( '' === $current_guid ) {
			$data['guid'] = $guid;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See class docblock: WordPress save APIs trigger WPML sync.
		$updated = $wpdb->update( $wpdb->posts, $data, array( 'ID' => $post_id ) );
		if ( false === $updated ) {
			throw new Apply_Exception( esc_html( sprintf( 'Could not write post %1$d: %2$s', $post_id, $wpdb->last_error ) ) );
		}
	}
}
