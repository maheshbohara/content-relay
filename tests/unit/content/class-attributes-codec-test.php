<?php
/**
 * Tests for Attributes_Codec.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Unit\Content;

use ContentRelay\Content\Attributes_Codec;
use PHPUnit\Framework\TestCase;

/**
 * Attributes_Codec tests.
 */
final class Attributes_Codec_Test extends TestCase {

	/**
	 * Attribute JSON strings that must re-encode byte-for-byte.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function round_trip_provider(): array {
		return array(
			'core style (serialize_block_attributes)' => array( '{"url":"https://x.test/a/b","text":"it’s \\u003cb\\u003e \\u0022q\\u0022 \\u002d\\u002d \\u0026 \\u005c"}' ),
			'seed style (plain json_encode)'          => array( '{"name":"wtcw\\/hero","text":"it\\u2019s \\"q\\""}' ),
			'empty object stays an object'            => array( '{"style":{"color":{}},"list":[]}' ),
			'zero fraction preserved'                 => array( '{"opacity":1.0,"scale":1.5}' ),
			'raw css custom properties'               => array( '{"style":{"color":{"text":"var(--wp--preset--color--x)"}}}' ),
			'nested repeater rows'                    => array( '{"data":{"rows_0_image":4350,"_rows_0_image":"field_a","rows":1}}' ),
		);
	}

	/**
	 * Decoding and re-encoding in the detected style reproduces the original bytes.
	 *
	 * @dataProvider round_trip_provider
	 *
	 * @param string $json Attribute JSON.
	 */
	public function test_round_trip_is_byte_exact( string $json ): void {
		$codec = new Attributes_Codec();
		$attrs = $codec->decode( $json );

		$this->assertNotNull( $attrs );
		$this->assertSame( $json, $codec->encode( $attrs, $codec->detect_style( $json ) ) );
	}

	/**
	 * Invalid JSON and non-object JSON decode to null.
	 */
	public function test_invalid_or_non_object_json_is_rejected(): void {
		$codec = new Attributes_Codec();

		$this->assertNull( $codec->decode( '{"a":1,}' ) );
		$this->assertNull( $codec->decode( '[1,2]' ) );
	}

	/**
	 * A changed value is encoded in the original style.
	 */
	public function test_changed_value_keeps_style(): void {
		$codec = new Attributes_Codec();
		$json  = '{"name":"wtcw\\/hero","data":{"intro":"it\\u2019s","hero_image":4350}}';
		$attrs = $codec->decode( $json );

		$attrs->data->hero_image = 9001;

		$this->assertSame( str_replace( '4350', '9001', $json ), $codec->encode( $attrs, $codec->detect_style( $json ) ) );
	}
}
