<?php
/**
 * Finds block delimiters in post content without parsing or re-serializing it.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Byte-exact block delimiter scanner.
 *
 * `serialize_blocks( parse_blocks() )` is not lossless: it drops the attributes of blocks whose JSON does not
 * parse, turns `{}` into `[]` and changes escaping. Content Relay therefore never re-serializes content; it locates
 * delimiters with the same regular expression as WP_Block_Parser and edits byte ranges in place.
 */
final class Block_Scanner {

	/**
	 * Delimiter pattern, identical to WP_Block_Parser::next_token().
	 */
	private const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Scans content and returns every opening or void block delimiter in document order.
	 *
	 * Closers are matched to the most recent open block, as WP_Block_Parser does. A closer with no open block is
	 * ignored; blocks left open at the end extend to the end of the content.
	 *
	 * @param string $content Post content.
	 * @return Block_Token[] Indexed by Block_Token::$index.
	 */
	public function scan( string $content ): array {
		$tokens = array();
		$stack  = array();
		$length = strlen( $content );
		$offset = 0;

		while ( $offset < $length && 1 === preg_match( self::DELIMITER, $content, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
			$start  = (int) $matches[0][1];
			$end    = $start + strlen( $matches[0][0] );
			$offset = $end;

			if ( self::group_matched( $matches, 'closer' ) ) {
				if ( array() !== $stack ) {
					$open                       = (int) array_pop( $stack );
					$tokens[ $open ]->inner_end = $start;
					$tokens[ $open ]->outer_end = $end;
				}
				continue;
			}

			$token             = new Block_Token();
			$token->index      = count( $tokens );
			$token->start      = $start;
			$token->opener_end = $end;
			$token->is_void    = self::group_matched( $matches, 'void' );
			$token->depth      = count( $stack );
			$token->name       = ( self::group_matched( $matches, 'namespace' ) ? $matches['namespace'][0] : 'core/' ) . $matches['name'][0];

			if ( self::group_matched( $matches, 'attrs' ) ) {
				$token->attrs_start  = (int) $matches['attrs'][1];
				$token->attrs_length = strlen( rtrim( $matches['attrs'][0] ) );
			}

			$token->inner_start = $end;
			$token->inner_end   = $end;
			$token->outer_end   = $end;

			if ( array() !== $stack ) {
				$token->parent                        = (int) end( $stack );
				$tokens[ $token->parent ]->children[] = $token->index;
			}

			$tokens[] = $token;

			if ( ! $token->is_void ) {
				$stack[] = $token->index;
			}
		}

		foreach ( $stack as $open ) {
			$tokens[ $open ]->inner_end = $length;
			$tokens[ $open ]->outer_end = $length;
		}

		return $tokens;
	}

	/**
	 * Returns the attributes JSON of a token.
	 *
	 * @param string      $content Content the token was scanned from.
	 * @param Block_Token $token   Token.
	 * @return string Empty string when the block has no attributes.
	 */
	public function attrs_json( string $content, Block_Token $token ): string {
		return $token->has_attrs() ? substr( $content, $token->attrs_start, $token->attrs_length ) : '';
	}

	/**
	 * Whether a named group took part in the match.
	 *
	 * @param array<int|string, array{0: string, 1: int}> $matches preg_match() result with PREG_OFFSET_CAPTURE.
	 * @param string                                      $group   Group name.
	 */
	private static function group_matched( array $matches, string $group ): bool {
		return isset( $matches[ $group ] ) && -1 !== $matches[ $group ][1];
	}
}
