<?php
/**
 * Tests for Block_Scanner.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Unit\Content;

use ContentRelay\Content\Block_Scanner;
use PHPUnit\Framework\TestCase;

/**
 * Block_Scanner tests.
 */
final class Block_Scanner_Test extends TestCase {

	/**
	 * Content with freeform HTML, a group holding a void image block, and a namespaced void block.
	 */
	private const CONTENT = '<p>intro</p><!-- wp:group {"layout":{"type":"flex"}} --><div class="g"><!-- wp:image {"id":42} /--><p>x</p></div><!-- /wp:group --><!-- wp:wtcw/hero {"data":{"hero_image":4350}} /-->';

	/**
	 * Finds nested and void blocks in document order with correct names, depth and parents.
	 */
	public function test_scans_nested_and_void_blocks(): void {
		$tokens = ( new Block_Scanner() )->scan( self::CONTENT );

		$this->assertCount( 3, $tokens );
		$this->assertSame( array( 'core/group', 'core/image', 'wtcw/hero' ), array_map( static fn( $t ) => $t->name, $tokens ) );
		$this->assertSame( array( 0, 1, 0 ), array_map( static fn( $t ) => $t->depth, $tokens ) );
		$this->assertSame( array( 1 ), $tokens[0]->children );
		$this->assertSame( 0, $tokens[1]->parent );
		$this->assertNull( $tokens[2]->parent );
		$this->assertTrue( $tokens[1]->is_void );
		$this->assertFalse( $tokens[0]->is_void );
	}

	/**
	 * Attribute JSON ranges are exact and exclude the whitespace before the delimiter end.
	 */
	public function test_attribute_ranges_are_exact(): void {
		$scanner = new Block_Scanner();
		$tokens  = $scanner->scan( self::CONTENT );

		$this->assertSame( '{"layout":{"type":"flex"}}', $scanner->attrs_json( self::CONTENT, $tokens[0] ) );
		$this->assertSame( '{"id":42}', $scanner->attrs_json( self::CONTENT, $tokens[1] ) );
		$this->assertSame( '{"data":{"hero_image":4350}}', $scanner->attrs_json( self::CONTENT, $tokens[2] ) );
	}

	/**
	 * Inner and outer ranges of a block with a closer are exact.
	 */
	public function test_inner_and_outer_ranges(): void {
		$tokens = ( new Block_Scanner() )->scan( self::CONTENT );
		$group  = $tokens[0];

		$this->assertSame( '<div class="g"><!-- wp:image {"id":42} /--><p>x</p></div>', substr( self::CONTENT, $group->inner_start, $group->inner_end - $group->inner_start ) );
		$this->assertSame( '<!-- /wp:group -->', substr( self::CONTENT, $group->inner_end, $group->outer_end - $group->inner_end ) );
	}

	/**
	 * A block without attributes reports none.
	 */
	public function test_block_without_attributes(): void {
		$tokens = ( new Block_Scanner() )->scan( '<!-- wp:separator /-->' );

		$this->assertFalse( $tokens[0]->has_attrs() );
		$this->assertSame( 'core/separator', $tokens[0]->name );
	}

	/**
	 * An unclosed block extends to the end; a stray closer is ignored.
	 */
	public function test_unclosed_block_and_stray_closer(): void {
		$content = '<!-- /wp:group --><!-- wp:group --><p>a</p>';
		$tokens  = ( new Block_Scanner() )->scan( $content );

		$this->assertCount( 1, $tokens );
		$this->assertSame( strlen( $content ), $tokens[0]->inner_end );
		$this->assertSame( strlen( $content ), $tokens[0]->outer_end );
	}
}
