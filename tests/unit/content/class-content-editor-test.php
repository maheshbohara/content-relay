<?php
/**
 * Tests for Content_Editor.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Unit\Content;

use ContentRelay\Content\Content_Editor;
use ContentRelay\Content\Content_Exception;
use PHPUnit\Framework\TestCase;

/**
 * Content_Editor tests.
 */
final class Content_Editor_Test extends TestCase {

	/**
	 * Content with freeform HTML, a group holding a void image block, and a namespaced void block.
	 */
	private const CONTENT = '<p>intro 42</p><!-- wp:group {"layout":{"type":"flex"}} --><div class="g"><!-- wp:image {"id":42} /--><p>x</p></div><!-- /wp:group --><!-- wp:wtcw/hero {"data":{"hero_image":4350}} /-->';

	/**
	 * Only the edited attribute's bytes change.
	 */
	public function test_attribute_edit_changes_only_that_value(): void {
		$result = ( new Content_Editor() )->edit(
			self::CONTENT,
			array(
				2 => static function ( \stdClass $attrs ): \stdClass {
					$attrs->data->hero_image = 9001;
					return $attrs;
				},
			)
		);

		$this->assertSame( str_replace( '4350', '9001', self::CONTENT ), $result );
	}

	/**
	 * Seed-style JSON (escaped slashes and unicode) survives an edit unchanged apart from the value.
	 */
	public function test_attribute_edit_preserves_seed_style(): void {
		$content = '<!-- wp:wtcw/hero {"name":"wtcw\\/hero","data":{"intro":"it\\u2019s \\"new\\"","hero_image":4350}} /-->';
		$result  = ( new Content_Editor() )->edit(
			$content,
			array(
				0 => static function ( \stdClass $attrs ): \stdClass {
					$attrs->data->hero_image = 12;
					return $attrs;
				},
			)
		);

		$this->assertSame( str_replace( '4350', '12', $content ), $result );
	}

	/**
	 * An HTML edit on a parent block never reaches a nested block's delimiter.
	 */
	public function test_html_edit_skips_nested_blocks(): void {
		$replace_42 = static function ( string $html ): string {
			return str_replace( '42', '99', $html );
		};

		$this->assertSame( self::CONTENT, ( new Content_Editor() )->edit( self::CONTENT, array(), array( 0 => $replace_42 ) ) );

		$result = ( new Content_Editor() )->edit(
			self::CONTENT,
			array(),
			array(
				0 => static function ( string $html ): string {
					return str_replace( 'class="g"', 'class="h"', $html );
				},
			)
		);
		$this->assertSame( str_replace( 'class="g"', 'class="h"', self::CONTENT ), $result );
	}

	/**
	 * A freeform edit changes HTML outside blocks only.
	 */
	public function test_freeform_edit_changes_only_html_outside_blocks(): void {
		$result = ( new Content_Editor() )->edit(
			self::CONTENT,
			array(),
			array(
				Content_Editor::FREEFORM => static function ( string $html ): string {
					return str_replace( '42', '99', $html );
				},
			)
		);

		$this->assertSame( str_replace( 'intro 42', 'intro 99', self::CONTENT ), $result );
	}

	/**
	 * Editing a block whose attribute JSON does not parse is refused.
	 */
	public function test_invalid_json_edit_is_refused(): void {
		$this->expectException( Content_Exception::class );

		( new Content_Editor() )->edit(
			'<!-- wp:wtcw/broken {"a":1,} /-->',
			array(
				0 => static function ( \stdClass $attrs ): \stdClass {
					return $attrs;
				},
			)
		);
	}

	/**
	 * Editing a block that does not exist is refused.
	 */
	public function test_missing_block_is_refused(): void {
		$this->expectException( Content_Exception::class );

		( new Content_Editor() )->edit(
			self::CONTENT,
			array(
				9 => static function ( \stdClass $attrs ): \stdClass {
					return $attrs;
				},
			)
		);
	}
}
