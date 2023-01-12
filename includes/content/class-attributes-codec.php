<?php
/**
 * Decodes and re-encodes block attribute JSON in the style the content already uses.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Attribute JSON codec.
 *
 * Content on a site is written in more than one JSON style: core's serialize_block_attributes() (unescaped
 * slashes and unicode, `--` `<` `>` `&` `"` `\` as \u escapes) and plain PHP json_encode() from seed scripts
 * (`\/`, `’`, raw `\"`). Re-encoding in the wrong style would change bytes the relay did not mean to touch,
 * so the style is detected from the original JSON and reproduced.
 */
final class Attributes_Codec {

	/**
	 * Core's delimiter-safety replacements, applied after json_encode (serialize_block_attributes()).
	 */
	private const SAFETY_ESCAPES = array(
		'\\\\' => '\\u005c',
		'--'   => '\\u002d\\u002d',
		'<'    => '\\u003c',
		'>'    => '\\u003e',
		'&'    => '\\u0026',
		'\\"'  => '\\u0022',
	);

	/**
	 * Decodes attribute JSON keeping objects as objects, so `{}` is not turned into `[]`.
	 *
	 * @param string $json Attribute JSON.
	 * @return \stdClass|null Null when the JSON is not a valid object.
	 */
	public function decode( string $json ): ?\stdClass {
		$value = json_decode( $json, false, 512, JSON_BIGINT_AS_STRING );

		return ( JSON_ERROR_NONE === json_last_error() && $value instanceof \stdClass ) ? $value : null;
	}

	/**
	 * Detects how an attribute JSON string was encoded.
	 *
	 * @param string $json Original attribute JSON.
	 * @return array{flags: int, escapes: array<string, string>}
	 */
	public function detect_style( string $json ): array {
		$flags = 0;

		if ( false === strpos( $json, '\\/' ) ) {
			$flags |= JSON_UNESCAPED_SLASHES;
		}

		// A \uXXXX escape that is not one of core's safety escapes means non-ASCII text was escaped.
		if ( 1 !== preg_match( '/(?<!\\\\)(?:\\\\\\\\)*\\\\u(?!005c|002d|003c|003e|0026|0022)[0-9a-fA-F]{4}/', $json ) ) {
			$flags |= JSON_UNESCAPED_UNICODE;
		}

		if ( 1 === preg_match( '/[0-9]\.0+(?=[,}\]\s])/', $json ) ) {
			$flags |= JSON_PRESERVE_ZERO_FRACTION;
		}

		$escapes = array();
		foreach ( self::SAFETY_ESCAPES as $raw => $escaped ) {
			// Keep a character raw when the original has it raw; otherwise use core's escape.
			if ( false === strpos( $json, $raw ) ) {
				$escapes[ $raw ] = $escaped;
			}
		}

		return array(
			'flags'   => $flags,
			'escapes' => $escapes,
		);
	}

	/**
	 * Encodes attributes in a detected style.
	 *
	 * @param \stdClass                                         $attrs Attributes.
	 * @param array{flags: int, escapes: array<string, string>} $style Result of detect_style().
	 * @return string|null Null when the value cannot be encoded.
	 */
	public function encode( \stdClass $attrs, array $style ): ?string {
		$json = wp_json_encode( $attrs, $style['flags'] );
		if ( false === $json ) {
			return null;
		}

		return array() === $style['escapes'] ? $json : strtr( $json, $style['escapes'] );
	}

	/**
	 * Whether two decoded attribute objects hold the same data (key order included).
	 *
	 * @param \stdClass $a First value.
	 * @param \stdClass $b Second value.
	 */
	public function same( \stdClass $a, \stdClass $b ): bool {
		return wp_json_encode( $a, JSON_PRESERVE_ZERO_FRACTION ) === wp_json_encode( $b, JSON_PRESERVE_ZERO_FRACTION );
	}
}
