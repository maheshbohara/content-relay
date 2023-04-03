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
}
