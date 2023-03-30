<?php
/**
 * Tools → Content Relay history.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Admin;

use ContentRelay\Apply\Apply_Exception;
use ContentRelay\Apply\Rollback;
use ContentRelay\Config;
use ContentRelay\Language\Adapter_Factory;
use ContentRelay\Receive\Receive_Context;
use ContentRelay\Send\Send_Exception;
use ContentRelay\Send\Sender;
use ContentRelay\Storage\Log_Table;
use ContentRelay\Storage\Staging;
use ContentRelay\Storage\Staging_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Sessions sent and received, with rollback: a sent relay is rolled back on its receiver, a received one here.
 */
final class History_Page {

	/**
	 * Page slug.
	 */
	public const SLUG = 'content-relay-history';

	/**
	 * Rows per page.
	 */
	private const PER_PAGE = 20;

	/**
	 * Notices for this request.
	 *
	 * @var array<int, array{type: string, message: string}>
	 */
	private array $notices = array();
}
