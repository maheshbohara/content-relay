<?php
/**
 * Tests for Bundle.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Unit\Receive;

use ContentRelay\Receive\Bundle;
use PHPUnit\Framework\TestCase;

/**
 * Bundle tests.
 */
final class Bundle_Test extends TestCase {

	/**
	 * References the sender could not resolve map to 0; malformed keys and relayed objects are ignored.
	 */
	public function test_absent_ids_map_to_zero(): void {
		$bundle = new Bundle(
			array(
				'objects' => array( 'post:5' => array() ),
				'absent'  => array( 'post:18005', 'term:9', 'user:3', 'post:5', 'post:x', 'file:1', 7 ),
			),
			'/tmp'
		);

		$this->assertSame(
			array(
				'post:18005' => 0,
				'term:9'     => 0,
				'user:3'     => 0,
			),
			$bundle->absent_ids()
		);
	}

	/**
	 * A manifest from a sender without the list yields nothing.
	 */
	public function test_absent_ids_default_empty(): void {
		$this->assertSame( array(), ( new Bundle( array( 'objects' => array() ), '/tmp' ) )->absent_ids() );
	}
}
