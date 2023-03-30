<?php
/**
 * Admin bootstrap.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Admin;

use ContentRelay\Connection\Connections;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin screens (spec §14.1) and holds the small helpers they share.
 */
final class Admin {

	/**
	 * Hooks every screen.
	 */
	public static function register(): void {
		( new Settings_Page() )->register();
		( new History_Page() )->register();
		( new Relay_Screen() )->register();
		( new Row_Action() )->register();
	}

	/**
	 * Enqueues the admin stylesheet on the current screen (call from a `load-{page}` hook).
	 */
	public static function enqueue_style(): void {
		add_action(
			'admin_enqueue_scripts',
			static function (): void {
				wp_enqueue_style( 'content-relay-admin', CONTENT_RELAY_URL . 'assets/admin/admin.css', array(), CONTENT_RELAY_VERSION );
			}
		);
	}

	/**
	 * Number of stored connections (read without decrypting anything).
	 */
	public static function connection_count(): int {
		$connections = get_option( Connections::OPTION, array() );

		return is_array( $connections ) ? count( $connections ) : 0;
	}

	/**
	 * Formats a stored UTC time (ISO 8601 or `Y-m-d H:i:s`) in the site's date and time format.
	 *
	 * @param mixed $value Stored time.
	 */
	public static function time( $value ): string {
		$timestamp = is_string( $value ) && '' !== $value ? strtotime( $value . ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ? ' UTC' : '' ) ) : false;
		if ( false === $timestamp ) {
			return '—';
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Host name of a URL, for compact display.
	 *
	 * @param string $url URL.
	 */
	public static function host( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) ? $host : $url;
	}
}
