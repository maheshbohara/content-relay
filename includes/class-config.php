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

	/**
	 * Option holding the post types an administrator made relayable.
	 */
	public const POST_TYPES_OPTION = 'content_relay_post_types';

	/**
	 * Post types that can be relayed from this site, as chosen in the dashboard. Pages by default.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = get_option( self::POST_TYPES_OPTION, array( 'page' ) );
		$types = is_array( $types ) ? array_values( array_filter( array_map( 'strval', $types ), array( self::class, 'is_relayable_post_type' ) ) ) : array();

		return array() === $types ? array( 'page' ) : $types;
	}

	/**
	 * Saves the relayable post types, keeping only registered, relayable ones.
	 *
	 * @param string[] $types Post type names.
	 * @return string[] What was saved.
	 */
	public static function set_post_types( array $types ): array {
		$types = array_values( array_unique( array_filter( array_map( 'strval', $types ), array( self::class, 'is_relayable_post_type' ) ) ) );
		$types = array() === $types ? array( 'page' ) : $types;
		update_option( self::POST_TYPES_OPTION, $types, false );

		return $types;
	}

	/**
	 * Whether a post type can be offered for relaying: registered, shown in the admin, and not media or internals.
	 *
	 * @param string $type Post type name.
	 */
	public static function is_relayable_post_type( string $type ): bool {
		$object = get_post_type_object( $type );

		return null !== $object && $object->show_ui && ! in_array( $type, array( 'attachment', 'revision', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page' ), true );
	}

	/**
	 * Whether plain-HTTP receivers are allowed (local testing only).
	 */
	public static function allow_http(): bool {
		return defined( 'CONTENT_RELAY_ALLOW_HTTP' ) && true === constant( 'CONTENT_RELAY_ALLOW_HTTP' );
	}

	/**
	 * Sender IPs/CIDRs the receiver accepts. Null when no allowlist is configured (every IP is accepted).
	 *
	 * @return string[]|null
	 */
	public static function ip_allowlist(): ?array {
		if ( ! defined( 'CONTENT_RELAY_IP_ALLOWLIST' ) ) {
			return null;
		}

		$list = constant( 'CONTENT_RELAY_IP_ALLOWLIST' );
		if ( is_string( $list ) ) {
			$list = preg_split( '/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY );
		}

		return array_values( array_filter( array_map( 'strval', (array) $list ) ) );
	}

	/**
	 * Whether a receiver URL uses a scheme this site may send to.
	 *
	 * @param string $url Receiver URL.
	 */
	public static function is_allowed_receiver_url( string $url ): bool {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		return 'https' === $scheme || ( 'http' === $scheme && self::allow_http() );
	}
}
