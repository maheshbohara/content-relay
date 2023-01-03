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
}
