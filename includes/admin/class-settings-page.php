<?php
/**
 * Settings → Content Relay.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Admin;

use ContentRelay\Config;
use ContentRelay\Connection\Client;
use ContentRelay\Connection\Connection_Exception;
use ContentRelay\Connection\Connections;
use ContentRelay\Connection\Key_Codec;
use ContentRelay\Connection\Receiver_Keys;
use ContentRelay\Connection\Vault;
use ContentRelay\Language\Adapter_Factory;
use ContentRelay\Send\Send_Exception;
use ContentRelay\Send\Sender;
use ContentRelay\Storage\Log_Table;

defined( 'ABSPATH' ) || exit;

/**
 * This site's send/receive status, the keys it issued, and its connections (spec §14.1).
 *
 * Forms post back to the page itself and are handled on `load-…` before output, so an issued key can be shown once
 * in the same response without ever being stored.
 */
final class Settings_Page {

	/**
	 * Page slug.
	 */
	public const SLUG = 'content-relay';

	/**
	 * Notices for this request.
	 *
	 * @var array<int, array{type: string, message: string}>
	 */
	private array $notices = array();
}
