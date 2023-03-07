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

	/**
	 * Replaces a post's meta with manifest meta, keeping keys this site owns.
	 *
	 * @param int                     $post_id       Post id.
	 * @param array<string, string[]> $meta          Rewritten raw meta.
	 * @param bool                    $is_attachment Whether the post is an attachment.
	 *
	 * @throws Apply_Exception When a write fails.
	 */
	public function replace_meta( int $post_id, array $meta, bool $is_attachment ): void {
		global $wpdb;

		$kept = Exporter::denied_meta();
		if ( $is_attachment ) {
			$kept = array_merge( $kept, self::ATTACHMENT_META );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the rows being replaced in this transaction.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$meta_key = (string) $row['meta_key'];
			if ( in_array( $meta_key, $kept, true ) || 0 === strpos( $meta_key, '_content_relay_' ) || 0 === strpos( $meta_key, '_wp_trash_meta_' ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct meta writes avoid WPML custom-field sync onto translations.
			if ( false === $wpdb->delete( $wpdb->postmeta, array( 'meta_id' => (int) $row['meta_id'] ) ) ) {
				throw new Apply_Exception( esc_html( sprintf( 'Could not remove meta of post %d.', $post_id ) ) );
			}
		}

		foreach ( $meta as $meta_key => $values ) {
			if ( in_array( (string) $meta_key, $kept, true ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				$this->add_meta( $post_id, (string) $meta_key, (string) $value );
			}
		}
	}

	/**
	 * Inserts one meta row.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Meta key.
	 * @param string $value    Raw (already serialized) value.
	 *
	 * @throws Apply_Exception When the insert fails.
	 */
	public function add_meta( int $post_id, string $meta_key, string $value ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct meta writes avoid WPML custom-field sync onto translations.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES (%d, %s, %s)", $post_id, $meta_key, $value ) );

		if ( false === $inserted ) {
			throw new Apply_Exception( esc_html( sprintf( 'Could not write meta %1$s of post %2$d.', $meta_key, $post_id ) ) );
		}
	}

	/**
	 * Replaces a single-valued meta key.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Meta key.
	 * @param string $value    Raw value.
	 */
	public function set_meta( int $post_id, string $meta_key, string $value ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct meta writes avoid WPML custom-field sync onto translations.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $meta_key ) );
		$this->add_meta( $post_id, $meta_key, $value );
	}

	/**
	 * Sets a post's terms in every taxonomy of its post type.
	 *
	 * @param int                  $post_id   Post id.
	 * @param string               $post_type Post type.
	 * @param array<string, int[]> $terms     Taxonomy => receiver term ids.
	 *
	 * @throws Apply_Exception When a write fails.
	 */
	public function replace_terms( int $post_id, string $post_type, array $terms ): void {
		global $wpdb;

		$taxonomies = array_values( array_unique( array_merge( get_object_taxonomies( $post_type ), array_keys( $terms ) ) ) );
		if ( array() === $taxonomies ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated for each taxonomy; wp_set_object_terms() re-adds default-language terms under WPML.
		$current = $wpdb->get_results( $wpdb->prepare( "SELECT tr.term_taxonomy_id, tt.taxonomy FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy IN (" . implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) ) . ')', array_merge( array( $post_id ), $taxonomies ) ), ARRAY_A );

		$wanted = array();
		foreach ( $terms as $taxonomy => $term_ids ) {
			foreach ( $term_ids as $term_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WPML filters term lookups by the current language.
				$tt_id = $wpdb->get_var( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s", $term_id, $taxonomy ) );
				if ( null === $tt_id ) {
					throw new Apply_Exception( esc_html( sprintf( 'Term %1$d is not in taxonomy %2$s.', $term_id, $taxonomy ) ) );
				}
				$wanted[ (int) $tt_id ] = (string) $taxonomy;
			}
		}

		$have    = array();
		$touched = array();
		foreach ( (array) $current as $row ) {
			$tt_id          = (int) $row['term_taxonomy_id'];
			$have[ $tt_id ] = (string) $row['taxonomy'];
			if ( ! isset( $wanted[ $tt_id ] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See replace_terms() docblock.
				$wpdb->delete(
					$wpdb->term_relationships,
					array(
						'object_id'        => $post_id,
						'term_taxonomy_id' => $tt_id,
					)
				);
				$touched[ $have[ $tt_id ] ][] = $tt_id;
			}
		}

		foreach ( $wanted as $tt_id => $taxonomy ) {
			if ( isset( $have[ $tt_id ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- See replace_terms() docblock.
			$inserted = $wpdb->insert(
				$wpdb->term_relationships,
				array(
					'object_id'        => $post_id,
					'term_taxonomy_id' => $tt_id,
					'term_order'       => 0,
				)
			);
			if ( false === $inserted ) {
				throw new Apply_Exception( esc_html( sprintf( 'Could not assign term %1$d to post %2$d.', $tt_id, $post_id ) ) );
			}
			$touched[ $taxonomy ][] = $tt_id;
		}

		foreach ( $touched as $taxonomy => $tt_ids ) {
			wp_update_term_count_now( $tt_ids, $taxonomy );
		}
	}

	/**
	 * Deletes a post row and all its meta (used to undo created attachments).
	 *
	 * @param int $post_id Post id.
	 */
	public function delete( int $post_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes rows this relay created; wp_delete_post() would also delete shared files and fire WPML hooks.
		$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $post_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
		$wpdb->delete( $wpdb->posts, array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
	}
}
