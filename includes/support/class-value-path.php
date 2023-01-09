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

	/**
	 * Lists every string leaf with its path.
	 *
	 * @param mixed                  $value  Root value.
	 * @param array<int, string|int> $prefix Path of $value.
	 * @return array<int, array{path: array<int, string|int>, value: string}>
	 */
	public static function string_leaves( $value, array $prefix = array() ): array {
		if ( is_string( $value ) ) {
			return array(
				array(
					'path'  => $prefix,
					'value' => $value,
				),
			);
		}

		if ( $value instanceof \stdClass ) {
			$value = get_object_vars( $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$leaves = array();
		foreach ( $value as $key => $item ) {
			$path   = $prefix;
			$path[] = $key;
			foreach ( self::string_leaves( $item, $path ) as $leaf ) {
				$leaves[] = $leaf;
			}
		}

		return $leaves;
	}

	/**
	 * Extracts positive integer ids from an id, a numeric string, or a flat list of either.
	 *
	 * @param mixed $value Value.
	 * @return int[]
	 */
	public static function ids( $value ): array {
		if ( is_int( $value ) ) {
			return $value > 0 ? array( $value ) : array();
		}

		if ( is_string( $value ) ) {
			return ( '' !== $value && ctype_digit( $value ) && (int) $value > 0 ) ? array( (int) $value ) : array();
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array();
		foreach ( $value as $item ) {
			if ( is_int( $item ) || is_string( $item ) ) {
				foreach ( self::ids( $item ) as $id ) {
					$ids[] = $id;
				}
			}
		}

		return $ids;
	}

	/**
	 * Replaces ids with mapped ids, keeping each value's type (int stays int, numeric string stays string).
	 *
	 * @param mixed               $value Value shaped as accepted by ids().
	 * @param callable(int): ?int $map   Maps a source id to a target id; null leaves the value unchanged.
	 * @return mixed
	 */
	public static function map_ids( $value, callable $map ) {
		if ( is_int( $value ) && $value > 0 ) {
			$mapped = $map( $value );
			return null === $mapped ? $value : $mapped;
		}

		if ( is_string( $value ) && '' !== $value && ctype_digit( $value ) && (int) $value > 0 ) {
			$mapped = $map( (int) $value );
			return null === $mapped ? $value : (string) $mapped;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( is_int( $item ) || is_string( $item ) ) {
					$value[ $key ] = self::map_ids( $item, $map );
				}
			}
		}

		return $value;
	}
}
