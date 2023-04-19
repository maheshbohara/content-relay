<?php
/**
 * Plan → apply → re-plan → conflict → rollback on a plain single-language site.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Integration;

use ContentRelay\Apply\Applier;
use ContentRelay\Apply\Rollback;
use ContentRelay\Receive\Planner;

/**
 * A page with an image is exported, removed, and relayed back from its bundle, so every id is new.
 */
final class Roundtrip_Test extends Test_Case {

	/**
	 * The whole engine path through one relay.
	 */
	public function test_create_unchanged_conflict_rollback(): void {
		$parent_id = $this->page();
		$child_id  = $this->page( array( 'post_parent' => $parent_id ) );
		$image_id  = $this->image( $child_id );
		wp_update_post(
			array(
				'ID'           => $child_id,
				'post_content' => self::image_block( $image_id ),
			)
		);

		$manifest = $this->export( $child_id );
		$bundle   = $this->bundle( $manifest );
		$objects  = $manifest['objects'];

		$this->assertSame( 'owned', $objects[ 'post:' . $child_id ]['role'] );
		$this->assertSame( 'owned', $objects[ 'post:' . $image_id ]['role'] );
		$this->assertSame( 'referenced', $objects[ 'post:' . $parent_id ]['role'] );
		$this->assertNull( $objects[ 'post:' . $child_id ]['language'] );

		wp_delete_post( $child_id, true );
		wp_delete_attachment( $image_id, true );

		$context = $this->context();
		$plan    = ( new Planner( $context ) )->plan( $bundle, false );
		$items   = self::items( $plan );
		$this->assertSame( array(), $plan['blockers'] );
		$this->assertSame( 'create', $items[ 'post:' . $child_id ]['action'] );
		$this->assertSame( 'create', $items[ 'post:' . $image_id ]['action'] );
		$this->assertSame( 'reuse', $items[ 'post:' . $parent_id ]['action'] );

		$result = ( new Applier( $context ) )->apply( $bundle, false, $plan['hash'] );
		$this->assertSame( array(), $result['problems'] );

		$new_child = self::written_id( $result, 'post:' . $child_id );
		$new_image = self::written_id( $result, 'post:' . $image_id );
		$this->assertNotSame( 0, $new_child );
		$this->assertNotSame( $child_id, $new_child );
		$this->assertNotSame( $image_id, $new_image );

		$post = get_post( $new_child );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$this->assertSame( $parent_id, (int) $post->post_parent );
		$this->assertStringContainsString( '"id":' . $new_image, $post->post_content );
		$this->assertStringContainsString( 'wp-image-' . $new_image, $post->post_content );
		$this->assertStringNotContainsString( '"id":' . $image_id . ',', $post->post_content );
		$this->assertFileExists( (string) get_attached_file( $new_image ) );

		$again = self::items( ( new Planner( $this->context() ) )->plan( $bundle, false ) );
		$this->assertSame( 'unchanged', $again[ 'post:' . $child_id ]['action'] );
		$this->assertSame( 'unchanged', $again[ 'post:' . $image_id ]['action'] );

		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_title' => 'Edited on the receiver' ), array( 'ID' => $new_child ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- An edit that bypasses save hooks, like another editor's direct change.
		clean_post_cache( $new_child );

		$conflict = ( new Planner( $this->context() ) )->plan( $bundle, false );
		$this->assertSame( 'conflict', self::items( $conflict )[ 'post:' . $child_id ]['action'] );
		$this->assertNotEmpty( $conflict['blockers'] );
		$this->assertSame( 'overwrite', self::items( ( new Planner( $this->context() ) )->plan( $bundle, true ) )[ 'post:' . $child_id ]['action'] );

		$summary = ( new Rollback( $this->context() ) )->rollback( $result['session'], true );
		$this->assertContains( $new_child, $summary['deleted'] );
		$this->assertContains( $new_image, $summary['deleted'] );
		$this->assertNull( get_post( $new_child ) );
		$this->assertNull( get_post( $new_image ) );
	}
}
