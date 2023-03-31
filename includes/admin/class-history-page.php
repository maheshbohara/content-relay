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

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter and pagination.
		$direction = isset( $_GET['direction'] ) ? sanitize_key( wp_unslash( $_GET['direction'] ) ) : '';
		$paged     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$direction = in_array( $direction, array( Log_Table::OUT, Log_Table::IN ), true ) ? $direction : null;

		$log   = new Log_Table();
		$total = $log->count( $direction );
		$rows  = $log->recent( $direction, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );

		echo '<div class="wrap content-relay">';
		echo '<h1>' . esc_html__( 'Content Relay history', 'content-relay' ) . '</h1>';
		foreach ( $this->notices as $notice ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );
		}

		$filters = array(
			''             => array( __( 'All', 'content-relay' ), $log->count( null ) ),
			Log_Table::OUT => array( __( 'Sent', 'content-relay' ), $log->count( Log_Table::OUT ) ),
			Log_Table::IN  => array( __( 'Received', 'content-relay' ), $log->count( Log_Table::IN ) ),
		);
		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( $filters as $key => $filter ) {
			$links[] = sprintf(
				'<li><a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a></li>',
				esc_url( '' === $key ? self::url() : self::url( array( 'direction' => $key ) ) ),
				( (string) $direction === (string) $key ) ? ' class="current" aria-current="page"' : '',
				esc_html( $filter[0] ),
				(int) $filter[1]
			);
		}
		echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each link is escaped above.
		echo '</ul><br class="clear" />';

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No relays yet.', 'content-relay' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped content-relay-table content-relay-history"><thead><tr>';
		foreach ( array( __( 'When', 'content-relay' ), __( 'Direction', 'content-relay' ), __( 'Content', 'content-relay' ), __( 'By', 'content-relay' ), __( 'Status', 'content-relay' ), '' ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$unconfirmed = $this->confirm_statuses( $rows );
		foreach ( $rows as $row ) {
			$this->render_row( $log, $row, $unconfirmed[ (int) $row['id'] ] ?? '' );
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}

		echo '</div>';
	}

	/**
	 * Confirms sent relays still marked applied with their receivers, which may have rolled them back since. Rows found
	 * rolled back are updated in the log and in `$rows`; a receiver that cannot be reached is asked once per request.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows on this page; statuses updated in place.
	 * @return array<int, string> Row id => `unknown` or `unreachable` where the receiver did not confirm the status.
	 */
	private function confirm_statuses( array &$rows ): array {
		if ( ! Config::can_send() ) {
			return array();
		}
		try {
			$sender = Sender::for_site();
		} catch ( Send_Exception $e ) {
			return array();
		}

		$unconfirmed = array();
		$unreachable = array();
		foreach ( $rows as $index => $row ) {
			if ( Log_Table::OUT !== $row['direction'] || Log_Table::STATUS_APPLIED !== $row['status'] ) {
				continue;
			}

			$connection = (string) $row['connection'];
			$state      = isset( $unreachable[ $connection ] ) ? 'unreachable' : $sender->refresh_status( (string) $row['session'] );
			if ( 'unreachable' === $state ) {
				$unreachable[ $connection ] = true;
			}

			if ( Log_Table::STATUS_ROLLED_BACK === $state ) {
				$rows[ $index ]['status'] = $state;
			} elseif ( Log_Table::STATUS_APPLIED !== $state ) {
				$unconfirmed[ (int) $row['id'] ] = $state;
			}
		}

		return $unconfirmed;
	}
}
