<?php
/**
 * Encodes and decodes the connection key an administrator copies from receiver to sender.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Connection;

use ContentRelay\Support\Base64_Url;

defined( 'ABSPATH' ) || exit;

/**
 * Connection key format: `contentrelay1.` + base64url( {"v":1,"url":…,"kid":…,"sk":base64url(secret key)} ).
 */
final class Key_Codec {

	/**
	 * Prefix that identifies the key format version.
	 */
	public const PREFIX = 'contentrelay1.';

	/**
	 * Length of an Ed25519 secret key in bytes.
	 */
	private const SECRET_KEY_BYTES = 64;

	/**
	 * Builds a connection key.
	 *
	 * @param string $url        Receiver home URL.
	 * @param string $kid        Key id.
	 * @param string $secret_key Ed25519 secret key (binary).
	 * @return string
	 */
	public function encode( string $url, string $kid, string $secret_key ): string {
		$payload = wp_json_encode(
			array(
				'v'   => 1,
				'url' => $url,
				'kid' => $kid,
				'sk'  => Base64_Url::encode( $secret_key ),
			),
			JSON_UNESCAPED_SLASHES
		);

		return self::PREFIX . Base64_Url::encode( (string) $payload );
	}
}
