<?php
/**
 * E2E spike (M0): proves Block_Scanner + Attributes_Codec + Content_Editor are byte-exact on every block post.
 *
 * Usage (read-only, any site with the plugin): wp eval-file tests/e2e/spike-roundtrip.php
 * Expected on wtcwinnipeg.test (2026-09-14): 1548 posts, reencode_differs 0, noop_edit_differs 0,
 * real_edit_bad 0, invalid_json 5 (posts 36, 37, 1847, 1849 hold broken block JSON).
 *
 * @package ContentRelay
 */

// phpcs:ignoreFile -- Test harness, not plugin code.

use ContentRelay\Content\Attributes_Codec;
use ContentRelay\Content\Block_Scanner;
use ContentRelay\Content\Content_Editor;

global $wpdb;
$rows    = $wpdb->get_results( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE '%<!-- wp:%'" );
$scanner = new Block_Scanner();
$codec   = new Attributes_Codec();
$editor  = new Content_Editor();

$count_blocks = function ( array $blocks ) use ( &$count_blocks ) {
	$n = 0;
	foreach ( $blocks as $b ) {
		$n += ( null !== $b['blockName'] ? 1 : 0 ) + $count_blocks( $b['innerBlocks'] );
	}
	return $n;
};

$s       = array_fill_keys( array( 'posts', 'tokens', 'count_mismatch', 'attrs', 'invalid_json', 'reencode_identical', 'reencode_differs', 'noop_edit_identical', 'noop_edit_differs', 'edit_exceptions', 'real_edit_ok', 'real_edit_bad' ), 0 );
$samples = array();

foreach ( $rows as $row ) {
	$c = $row->post_content;
	++$s['posts'];
	$tokens       = $scanner->scan( $c );
	$s['tokens'] += count( $tokens );
	if ( count( $tokens ) !== $count_blocks( parse_blocks( $c ) ) ) {
		++$s['count_mismatch'];
		$samples['count'][] = $row->ID;
	}

	$attr_edits = array();
	$html_edits = array();
	foreach ( $tokens as $t ) {
		$html_edits[ $t->index ] = static function ( string $h ): string { return $h; };
		if ( ! $t->has_attrs() ) {
			continue;
		}
		++$s['attrs'];
		$json  = $scanner->attrs_json( $c, $t );
		$attrs = $codec->decode( $json );
		if ( null === $attrs ) {
			++$s['invalid_json'];
			$samples['invalid'][] = "{$row->ID}#{$t->index} {$t->name}";
			continue;
		}
		$again = $codec->encode( $attrs, $codec->detect_style( $json ) );
		if ( $again === $json ) {
			++$s['reencode_identical'];
		} else {
			++$s['reencode_differs'];
			$samples['differs'][] = "{$row->ID}#{$t->index} {$t->name}";
		}
		$attr_edits[ $t->index ] = static function ( \stdClass $a ): \stdClass { return $a; };
	}

	try {
		$out = $editor->edit( $c, $attr_edits, $html_edits );
		++$s[ $out === $c ? 'noop_edit_identical' : 'noop_edit_differs' ];
	} catch ( \Throwable $e ) {
		++$s['edit_exceptions'];
		$samples['exceptions'][] = $row->ID . ' ' . $e->getMessage();
	}

	foreach ( $tokens as $t ) {
		$json  = $scanner->attrs_json( $c, $t );
		$attrs = '' === $json ? null : $codec->decode( $json );
		if ( null === $attrs || ! isset( $attrs->data ) || ! is_object( $attrs->data ) ) {
			continue;
		}
		$key = null;
		foreach ( get_object_vars( $attrs->data ) as $k => $v ) {
			if ( is_int( $v ) && $v > 100 ) {
				$key = $k;
				break;
			}
		}
		if ( null === $key ) {
			continue;
		}
		$old = $attrs->data->$key;
		$out = $editor->edit( $c, array( $t->index => static function ( \stdClass $a ) use ( $key ): \stdClass { $a->data->$key = 987654; return $a; } ) );
		$ok  = strlen( $out ) === strlen( $c ) - strlen( (string) $old ) + 6 && false !== strpos( $out, '"' . $key . '":987654' );
		++$s[ $ok ? 'real_edit_ok' : 'real_edit_bad' ];
		break;
	}
}

print_r( $s );
foreach ( $samples as $k => $list ) {
	echo "--- $k (" . count( $list ) . "):\n  " . implode( "\n  ", array_slice( $list, 0, 10 ) ) . "\n";
}
