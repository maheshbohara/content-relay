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

	/**
	 * History URL.
	 *
	 * @param array<string, string|int> $args Extra query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * Hooks the page.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
	}

	/**
	 * Adds the menu entry.
	 */
	public function menu(): void {
		$hook = add_management_page( __( 'Content Relay history', 'content-relay' ), __( 'Content Relay history', 'content-relay' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * Handles a rollback form before the page renders.
	 */
	public function load(): void {
		Admin::enqueue_style();

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' !== $method ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Content Relay.', 'content-relay' ), 403 );
		}
		$action = isset( $_POST['content_relay_history_action'] ) && 'verify' === $_POST['content_relay_history_action'] ? 'verify' : 'rollback'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only picks the nonce action verified on the next line.
		check_admin_referer( 'content_relay_history_' . $action );

		$id        = isset( $_POST['log_id'] ) ? absint( $_POST['log_id'] ) : 0;
		$overwrite = ! empty( $_POST['overwrite'] );
		$log       = new Log_Table();
		$row       = $log->find( $id );

		if ( null === $row || Log_Table::STATUS_APPLIED !== $row['status'] ) {
			$this->notice( 'error', 'verify' === $action ? __( 'Only an applied relay can be checked.', 'content-relay' ) : __( 'Only an applied relay can be rolled back.', 'content-relay' ) );
			return;
		}

		if ( 'verify' === $action ) {
			$this->verify_sent( $row );
			return;
		}

		if ( Log_Table::OUT === $row['direction'] ) {
			$this->rollback_sent( $row, $overwrite );
		} else {
			$this->rollback_received( $log, $row, $overwrite );
		}
	}
}
