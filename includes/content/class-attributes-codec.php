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
}
