<?php
/**
 * Applies reference rewrites to post content by splicing byte ranges.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Edits block attributes and block HTML in place, leaving every other byte untouched.
 */
final class Content_Editor {

	/**
	 * Index used in HTML edits for content outside every block (freeform/classic content).
	 */
	public const FREEFORM = -1;

	/**
	 * Delimiter scanner.
	 *
	 * @var Block_Scanner
	 */
	private Block_Scanner $scanner;

	/**
	 * Attribute codec.
	 *
	 * @var Attributes_Codec
	 */
	private Attributes_Codec $codec;

	/**
	 * Constructor.
	 *
	 * @param Block_Scanner|null    $scanner Scanner.
	 * @param Attributes_Codec|null $codec   Codec.
	 */
	public function __construct( ?Block_Scanner $scanner = null, ?Attributes_Codec $codec = null ) {
		$this->scanner = null === $scanner ? new Block_Scanner() : $scanner;
		$this->codec   = null === $codec ? new Attributes_Codec() : $codec;
	}
}
