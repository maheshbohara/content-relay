<?php
/**
 * Tests for State_Fingerprint.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Unit\Identity;

use ContentRelay\Identity\State_Fingerprint;
use PHPUnit\Framework\TestCase;

/**
 * State_Fingerprint tests.
 */
final class State_Fingerprint_Test extends TestCase {

	/**
	 * Every referenced key is collected once, sorted.
	 */
	public function test_ref_keys_collects_every_reference(): void {
		$record = array(
			'locations' => array(
				array( 'refs' => array( 'post:2', 'post:1' ) ),
				array( 'refs' => array() ),
				array( 'refs' => array( 'term:9' ) ),
			),
			'parent'    => 'post:1',
			'author'    => 'user:3',
			'terms'     => array( 'market-profile' => array( 'term:9', 'term:8' ) ),
		);

		$this->assertSame( array( 'post:1', 'post:2', 'term:8', 'term:9', 'user:3' ), State_Fingerprint::ref_keys( $record ) );
	}

	/**
	 * An object without references has none.
	 */
	public function test_ref_keys_of_object_without_references(): void {
		$this->assertSame(
			array(),
			State_Fingerprint::ref_keys(
				array(
					'parent' => null,
					'author' => null,
				)
			)
		);
	}
}
