<?php
/**
 * Cache and index refresh after direct writes.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Apply;

use ContentRelay\Receive\Receive_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Clears object caches, the language plugin's caches and Yoast indexables for written posts.
 */
final class Cache {

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
	 * Refreshes everything derived from the given posts.
	 *
	 * @param int[] $post_ids Post ids written, restored or deleted.
	 */
	public function after_write( array $post_ids ): void {
		$post_ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );

		foreach ( $post_ids as $post_id ) {
			clean_post_cache( $post_id );
		}
		$this->context->language->flush_caches();
		$this->flush_yoast( $post_ids );
	}

	/**
	 * Deletes Yoast indexables of posts so they are rebuilt from the stored data on next load.
	 *
	 * @param int[] $post_ids Post ids.
	 */
	private function flush_yoast( array $post_ids ): void {
		global $wpdb;

		if ( ! defined( 'WPSEO_VERSION' ) || array() === $post_ids ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Yoast renders titles from its indexable table; the rows are rebuilt on next load. Placeholders are generated per id.
		$indexable_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}yoast_indexable WHERE object_type = 'post' AND object_id IN ($placeholders)", $post_ids ) );
		if ( array() !== $indexable_ids ) {
			$indexable_placeholders = implode( ',', array_fill( 0, count( $indexable_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}yoast_indexable_hierarchy WHERE indexable_id IN ($indexable_placeholders)", $indexable_ids ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}yoast_indexable WHERE id IN ($indexable_placeholders)", $indexable_ids ) );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}yoast_primary_term WHERE post_id IN ($placeholders)", $post_ids ) );
		// phpcs:enable
	}
}
