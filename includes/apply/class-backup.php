<?php
/**
 * Snapshots of receiver objects before a relay overwrites them, and session records.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Apply;

use ContentRelay\Identity\Uuid;
use ContentRelay\Receive\Receive_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Writes and reads the reverse manifest (spec §11) and session records in a protected uploads directory.
 */
final class Backup {

	/**
	 * Receiver context.
	 *
	 * @var Receive_Context
	 */
	private Receive_Context $context;

	/**
	 * Constructor.
	 *
	 * @param Receive_Context $context Receiver context.
	 */
	public function __construct( Receive_Context $context ) {
		$this->context = $context;
	}

	/**
	 * Stores the current rows of posts that are about to be replaced.
	 *
	 * @param string $session  Session id.
	 * @param int[]  $post_ids Posts (and attachments) that will be updated.
	 * @return string Backup file path.
	 *
	 * @throws Apply_Exception When the backup cannot be written; nothing is changed without one.
	 */
	public function snapshot( string $session, array $post_ids ): string {
		global $wpdb;

		$posts = array();
		foreach ( array_unique( $post_ids ) as $post_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A backup must copy stored rows exactly.
			$posts[ $post_id ] = array(
				'row'      => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $post_id ), ARRAY_A ),
				'meta'     => $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $post_id ), ARRAY_A ),
				'terms'    => array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d", $post_id ) ) ),
				'language' => $this->context->language->export_post_state( (int) $post_id ),
			);
			// phpcs:enable
		}

		return $this->write(
			'backups',
			$session,
			array(
				'session'    => $session,
				'created_at' => gmdate( 'c' ),
				'posts'      => $posts,
			)
		);
	}

	/**
	 * Stores a session record (plan, result, created objects).
	 *
	 * @param string               $session Session id.
	 * @param array<string, mixed> $record  Record.
	 * @return string File path.
	 *
	 * @throws Apply_Exception When the record cannot be written.
	 */
	public function record_session( string $session, array $record ): string {
		return $this->write( 'sessions', $session, $record );
	}

	/**
	 * Adds fields to an existing session record.
	 *
	 * @param string               $session Session id.
	 * @param array<string, mixed> $changes Fields to add or replace.
	 *
	 * @throws Apply_Exception When the record cannot be read or written.
	 */
	public function mark_session( string $session, array $changes ): void {
		$this->record_session( $session, array_merge( $this->read( 'sessions', $session ), $changes ) );
	}

	/**
	 * Reads a backup or session record.
	 *
	 * @param string $subdirectory `backups` or `sessions`.
	 * @param string $session      Session id.
	 * @return array<string, mixed>
	 *
	 * @throws Apply_Exception When the record does not exist or cannot be decoded.
	 */
	public function read( string $subdirectory, string $session ): array {
		if ( ! Uuid::is_valid( $session ) ) {
			throw new Apply_Exception( 'That is not a relay session id.' );
		}

		$path = $this->path( $subdirectory, $session );
		if ( ! is_readable( $path ) ) {
			throw new Apply_Exception( esc_html( sprintf( 'No %1$s record for session %2$s on this site.', $subdirectory, $session ) ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file inside uploads.
		$raw  = file_get_contents( $path );
		$json = false === $raw ? false : gzdecode( $raw );
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new Apply_Exception( esc_html( sprintf( 'The %1$s record for session %2$s is unreadable.', $subdirectory, $session ) ) );
		}

		return $data;
	}
}
