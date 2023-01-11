<?php
/**
 * Tests for Line_Diff.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Unit\Support;

use ContentRelay\Support\Line_Diff;
use PHPUnit\Framework\TestCase;

/**
 * Review-screen diff tests.
 */
final class Line_Diff_Test extends TestCase {

	/**
	 * Identical texts have no hunks.
	 */
	public function test_identical(): void {
		$diff = Line_Diff::diff( "a\nb", "a\nb" );

		$this->assertFalse( $diff['changed'] );
		$this->assertSame( array(), $diff['hunks'] );
	}

	/**
	 * One changed line in the middle of a long text: one hunk with two context lines either side.
	 */
	public function test_single_change_with_context(): void {
		$old = implode( "\n", range( 1, 20 ) );
		$new = str_replace( "\n10\n", "\nten\n", $old );

		$diff = Line_Diff::diff( $old, $new );

		$this->assertTrue( $diff['changed'] );
		$this->assertSame( 1, $diff['added'] );
		$this->assertSame( 1, $diff['removed'] );
		$this->assertCount( 1, $diff['hunks'] );
		$this->assertSame( 8, $diff['hunks'][0]['old_start'] );
		$this->assertSame(
			array( array( ' ', '8' ), array( ' ', '9' ), array( '-', '10' ), array( '+', 'ten' ), array( ' ', '11' ), array( ' ', '12' ) ),
			$diff['hunks'][0]['lines']
		);
	}

	/**
	 * Distant changes produce separate hunks; insertions keep line numbers straight.
	 */
	public function test_separate_hunks_and_insertion(): void {
		$old = implode( "\n", range( 1, 20 ) );
		$new = str_replace( array( "1\n2\n", "\n19\n" ), array( "1\ninserted\n2\n", "\nnineteen\n" ), $old );

		$diff = Line_Diff::diff( $old, $new );

		$this->assertCount( 2, $diff['hunks'] );
		$this->assertSame( array( '+', 'inserted' ), $diff['hunks'][0]['lines'][1] );
		$this->assertSame( 17, $diff['hunks'][1]['old_start'] );
		$this->assertSame( 18, $diff['hunks'][1]['new_start'] );
	}
}
