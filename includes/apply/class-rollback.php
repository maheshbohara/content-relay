<?php
/**
 * Undoes a relay from its backup and session record.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Apply;

use ContentRelay\Receive\Receive_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Rollback (spec §11): restores replaced objects exactly, removes created posts, attachments, terms and files.
 */
final class Rollback {

	/**
	 * Receiver context.
	 *
	 * @var Receive_Context
	 */
	private Receive_Context $context;

	/**
	 * Row writer.
	 *
	 * @var Post_Writer
	 */
	private Post_Writer $writer;

	/**
	 * Backup store.
	 *
	 * @var Backup
	 */
	private Backup $backup;

	/**
	 * Constructor.
	 *
	 * @param Receive_Context $context Receiver context.
	 */
	public function __construct( Receive_Context $context ) {
		$this->context = $context;
		$this->writer  = new Post_Writer();
		$this->backup  = new Backup( $context );
	}

	/**
	 * Rolls back a session.
	 *
	 * @param string $session   Session id.
	 * @param bool   $overwrite Roll back even if relayed objects changed after the relay.
	 * @return array{session: string, restored: int[], deleted: int[], terms: int[], files: int, caches: string[]}
	 *
	 * @throws Apply_Exception When the session is unknown, already rolled back, changed since, or writing fails.
	 */
	public function rollback( string $session, bool $overwrite ): array {
		global $wpdb;

		$record = $this->backup->read( 'sessions', $session );
		if ( ! empty( $record['rolled_back_at'] ) ) {
			throw new Apply_Exception( esc_html( sprintf( 'Session %1$s was already rolled back at %2$s.', $session, (string) $record['rolled_back_at'] ) ) );
		}

		$result   = (array) ( $record['result'] ?? array() );
		$snapshot = $this->backup->read( 'backups', $session );
		$source   = (string) ( $result['source'] ?? '' );

		$problems = array();
		foreach ( (array) ( $result['written'] ?? array() ) as $row ) {
			$post_id = (int) $row['id'];
			$state   = $this->context->sync->get_post( $post_id, $source );
			if ( null === $state || $state['session'] !== $session ) {
				$problems[] = sprintf( '%1$s (post %2$d) was relayed again after this session.', $row['key'], $post_id );
			} elseif ( $this->context->state->post( $post_id ) !== $state['local'] ) {
				$problems[] = sprintf( '%1$s (post %2$d) was edited on this site after the relay.', $row['key'], $post_id );
			}
		}
		if ( array() !== $problems && ! $overwrite ) {
			throw new Apply_Exception( esc_html( implode( ' ', $problems ) . ' Roll back with overwrite to discard those changes.' ) );
		}

		$created  = array_map( 'intval', array_merge( (array) ( $result['created']['posts'] ?? array() ), (array) ( $result['created']['attachments'] ?? array() ) ) );
		$restored = array();
		$terms    = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The rollback commit is atomic.
		$wpdb->query( 'START TRANSACTION' );
		try {
			foreach ( $created as $post_id ) {
				$this->context->language->unlink_post( $post_id );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes term assignments of a post this relay created.
				$wpdb->delete( $wpdb->term_relationships, array( 'object_id' => $post_id ) );
				$this->writer->delete( $post_id );
			}

			foreach ( (array) ( $snapshot['posts'] ?? array() ) as $post_id => $saved ) {
				$this->restore( (int) $post_id, (array) $saved );
				$restored[] = (int) $post_id;
			}

			foreach ( (array) ( $result['created']['terms'] ?? array() ) as $term_id ) {
				$term = get_term( (int) $term_id );
				if ( $term instanceof \WP_Term ) {
					wp_delete_term( $term->term_id, $term->taxonomy );
					$terms[] = (int) $term->term_id;
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The rollback commit is atomic.
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The rollback commit is atomic.
			$wpdb->query( 'ROLLBACK' );
			throw new Apply_Exception( esc_html( 'Rollback failed and was undone: ' . $e->getMessage() ), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped; the previous exception is chained, not output.
		}

		$files = 0;
		foreach ( (array) ( $result['created']['files'] ?? array() ) as $path ) {
			if ( is_string( $path ) && 0 === strpos( $path, $this->context->uploads_dir . '/' ) && file_exists( $path ) ) {
				wp_delete_file( $path );
				++$files;
			}
		}

		( new Cache( $this->context ) )->after_write( array_merge( $created, $restored ) );
		$caches = ( new Page_Cache() )->purge( array_column( (array) ( $result['written'] ?? array() ), 'url' ), array_merge( $created, $restored ) );
		$this->backup->mark_session( $session, array( 'rolled_back_at' => gmdate( 'c' ) ) );

		return array(
			'session'  => $session,
			'restored' => $restored,
			'deleted'  => $created,
			'terms'    => $terms,
			'files'    => $files,
			'caches'   => $caches['purged'],
		);
	}
}
