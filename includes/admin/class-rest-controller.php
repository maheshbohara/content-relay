<?php
/**
 * Own-site admin API behind the relay screen.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Admin;

use ContentRelay\Connection\Client;
use ContentRelay\Discovery\Export_Context;
use ContentRelay\Discovery\Export_Exception;
use ContentRelay\Discovery\Exporter;
use ContentRelay\Language\Adapter_Factory;
use ContentRelay\Send\Send_Exception;
use ContentRelay\Send\Sender;

defined( 'ABSPATH' ) || exit;

/**
 * `content-relay/v1/admin/*` — cookie-authenticated routes (WordPress REST nonce) for administrators of this site. They
 * wrap the Sender, so the browser drives a relay step by step and never holds a signing key. Registered only when
 * sending is on.
 */
final class Rest_Controller {

	/**
	 * Seconds each upload step may run.
	 */
	private const UPLOAD_BUDGET_SECONDS = 8.0;

	/**
	 * Hooks the routes.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( new self(), 'routes' ) );
	}
}
