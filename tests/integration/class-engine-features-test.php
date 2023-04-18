<?php
/**
 * Attachment parents, term meta ids, query dependencies and page caches.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Integration;

use ContentRelay\Apply\Applier;
use ContentRelay\Apply\Rollback;
use ContentRelay\Apply\Term_Writer;
use ContentRelay\Receive\Planner;

/**
 * M3 engine behaviour on a plain single-language site.
 */
final class Engine_Features_Test extends Test_Case {

	/**
	 * An image uploaded to the relayed page gets the new page as its parent; one uploaded elsewhere gets none.
	 */
	public function test_attachment_parent_is_the_relayed_page(): void {
		$other    = $this->page();
		$page     = $this->page();
		$own      = $this->image( $page );
		$borrowed = $this->image( $other, 'cc3333' );
		wp_update_post(
			array(
				'ID'           => $page,
				'post_content' => self::image_block( $own ) . self::image_block( $borrowed ),
			)
		);

		$result   = $this->relay_back( $page, array( $page, $own, $borrowed ) );
		$new_page = self::written_id( $result, 'post:' . $page );

		$this->assertSame( $new_page, (int) get_post_field( 'post_parent', self::written_id( $result, 'post:' . $own ) ) );
		$this->assertSame( 0, (int) get_post_field( 'post_parent', self::written_id( $result, 'post:' . $borrowed ) ) );
	}

	/**
	 * ACF id fields of a created term are mapped when the relay carries the object and removed with a warning otherwise.
	 */
	public function test_term_meta_ids_are_mapped_or_removed(): void {
		register_taxonomy( 'relay_topic', 'page' );

		$writer  = new Term_Writer( $this->context() );
		$term_id = $writer->create(
			array(
				'taxonomy'    => 'relay_topic',
				'slug'        => self::unique( 'topic' ),
				'name'        => 'Topic',
				'description' => '',
				'language'    => null,
				'uuid'        => null,
				'meta'        => array(
					'photo'   => array( '123' ),
					'_photo'  => array( 'field_photo' ),
					'gallery' => array( (string) maybe_serialize( array( '123', '456' ) ) ),
					'website' => array( '789' ),
				),
				'meta_refs'   => array(
					array(
						'key'   => 'photo',
						'index' => 0,
						'kind'  => 'post',
					),
					array(
						'key'   => 'gallery',
						'index' => 0,
						'kind'  => 'post',
					),
				),
			),
			0,
			null,
			array( 'post:123' => 999 )
		);

		$this->assertSame( '999', get_term_meta( $term_id, 'photo', true ) );
		$this->assertSame( array( '999' ), get_term_meta( $term_id, 'gallery', true ) );
		$this->assertSame( '789', get_term_meta( $term_id, 'website', true ) );
		$this->assertSame( 'field_photo', get_term_meta( $term_id, '_photo', true ) );
		$this->assertCount( 1, $writer->warnings() );
		$this->assertStringContainsString( 'post 456', $writer->warnings()[0] );
	}
}
