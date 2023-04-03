<?php
/**
 * The relay screen.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Admin;

use ContentRelay\Config;
use ContentRelay\Connection\Connections;
use ContentRelay\Discovery\Exporter;

defined( 'ABSPATH' ) || exit;

/**
 * A screen without a menu entry, reached from the "Relay to…" row action: choose a connection, review what is relayed
 * and the receiver's plan, relay, roll back. Rendered by `assets/admin/relay.js` against the admin REST routes.
 */
final class Relay_Screen {

	/**
	 * Page slug.
	 */
	public const SLUG = 'content-relay-relay';

	/**
	 * Screen URL for a page.
	 *
	 * @param int $post_id Page id.
	 */
	public static function url( int $post_id ): string {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'post' => $post_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Hooks the screen.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
	}

	/**
	 * Registers the screen without a menu entry.
	 */
	public function menu(): void {
		$hook = add_submenu_page( '', __( 'Relay content', 'content-relay' ), __( 'Relay content', 'content-relay' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * Enqueues assets for a valid page.
	 */
	public function load(): void {
		Admin::enqueue_style();

		$post = self::requested_post();

		// A page without a menu entry gets no title from WordPress; give the browser tab one.
		/* translators: %s: page title. */
		$label = null === $post ? __( 'Relay content', 'content-relay' ) : sprintf( __( 'Relay “%s”', 'content-relay' ), $post->post_title );
		add_filter(
			'admin_title',
			static function ( $admin_title, $title ) use ( $label ) {
				return '' === (string) $title ? $label . ' ' . $admin_title : $admin_title;
			},
			10,
			2
		);

		if ( null === $post || ! Config::can_send() ) {
			return;
		}

		add_action(
			'admin_enqueue_scripts',
			static function () use ( $post ): void {
				self::scripts( $post );
			}
		);
	}
}
