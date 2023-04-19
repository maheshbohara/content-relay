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

	/**
	 * Declared query dependencies are checked on the receiver's post, and page caches are purged on apply and rollback.
	 */
	public function test_query_dependencies_and_page_caches(): void {
		$seen  = array();
		$calls = array();

		add_filter(
			'content_relay_query_dependencies',
			static function ( array $dependencies, \WP_Post $post ) use ( &$seen ): array {
				$seen[]         = $post->ID;
				$dependencies[] = array(
					'label' => 'Events for this page',
					'check' => '__return_false',
				);
				$dependencies[] = array(
					'label' => 'Always there',
					'check' => '__return_true',
				);
				return $dependencies;
			},
			10,
			2
		);
		add_filter(
			'content_relay_page_cache_integrations',
			static function ( array $integrations ) use ( &$calls ): array {
				$integrations['fake-cache'] = array(
					'available' => '__return_true',
					'purge'     => static function ( array $urls, array $post_ids ) use ( &$calls ): void {
						$calls[] = array( $urls, $post_ids );
					},
				);
				return $integrations;
			}
		);

		$page   = $this->page();
		$result = $this->relay_back( $page, array( $page ) );
		$new    = self::written_id( $result, 'post:' . $page );

		$this->assertSame( array( $new ), $seen );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( '"Events for this page" found nothing', $result['warnings'][0] );
		$this->assertSame( array( 'fake-cache' ), $result['caches'] );
		$this->assertSame( array( (string) get_permalink( $new ) ), $calls[0][0] );
		$this->assertSame( array( $new ), $calls[0][1] );

		$summary = ( new Rollback( $this->context() ) )->rollback( $result['session'], false );
		$this->assertSame( array( 'fake-cache' ), $summary['caches'] );
		$this->assertCount( 2, $calls );
	}

	/**
	 * Exports a page, deletes the given posts, and applies the bundle back, so every relayed object gets a new id.
	 *
	 * @param int   $post_id Page.
	 * @param int[] $delete  Posts and attachments deleted before applying.
	 * @return array<string, mixed> Apply result.
	 */
	private function relay_back( int $post_id, array $delete ): array {
		$bundle = $this->bundle( $this->export( $post_id ) );
		foreach ( $delete as $id ) {
			if ( 'attachment' === get_post_type( $id ) ) {
				wp_delete_attachment( $id, true );
			} else {
				wp_delete_post( $id, true );
			}
		}

		$context = $this->context();
		$plan    = ( new Planner( $context ) )->plan( $bundle, false );
		$this->assertSame( array(), $plan['blockers'] );

		$result = ( new Applier( $context ) )->apply( $bundle, false, $plan['hash'] );
		$this->assertSame( array(), $result['problems'] );

		return $result;
	}
}
