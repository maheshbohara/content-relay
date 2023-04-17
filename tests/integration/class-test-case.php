<?php
/**
 * Base class for integration tests.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Integration;

use ContentRelay\Config;
use ContentRelay\Discovery\Export_Context;
use ContentRelay\Discovery\Exporter;
use ContentRelay\Language\Adapter_Factory;
use ContentRelay\Receive\Bundle;
use ContentRelay\Receive\Receive_Context;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Test fixtures write temporary files.

/**
 * Fixtures for relays on one plain WordPress site: pages, real image attachments, manifests and bundle directories.
 */
abstract class Test_Case extends \WP_UnitTestCase {

	/**
	 * Administrator the relay runs as.
	 *
	 * @var int
	 */
	protected int $admin_id = 0;

	/**
	 * Temporary directories to remove after the test.
	 *
	 * @var string[]
	 */
	private array $temp_dirs = array();

	/**
	 * Logs in an administrator and switches sending and receiving on.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		Config::set( 'send', true );
		Config::set( 'receive', true );
	}

	/**
	 * Removes temporary directories.
	 */
	public function tear_down(): void {
		foreach ( $this->temp_dirs as $dir ) {
			self::remove_dir( $dir );
		}
		$this->temp_dirs = array();

		parent::tear_down();
	}

	/**
	 * A slug no other test uses (data can outlive a test; see the bootstrap).
	 *
	 * @param string $base Base.
	 */
	protected static function unique( string $base ): string {
		return $base . '-' . strtolower( wp_generate_password( 8, false ) );
	}

	/**
	 * Creates a published page.
	 *
	 * @param array<string, mixed> $args wp_insert_post() arguments.
	 */
	protected function page( array $args = array() ): int {
		$title = self::unique( 'Relay page' );

		return (int) self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => $title,
					'post_name'   => sanitize_title( $title ),
				),
				$args
			)
		);
	}

	/**
	 * Creates an image attachment with a real PNG file in uploads.
	 *
	 * @param int    $parent_id Post the image is uploaded to.
	 * @param string $color     Hex colour, so different images have different bytes.
	 */
	protected function image( int $parent_id = 0, string $color = '3366cc' ): int {
		$dir  = $this->temp_dir();
		$file = $dir . '/' . self::unique( 'relay-image' ) . '.png';

		$image = imagecreatetruecolor( 40, 30 );
		imagefill( $image, 0, 0, (int) imagecolorallocate( $image, (int) hexdec( substr( $color, 0, 2 ) ), (int) hexdec( substr( $color, 2, 2 ) ), (int) hexdec( substr( $color, 4, 2 ) ) ) );
		imagepng( $image, $file );
		imagedestroy( $image );

		return (int) self::factory()->attachment->create_upload_object( $file, $parent_id );
	}

	/**
	 * Block markup of a core image block for an attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	protected static function image_block( int $attachment_id ): string {
		$url = (string) wp_get_attachment_url( $attachment_id );

		return sprintf(
			'<!-- wp:image {"id":%1$d,"sizeSlug":"full"} --><figure class="wp-block-image size-full"><img src="%2$s" alt="" class="wp-image-%1$d"/></figure><!-- /wp:image -->',
			$attachment_id,
			esc_url( $url )
		);
	}
}
