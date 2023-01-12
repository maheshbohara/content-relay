<?php
/**
 * One block delimiter found in post content, with exact byte offsets.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Value object produced by Block_Scanner. All offsets are byte offsets into the scanned string.
 */
final class Block_Token {

	/**
	 * Position of this block among all opening/void delimiters, in document order.
	 *
	 * @var int
	 */
	public int $index = 0;

	/**
	 * Fully qualified block name (`core/` is added when the delimiter has no namespace).
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * Nesting depth; 0 for top-level blocks.
	 *
	 * @var int
	 */
	public int $depth = 0;

	/**
	 * Index of the enclosing block, or null at the top level.
	 *
	 * @var int|null
	 */
	public ?int $parent = null;
}
