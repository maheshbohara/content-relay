<?php
/**
 * Navigates decoded attribute and meta values by path.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Path access into nested stdClass/array values, and id extraction that preserves value types.
 */
final class Value_Path {

	/**
	 * Reads the value at a path.
	 *
	 * @param mixed                  $value Root value.
	 * @param array<int, string|int> $path  Keys.
	 * @return mixed Null when the path does not exist.
	 */
	public static function get( $value, array $path ) {
		foreach ( $path as $segment ) {
			if ( $value instanceof \stdClass && property_exists( $value, (string) $segment ) ) {
				$value = $value->{$segment};
			} elseif ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
				$value = $value[ $segment ];
			} else {
				return null;
			}
		}

		return $value;
	}

	/**
	 * Writes a value at an existing path and returns the root. Objects are modified in place.
	 *
	 * @param mixed                  $value Root value.
	 * @param array<int, string|int> $path  Keys.
	 * @param mixed                  $replacement   New value.
	 * @return mixed
	 */
	public static function set( $value, array $path, $replacement ) {
		if ( array() === $path ) {
			return $replacement;
		}

		$segment = array_shift( $path );
		if ( $value instanceof \stdClass && property_exists( $value, (string) $segment ) ) {
			$value->{$segment} = self::set( $value->{$segment}, $path, $replacement );
		} elseif ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
			$value[ $segment ] = self::set( $value[ $segment ], $path, $replacement );
		}

		return $value;
	}
}
