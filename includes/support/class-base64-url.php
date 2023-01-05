<?php
/**
 * URL-safe base64 for binary key material and signatures.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Support;

defined( 'ABSPATH' ) || exit;

/**
 * RFC 4648 §5 base64url without padding.
 */
final class Base64_Url {

	/**
	 * Encodes bytes.
	 *
	 * @param string $bytes Binary string.
	 * @return string
	 */
	public static function encode( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Transport encoding of signatures and key material, not obfuscation.
	}

	/**
	 * Decodes text; strict.
	 *
	 * @param string $text base64url text.
	 * @return string|null Null when the text is not valid base64url.
	 */
	public static function decode( string $text ): ?string {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]*$/', $text ) ) {
			return null;
		}

		$remainder = strlen( $text ) % 4;
		if ( 0 !== $remainder ) {
			$text .= str_repeat( '=', 4 - $remainder );
		}

		$bytes = base64_decode( strtr( $text, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Transport encoding of signatures and key material, not obfuscation.

		return false === $bytes ? null : $bytes;
	}
}
