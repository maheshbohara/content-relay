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
}
