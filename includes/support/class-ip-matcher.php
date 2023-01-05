<?php
/**
 * Matches an IP address against addresses and CIDR ranges.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Support;

defined( 'ABSPATH' ) || exit;

/**
 * IPv4 and IPv6 allowlist matching.
 */
final class Ip_Matcher {

	/**
	 * Whether an IP is covered by any entry of a list.
	 *
	 * @param string   $ip   Client IP.
	 * @param string[] $ranges Addresses (`203.0.113.7`) or ranges (`203.0.113.0/24`, `2001:db8::/32`).
	 */
	public static function matches( string $ip, array $ranges ): bool {
		$address = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton() warns on malformed input; false is handled.
		if ( false === $address ) {
			return false;
		}

		foreach ( $ranges as $entry ) {
			if ( self::entry_matches( $address, trim( $entry ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a packed address falls inside one entry.
	 *
	 * @param string $address Packed address (inet_pton).
	 * @param string $entry   Address or CIDR range.
	 */
	private static function entry_matches( string $address, string $entry ): bool {
		$parts  = explode( '/', $entry, 2 );
		$subnet = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton() warns on malformed input; false is handled.
		if ( false === $subnet || strlen( $subnet ) !== strlen( $address ) ) {
			return false;
		}

		$max  = strlen( $address ) * 8;
		$bits = $max;
		if ( isset( $parts[1] ) ) {
			if ( 1 !== preg_match( '/^\d{1,3}$/', $parts[1] ) || (int) $parts[1] > $max ) {
				return false;
			}
			$bits = (int) $parts[1];
		}

		$bytes = intdiv( $bits, 8 );
		if ( substr( $address, 0, $bytes ) !== substr( $subnet, 0, $bytes ) ) {
			return false;
		}

		$remainder = $bits % 8;
		if ( 0 === $remainder ) {
			return true;
		}

		$mask = ( 0xff << ( 8 - $remainder ) ) & 0xff;

		return ( ord( $address[ $bytes ] ) & $mask ) === ( ord( $subnet[ $bytes ] ) & $mask );
	}
}
