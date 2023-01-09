<?php
/**
 * Tests for Html_References.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Unit\Support;

use ContentRelay\Support\Html_References;
use PHPUnit\Framework\TestCase;

/**
 * Html_References tests.
 */
final class Html_References_Test extends TestCase {

	/**
	 * HTML with image classes, a gallery shortcode and a class that only looks like an image class.
	 */
	private const HTML = '<img class="wp-image-12 size-full"><img class="wp-image-7"><span class="wp-image-12x"></span>[gallery ids="3, 4,5"]';

	/**
	 * Finds ids in image classes and gallery shortcodes, once each.
	 */
	public function test_finds_attachment_ids(): void {
		$this->assertSame( array( 12, 7, 3, 4, 5 ), Html_References::attachment_ids( self::HTML ) );
	}

	/**
	 * Rewrites mapped ids and leaves unmapped ones.
	 */
	public function test_replaces_mapped_ids_only(): void {
		$map = static function ( int $id ): ?int {
			$targets = array(
				12 => 120,
				3  => 30,
			);
			return $targets[ $id ] ?? null;
		};

		$this->assertSame(
			'<img class="wp-image-120 size-full"><img class="wp-image-7"><span class="wp-image-12x"></span>[gallery ids="30, 4,5"]',
			Html_References::replace_ids( self::HTML, $map )
		);
	}
}
