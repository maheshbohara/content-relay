<?php
/**
 * Switches that grant the ability to send or receive, and the wp-config.php settings around them.
 *
 * @package ContentRelay
 */

namespace ContentRelay;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the send/receive switches and the CONTENT_RELAY_* constants (spec §5.1).
 *
 * Sending and receiving are switched on and off under Settings → Content Relay and stored in an option. A
 * CONTENT_RELAY_SEND or CONTENT_RELAY_RECEIVE constant, when defined, overrides the option and locks that switch, so a host
 * can still pin either one in wp-config.php.
 */
final class Config {

	/**
	 * Option holding the dashboard switches.
	 */
	public const OPTION = 'content_relay_switches';

	/**
	 * Switch names and the constant that locks each one.
	 */
	public const SWITCHES = array(
		'send'    => 'CONTENT_RELAY_SEND',
		'receive' => 'CONTENT_RELAY_RECEIVE',
	);

	/**
	 * Whether this site registers the receiving REST routes.
	 */
	public static function can_receive(): bool {
		return self::is_on( 'receive' );
	}

	/**
	 * Whether this site may push to connections.
	 */
	public static function can_send(): bool {
		return self::is_on( 'send' );
	}

	/**
	 * Whether a switch is on: its constant when defined, otherwise the dashboard option.
	 *
	 * @param string $name `send` or `receive`.
	 */
	public static function is_on( string $name ): bool {
		if ( ! isset( self::SWITCHES[ $name ] ) ) {
			return false;
		}
		if ( self::is_locked( $name ) ) {
			return true === constant( self::SWITCHES[ $name ] );
		}

		$switches = get_option( self::OPTION, array() );

		return is_array( $switches ) && true === ( $switches[ $name ] ?? false );
	}

	/**
	 * Whether wp-config.php pins a switch, so the dashboard cannot change it.
	 *
	 * @param string $name `send` or `receive`.
	 */
	public static function is_locked( string $name ): bool {
		return isset( self::SWITCHES[ $name ] ) && defined( self::SWITCHES[ $name ] );
	}

	/**
	 * Turns a dashboard switch on or off. Refused for an unknown or locked switch.
	 *
	 * @param string $name `send` or `receive`.
	 * @param bool   $on   New state.
	 */
	public static function set( string $name, bool $on ): bool {
		if ( ! isset( self::SWITCHES[ $name ] ) || self::is_locked( $name ) ) {
			return false;
		}

		$switches          = get_option( self::OPTION, array() );
		$switches          = is_array( $switches ) ? $switches : array();
		$switches[ $name ] = $on;
		update_option( self::OPTION, $switches, true );

		return true;
	}
}
