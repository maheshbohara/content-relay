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

	/**
	 * One history row.
	 *
	 * @param Log_Table            $log         Log.
	 * @param array<string, mixed> $row         Row (without plan and result).
	 * @param string               $unconfirmed `unknown` or `unreachable` when the receiver did not confirm an applied relay.
	 */
	private function render_row( Log_Table $log, array $row, string $unconfirmed = '' ): void {
		$sent   = Log_Table::OUT === $row['direction'];
		$status = (string) $row['status'];
		$full   = in_array( $status, array( Log_Table::STATUS_APPLIED, Log_Table::STATUS_ROLLED_BACK ), true ) ? $log->find( (int) $row['id'] ) : null;

		echo '<tr>';
		echo '<td>' . esc_html( Admin::time( $row['created_at'] ) ) . '</td>';

		/* translators: %s: host name of the other site. */
		$direction = $sent ? __( 'Sent to %s', 'content-relay' ) : __( 'Received from %s', 'content-relay' );
		echo '<td>' . esc_html( sprintf( $direction, Admin::host( (string) $row['peer_url'] ) ) ) . '</td>';

		echo '<td>';
		$post_id = (int) $row['root_post_id'];
		$title   = '' !== (string) $row['root_title'] ? (string) $row['root_title'] : __( '(untitled)', 'content-relay' );
		$edit    = $post_id > 0 ? get_edit_post_link( $post_id, 'raw' ) : null;
		if ( is_string( $edit ) && '' !== $edit ) {
			printf( '<a href="%1$s"><strong>%2$s</strong></a>', esc_url( $edit ), esc_html( $title ) );
		} else {
			echo '<strong>' . esc_html( $title ) . '</strong>';
		}
		if ( '' !== (string) $row['languages'] ) {
			echo ' <span class="content-relay-muted">' . esc_html( strtoupper( str_replace( ',', ', ', (string) $row['languages'] ) ) ) . '</span>';
		}
		echo '<br /><code class="content-relay-muted" title="' . esc_attr( (string) $row['session'] ) . '">' . esc_html( substr( (string) $row['session'], 0, 8 ) ) . '</code>';
		echo '</td>';

		echo '<td>' . esc_html( (string) $row['user_login'] ) . '</td>';

		echo '<td>' . esc_html( self::status_label( $row ) );
		if ( null !== $full && ! empty( $full['result']['rolled_back_on_receiver'] ) ) {
			echo '<br /><span class="content-relay-muted">' . esc_html__( 'Rolled back on the receiving site.', 'content-relay' ) . '</span>';
		}
		if ( 'unknown' === $unconfirmed ) {
			echo '<br /><span class="content-relay-muted">' . esc_html__( 'Not confirmed by the receiving site: it has no record of this relay for the current connection key.', 'content-relay' ) . '</span>';
		} elseif ( 'unreachable' === $unconfirmed ) {
			echo '<br /><span class="content-relay-muted">' . esc_html__( 'The receiving site could not be reached to confirm this status.', 'content-relay' ) . '</span>';
		}
		if ( '' !== (string) ( $row['error'] ?? '' ) ) {
			echo '<br /><span class="content-relay-error">' . esc_html( (string) $row['error'] ) . '</span>';
		}
		if ( null !== $full ) {
			$this->render_details( $full );
		}
		echo '</td>';

		echo '<td class="content-relay-actions">';
		if ( Log_Table::STATUS_APPLIED === $status ) {
			echo '<form method="post" class="content-relay-form">';
			wp_nonce_field( 'content_relay_history_rollback' );
			printf( '<input type="hidden" name="log_id" value="%d" />', (int) $row['id'] );
			printf(
				'<label class="content-relay-overwrite"><input type="checkbox" name="overwrite" value="1" /> %s</label> ',
				esc_html__( 'Also discard edits made after the relay', 'content-relay' )
			);
			printf(
				'<button type="submit" class="button" onclick="return confirm(%1$s)">%2$s</button>',
				esc_attr(
					(string) wp_json_encode(
						$sent
							? __( 'Roll back this relay on the receiving site? What it replaced is restored and what it created is removed.', 'content-relay' )
							: __( 'Roll back this relay on this site? What it replaced is restored and what it created is removed.', 'content-relay' )
					)
				),
				esc_html__( 'Roll back', 'content-relay' )
			);
			echo '</form>';

			if ( $sent ) {
				echo '<form method="post" class="content-relay-form">';
				wp_nonce_field( 'content_relay_history_verify' );
				echo '<input type="hidden" name="content_relay_history_action" value="verify" />';
				printf( '<input type="hidden" name="log_id" value="%d" />', (int) $row['id'] );
				printf( '<button type="submit" class="button">%s</button>', esc_html__( 'Check pages', 'content-relay' ) );
				echo '</form>';
			}
		}
		echo '</td></tr>';
	}

	/**
	 * Written objects and rollback summary of an applied session.
	 *
	 * @param array<string, mixed> $row Full row.
	 */
	private function render_details( array $row ): void {
		$result       = (array) ( $row['result'] ?? array() );
		$written      = (array) ( $result['written'] ?? array() );
		$warnings     = (array) ( $result['warnings'] ?? array() );
		$verification = is_array( $result['verification'] ?? null ) ? $result['verification'] : null;
		if ( array() === $written && empty( $result['rollback'] ) && array() === $warnings && null === $verification ) {
			return;
		}

		if ( null !== $verification ) {
			echo '<br />';
			if ( ! empty( $verification['ok'] ) ) {
				/* translators: %s: date and time of the check. */
				echo '<span class="content-relay-badge is-on">' . esc_html( sprintf( __( 'Pages checked %s: all passed', 'content-relay' ), Admin::time( $verification['checked_at'] ?? '' ) ) ) . '</span>';
			} else {
				/* translators: 1: date and time of the check, 2: number of failed checks. */
				echo '<span class="content-relay-badge is-off">' . esc_html( sprintf( __( 'Pages checked %1$s: %2$d failed', 'content-relay' ), Admin::time( $verification['checked_at'] ?? '' ), count( (array) ( $verification['problems'] ?? array() ) ) ) ) . '</span>';
			}
		}

		echo '<details class="content-relay-details"><summary>' . esc_html__( 'Details', 'content-relay' ) . '</summary><ul>';
		foreach ( (array) ( $verification['problems'] ?? array() ) as $problem ) {
			/* translators: %s: failed check. */
			echo '<li class="content-relay-error">' . esc_html( sprintf( __( 'Check failed: %s', 'content-relay' ), (string) $problem ) ) . '</li>';
		}
		foreach ( $warnings as $warning ) {
			/* translators: %s: warning from the receiving site. */
			echo '<li>' . esc_html( sprintf( __( 'Warning: %s', 'content-relay' ), (string) $warning ) ) . '</li>';
		}
		foreach ( $written as $object ) {
			/* translators: 1: plan action, 2: object key on the sender, 3: object id on the receiver. */
			echo '<li>' . esc_html( sprintf( __( '%1$s %2$s → id %3$s', 'content-relay' ), (string) ( $object['action'] ?? '' ), (string) ( $object['key'] ?? '' ), (string) ( $object['id'] ?? '' ) ) ) . '</li>';
		}
		if ( ! empty( $result['rollback'] ) ) {
			$rollback = (array) $result['rollback'];
			/* translators: 1: restored objects, 2: removed objects, 3: removed files. */
			echo '<li>' . esc_html( sprintf( __( 'Rollback: %1$d restored, %2$d removed, %3$d files removed', 'content-relay' ), count( (array) ( $rollback['restored'] ?? array() ) ), count( (array) ( $rollback['deleted'] ?? array() ) ), (int) ( $rollback['files'] ?? 0 ) ) ) . '</li>';
		}
		echo '</ul></details>';
	}

	/**
	 * Checks a sent relay's pages on its receiver again.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private function verify_sent( array $row ): void {
		if ( Log_Table::OUT !== $row['direction'] ) {
			$this->notice( 'error', __( 'Only a relay sent from this site can be checked from here.', 'content-relay' ) );
			return;
		}

		try {
			$report = Sender::for_site()->verify( (string) $row['session'] );
		} catch ( Send_Exception $e ) {
			$this->notice( 'error', $e->plain_message() );
			return;
		}

		if ( $report['ok'] ) {
			$this->notice( 'success', __( 'Every check on the receiving site passed.', 'content-relay' ) );
			return;
		}

		$this->notice(
			'error',
			sprintf(
				/* translators: 1: number of failed checks, 2: the failures. */
				_n( '%1$d check failed on the receiving site: %2$s', '%1$d checks failed on the receiving site: %2$s', count( $report['problems'] ), 'content-relay' ),
				count( $report['problems'] ),
				implode( ' ', $report['problems'] )
			)
		);
	}

	/**
	 * Rolls back a sent relay on its receiver.
	 *
	 * @param array<string, mixed> $row       Row.
	 * @param bool                 $overwrite Discard later edits.
	 */
	private function rollback_sent( array $row, bool $overwrite ): void {
		try {
			$data = Sender::for_site()->rollback( (string) $row['session'], $overwrite );
		} catch ( Send_Exception $e ) {
			$this->notice( 'error', $e->plain_message() );
			return;
		}

		$this->rolled_back_notice( (array) ( $data['rollback'] ?? array() ) );
	}

	/**
	 * Rolls back a received relay on this site.
	 *
	 * @param Log_Table            $log       Log.
	 * @param array<string, mixed> $row       Row.
	 * @param bool                 $overwrite Discard later edits.
	 */
	private function rollback_received( Log_Table $log, array $row, bool $overwrite ): void {
		try {
			$summary = ( new Rollback( new Receive_Context( Adapter_Factory::create() ) ) )->rollback( (string) $row['apply_session'], $overwrite );
		} catch ( Apply_Exception $e ) {
			$this->notice( 'error', html_entity_decode( $e->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			return;
		}

		$staging = Staging::for_site();
		if ( null !== $staging->read( (string) $row['session'] ) ) {
			try {
				$staging->update(
					(string) $row['session'],
					array(
						'status'         => Staging::STATUS_ROLLED_BACK,
						'rolled_back_at' => gmdate( 'c' ),
						'rollback'       => $summary,
					)
				);
			} catch ( Staging_Exception $e ) {
				$this->notice( 'warning', __( 'The rollback succeeded, but its staging record could not be updated.', 'content-relay' ) );
			}
		}

		$log->update(
			(string) $row['session'],
			Log_Table::IN,
			array(
				'status' => Log_Table::STATUS_ROLLED_BACK,
				'result' => array_merge( (array) ( $row['result'] ?? array() ), array( 'rollback' => $summary ) ),
			)
		);

		$this->rolled_back_notice( $summary );
	}

	/**
	 * Success notice for a rollback.
	 *
	 * @param array<string, mixed> $summary Rollback summary.
	 */
	private function rolled_back_notice( array $summary ): void {
		$this->notice(
			'success',
			sprintf(
				/* translators: 1: restored objects, 2: removed objects, 3: removed terms, 4: removed files. */
				__( 'Rolled back: %1$d restored, %2$d removed, %3$d terms removed, %4$d files removed.', 'content-relay' ),
				count( (array) ( $summary['restored'] ?? array() ) ),
				count( (array) ( $summary['deleted'] ?? array() ) ),
				count( (array) ( $summary['terms'] ?? array() ) ),
				(int) ( $summary['files'] ?? 0 )
			)
		);
	}

	/**
	 * Human status of a row.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function status_label( array $row ): string {
		switch ( $row['status'] ) {
			case Log_Table::STATUS_APPLIED:
				return __( 'Applied', 'content-relay' );
			case Log_Table::STATUS_ROLLED_BACK:
				return __( 'Rolled back', 'content-relay' );
			case Log_Table::STATUS_ABORTED:
				return __( 'Cancelled', 'content-relay' );
			default:
				$age = time() - (int) strtotime( (string) $row['updated_at'] . ' UTC' );
				return $age > Staging::TTL_SECONDS ? __( 'Expired (not applied)', 'content-relay' ) : __( 'Planned (not applied)', 'content-relay' );
		}
	}

	/**
	 * Queues a notice.
	 *
	 * @param string $type    `success`, `warning` or `error`.
	 * @param string $message Plain-text message.
	 */
	private function notice( string $type, string $message ): void {
		$this->notices[] = array(
			'type'    => $type,
			'message' => $message,
		);
	}
}
