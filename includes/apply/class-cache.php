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
}
