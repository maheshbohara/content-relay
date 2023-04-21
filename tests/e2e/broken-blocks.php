<?php
/**
 * E2E scenario 15 helper: fingerprints the blocks whose attribute JSON does not parse. Read-only.
 *
 * Usage: wp eval-file …/tests/e2e/broken-blocks.php <post-id> [<post-id>…]
 * Prints, per post, the md5 of post_content and of each unparseable block's full bytes (opener to closer), so the
 * sender and receiver outputs can be diffed.
 *
 * @package ContentRelay
 */

// phpcs:ignoreFile -- Test harness, not plugin code.

$scanner = new \ContentRelay\Content\Block_Scanner();
$codec   = new \ContentRelay\Content\Attributes_Codec();

foreach ( $args as $id ) {
	$content = (string) get_post_field( 'post_content', (int) $id, 'raw' );
	$broken  = array();
	foreach ( $scanner->scan( $content ) as $token ) {
		if ( $token->has_attrs() && null === $codec->decode( $scanner->attrs_json( $content, $token ) ) ) {
			$broken[] = sprintf( '%s#%d:%s', $token->name, $token->index, md5( substr( $content, $token->start, $token->outer_end - $token->start ) ) );
		}
	}
	echo sprintf( "post %d content=%s broken=%d %s\n", (int) $id, md5( $content ), count( $broken ), implode( ' ', $broken ) );
}
