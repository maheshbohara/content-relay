<?php
/**
 * Tests for Fingerprint.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Unit\Identity;

use ContentRelay\Identity\Fingerprint;
use PHPUnit\Framework\TestCase;

/**
 * Fingerprint tests.
 */
final class Fingerprint_Test extends TestCase {

	/**
	 * Key order does not change a fingerprint; list order and values do.
	 */
	public function test_key_order_is_ignored_but_list_order_is_not(): void {
		$fingerprint = new Fingerprint();

		$a = $fingerprint->of(
			array(
				'title' => 'Vietnam',
				'meta'  => array(
					'b' => array( 1, 2 ),
					'a' => 'x',
				),
			)
		);
		$b = $fingerprint->of(
			(object) array(
				'meta'  => (object) array(
					'a' => 'x',
					'b' => array( 1, 2 ),
				),
				'title' => 'Vietnam',
			)
		);

		$this->assertSame( $a, $b );
		$this->assertStringStartsWith( 'sha256:', $a );
		$this->assertNotSame(
			$a,
			$fingerprint->of(
				array(
					'title' => 'Vietnam',
					'meta'  => array(
						'a' => 'x',
						'b' => array( 2, 1 ),
					),
				)
			)
		);
		$this->assertNotSame( $fingerprint->of( array( 'n' => 1 ) ), $fingerprint->of( array( 'n' => '1' ) ) );
	}
}
