<?php
/**
 * Tests for Url_Map.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Unit\Apply;

use ContentRelay\Apply\Url_Map;
use PHPUnit\Framework\TestCase;

/**
 * Url_Map tests.
 */
final class Url_Map_Test extends TestCase {

	/**
	 * Builds a map from src.test to dst.test.
	 */
	private function map(): Url_Map {
		return new Url_Map( 'https://src.test', 'https://dst.test/', 'https://src.test/wp-content/uploads', 'https://dst.test/wp-content/uploads' );
	}

	/**
	 * Sender URLs become receiver URLs; look-alike hosts are left alone.
	 */
	public function test_rewrites_sender_host_only(): void {
		$in  = 'Links: https://src.test/about/ http://src.test:8080/x "https://src.test" https://src.test.evil/y https://notsrc.test/z';
		$out = 'Links: https://dst.test/about/ https://dst.test/x "https://dst.test" https://src.test.evil/y https://notsrc.test/z';

		$this->assertSame( $out, $this->map()->rewrite( $in ) );
	}

	/**
	 * A renamed upload is rewritten with its sub-sizes and scaled copy, but not a different file sharing the prefix.
	 */
	public function test_renamed_upload_and_its_variants(): void {
		$map = $this->map();
		$map->add_rename( '2026/09/hero.jpg', '2026/09/hero-1.jpg' );

		$in  = '<img src="https://src.test/wp-content/uploads/2026/09/hero-300x200.jpg"> <a href="https://src.test/wp-content/uploads/2026/09/hero-banner.jpg">b</a> https://src.test/wp-content/uploads/2026/09/hero-scaled.jpg https://src.test/wp-content/uploads/2026/09/hero.jpg';
		$out = '<img src="https://dst.test/wp-content/uploads/2026/09/hero-1-300x200.jpg"> <a href="https://dst.test/wp-content/uploads/2026/09/hero-banner.jpg">b</a> https://dst.test/wp-content/uploads/2026/09/hero-1-scaled.jpg https://dst.test/wp-content/uploads/2026/09/hero-1.jpg';

		$this->assertSame( $out, $map->rewrite( $in ) );
	}

	/**
	 * Text without the sender host is returned unchanged.
	 */
	public function test_text_without_sender_host_is_unchanged(): void {
		$this->assertSame( 'Nothing /about/ here', $this->map()->rewrite( 'Nothing /about/ here' ) );
	}
}
