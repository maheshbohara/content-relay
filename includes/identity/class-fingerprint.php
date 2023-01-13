<?php
/**
 * Stable content hashes for conflict detection.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * Hashes a normalized value: object keys sorted, lists kept in order.
 */
final class Fingerprint {

	/**
	 * Returns the fingerprint of a value.
	 *
	 * @param mixed $value Normalized object state (ids already replaced by identities).
	 * @return string `sha256:<hex>`.
	 */
	public function of( $value ): string {
		return 'sha256:' . hash( 'sha256', $this->canonical_json( $value ) );
	}

	/**
	 * Returns the canonical JSON a fingerprint is computed from.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public function canonical_json( $value ): string {
		return (string) wp_json_encode( $this->normalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
	}

	/**
	 * Recursively sorts associative keys.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function normalize( $value ) {
		if ( $value instanceof \stdClass ) {
			$value = get_object_vars( $value );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( array() !== $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value, SORT_STRING );
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->normalize( $item );
		}

		return $value;
	}
}
