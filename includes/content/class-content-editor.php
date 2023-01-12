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

	/**
	 * Applies edits and returns the new content.
	 *
	 * Attribute edits receive a decoded copy of the block's attributes and return the edited object. HTML edits
	 * receive each segment of the block's *own* HTML — inner content with nested blocks cut out — so a nested block
	 * is never edited through its parent. Every attribute edit is verified by decoding the new JSON again.
	 *
	 * @param string                                     $content         Post content.
	 * @param array<int, callable(\stdClass): \stdClass> $attribute_edits Block index => mutator.
	 * @param array<int, callable(string): string>       $html_edits      Block index (or FREEFORM) => mutator.
	 * @return string
	 *
	 * @throws Content_Exception When a block is missing, its JSON does not decode, or the result does not verify.
	 */
	public function edit( string $content, array $attribute_edits, array $html_edits = array() ): string {
		$tokens  = $this->scanner->scan( $content );
		$splices = array();

		foreach ( $attribute_edits as $index => $mutator ) {
			$token = $tokens[ $index ] ?? null;
			if ( null === $token || ! $token->has_attrs() ) {
				throw new Content_Exception( sprintf( 'Block %d has no attributes to edit.', (int) $index ) );
			}

			$json  = $this->scanner->attrs_json( $content, $token );
			$attrs = $this->codec->decode( $json );
			if ( null === $attrs ) {
				throw new Content_Exception( sprintf( 'Attributes of block %d (%s) are not valid JSON.', (int) $index, esc_html( $token->name ) ) );
			}

			$edited   = $mutator( $attrs );
			$new_json = $this->codec->encode( $edited, $this->codec->detect_style( $json ) );
			$check    = null === $new_json ? null : $this->codec->decode( $new_json );
			if ( null === $check || ! $this->codec->same( $check, $edited ) ) {
				throw new Content_Exception( sprintf( 'Edited attributes of block %d (%s) did not verify.', (int) $index, esc_html( $token->name ) ) );
			}

			if ( $new_json !== $json ) {
				$splices[] = array( $token->attrs_start, $token->attrs_length, (string) $new_json );
			}
		}

		foreach ( $html_edits as $index => $mutator ) {
			foreach ( $this->own_html_segments( $tokens, (int) $index, strlen( $content ) ) as $segment ) {
				$html     = substr( $content, $segment[0], $segment[1] - $segment[0] );
				$new_html = $mutator( $html );
				if ( $new_html !== $html ) {
					$splices[] = array( $segment[0], $segment[1] - $segment[0], $new_html );
				}
			}
		}

		return $this->apply_splices( $content, $splices );
	}
}
