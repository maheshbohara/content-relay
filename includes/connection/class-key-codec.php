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

	/**
	 * Parses a connection key.
	 *
	 * @param string $key Connection key as pasted by an administrator.
	 * @return array{url: string, kid: string, secret_key: string}|null Null when the key is malformed.
	 */
	public function decode( string $key ): ?array {
		$key = trim( $key );
		if ( 0 !== strpos( $key, self::PREFIX ) ) {
			return null;
		}

		$json = Base64_Url::decode( substr( $key, strlen( self::PREFIX ) ) );
		$data = null === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) || 1 !== ( $data['v'] ?? null ) ) {
			return null;
		}

		$url = $data['url'] ?? null;
		$kid = $data['kid'] ?? null;
		$sk  = $data['sk'] ?? null;
		if ( ! is_string( $url ) || ! is_string( $kid ) || ! is_string( $sk ) ) {
			return null;
		}

		$secret_key = Base64_Url::decode( $sk );
		if ( null === $secret_key || self::SECRET_KEY_BYTES !== strlen( $secret_key ) ) {
			return null;
		}

		if ( ! self::is_valid_kid( $kid ) || 1 !== preg_match( '#^https?://[^\s/]+#', $url ) ) {
			return null;
		}

		return array(
			'url'        => rtrim( $url, '/' ),
			'kid'        => $kid,
			'secret_key' => $secret_key,
		);
	}

	/**
	 * Whether a string is a well-formed key id (16 lowercase hex characters).
	 *
	 * @param string $kid Key id.
	 */
	public static function is_valid_kid( string $kid ): bool {
		return 1 === preg_match( '/^[a-f0-9]{16}$/', $kid );
	}
}
