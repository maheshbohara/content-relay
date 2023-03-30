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
}
