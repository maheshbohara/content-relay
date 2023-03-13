<?php
/**
 * Tests for Page_Cache.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Unit\Apply;

use ContentRelay\Apply\Page_Cache;
use PHPUnit\Framework\TestCase;

/**
 * Page-cache purge dispatch.
 */
final class Page_Cache_Test extends TestCase {

	/**
	 * Active integrations get deduplicated URLs and ids; inactive and malformed ones are skipped; failures are reported.
	 */
	public function test_purge(): void {
		$GLOBALS['content_relay_test_actions'] = array();
		$calls                                 = array();

		$cache = new Page_Cache(
			array(
				'active'    => array(
					'available' => static function (): bool {
						return true;
					},
					'purge'     => static function ( array $urls, array $ids ) use ( &$calls ): void {
						$calls[] = array( $urls, $ids );
					},
				),
				'inactive'  => array(
					'available' => static function (): bool {
						return false;
					},
					'purge'     => static function () use ( &$calls ): void {
						$calls[] = 'inactive';
					},
				),
				'truthy'    => array(
					'available' => static function () {
						return 1;
					},
					'purge'     => static function () use ( &$calls ): void {
						$calls[] = 'truthy';
					},
				),
				'broken'    => array(
					'available' => static function (): bool {
						return true;
					},
					'purge'     => static function (): void {
						throw new \RuntimeException( 'purge endpoint down' );
					},
				),
				'malformed' => array( 'purge' => 'strlen' ),
			)
		);

		$result = $cache->purge( array( 'https://r.test/a/', '', 'https://r.test/a/', 'https://r.test/fr/a/' ), array( 12, '12', 0, 13 ) );

		$this->assertSame( array( 'active' ), $result['purged'] );
		$this->assertSame( array( 'Purging the broken page cache failed: purge endpoint down' ), $result['problems'] );
		$this->assertSame( array( array( array( 'https://r.test/a/', 'https://r.test/fr/a/' ), array( 12, 13 ) ) ), $calls );
		$this->assertSame( 'content_relay_purge_page_cache', $GLOBALS['content_relay_test_actions'][0][0] );
	}
}
