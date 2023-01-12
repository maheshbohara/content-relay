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

	/**
	 * Indexes of directly nested blocks.
	 *
	 * @var int[]
	 */
	public array $children = array();

	/**
	 * Whether the delimiter is self-closing (`<!-- wp:name /-->`).
	 *
	 * @var bool
	 */
	public bool $is_void = false;

	/**
	 * Offset where the opening delimiter starts.
	 *
	 * @var int
	 */
	public int $start = 0;

	/**
	 * Offset just after the opening delimiter.
	 *
	 * @var int
	 */
	public int $opener_end = 0;

	/**
	 * Offset where the attributes JSON starts, or -1 when the block has none.
	 *
	 * @var int
	 */
	public int $attrs_start = -1;

	/**
	 * Length of the attributes JSON, without the whitespace that follows it.
	 *
	 * @var int
	 */
	public int $attrs_length = 0;
}
