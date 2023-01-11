<?php
/**
 * Tests for Value_Path.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Unit\Support;

use ContentRelay\Support\Value_Path;
use PHPUnit\Framework\TestCase;

/**
 * Value_Path tests.
 */
final class Value_Path_Test extends TestCase {

	/**
	 * Decoded attributes shaped like ACF block data.
	 */
	private function attrs(): \stdClass {
		return json_decode( '{"data":{"hero":4350,"ids":["1","2"],"link":{"url":"u"}},"list":[{"a":"x"}]}', false );
	}

	/**
	 * Reads and writes through objects and arrays; missing paths read as null and are not created.
	 */
	public function test_get_and_set(): void {
		$attrs = $this->attrs();

		$this->assertSame( 4350, Value_Path::get( $attrs, array( 'data', 'hero' ) ) );
		$this->assertSame( 'x', Value_Path::get( $attrs, array( 'list', 0, 'a' ) ) );
		$this->assertNull( Value_Path::get( $attrs, array( 'data', 'missing' ) ) );

		Value_Path::set( $attrs, array( 'data', 'hero' ), 9 );
		Value_Path::set( $attrs, array( 'data', 'missing' ), 1 );

		$this->assertSame( 9, $attrs->data->hero );
		$this->assertFalse( property_exists( $attrs->data, 'missing' ) );
		$this->assertSame( 'new', Value_Path::set( 'old', array(), 'new' ) );
	}

	/**
	 * Lists every string leaf with its path.
	 */
	public function test_string_leaves(): void {
		$this->assertSame(
			array(
				array(
					'path'  => array( 'data', 'ids', 0 ),
					'value' => '1',
				),
				array(
					'path'  => array( 'data', 'ids', 1 ),
					'value' => '2',
				),
				array(
					'path'  => array( 'data', 'link', 'url' ),
					'value' => 'u',
				),
				array(
					'path'  => array( 'list', 0, 'a' ),
					'value' => 'x',
				),
			),
			Value_Path::string_leaves( $this->attrs() )
		);
	}

	/**
	 * Only positive ids from ints, digit strings and flat lists count.
	 */
	public function test_ids(): void {
		$this->assertSame( array( 5 ), Value_Path::ids( 5 ) );
		$this->assertSame( array( 12 ), Value_Path::ids( '12' ) );
		$this->assertSame( array(), Value_Path::ids( '0' ) );
		$this->assertSame( array(), Value_Path::ids( 'abc' ) );
		$this->assertSame( array(), Value_Path::ids( '20260903x' ) );
		$this->assertSame( array( 3, 4 ), Value_Path::ids( array( '3', 'x', 4 ) ) );
		$this->assertSame( array(), Value_Path::ids( array( array( 1 ) ) ) );
	}

	/**
	 * Mapping keeps each value's type and leaves unmapped ids alone.
	 */
	public function test_map_ids_preserves_types(): void {
		$map = static function ( int $id ): ?int {
			return 1 === $id ? 10 : null;
		};

		$this->assertSame( 10, Value_Path::map_ids( 1, $map ) );
		$this->assertSame( '10', Value_Path::map_ids( '1', $map ) );
		$this->assertSame( array( '10', 2 ), Value_Path::map_ids( array( '1', 2 ), $map ) );
		$this->assertSame( 'abc', Value_Path::map_ids( 'abc', $map ) );
	}
}
