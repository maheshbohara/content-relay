<?php
/**
 * Applies an approved plan.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Apply;

use ContentRelay\Identity\State_Fingerprint;
use ContentRelay\Identity\Sync_State;
use ContentRelay\Identity\Uuid;
use ContentRelay\Receive\Bundle;
use ContentRelay\Receive\Planner;
use ContentRelay\Receive\Receive_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Apply sequence (spec §8.5): re-plan and compare with the approved plan, place media, back up, commit content in one
 * transaction, then refresh caches and verify what was written.
 */
final class Applier {

	/**
	 * Plan actions that write an owned object.
	 */
	private const WRITE_ACTIONS = array( 'create', 'create-new-file', 'replace-file', 'update', 'overwrite', 'adopt' );

	/**
	 * Plan actions whose object keeps its existing receiver id.
	 */
	private const EXISTING_ACTIONS = array( 'reuse', 'map', 'update', 'overwrite', 'adopt', 'unchanged' );

	/**
	 * Plan actions that place a new file and create a new attachment.
	 */
	private const NEW_FILE_ACTIONS = array( 'create', 'create-new-file', 'replace-file' );

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
	 * Warnings of the apply in progress.
	 *
	 * @var string[]
	 */
	private array $warnings = array();

	/**
	 * Constructor.
	 *
	 * @param Receive_Context $context Receiver context.
	 */
	public function __construct( Receive_Context $context ) {
		$this->context = $context;
		$this->writer  = new Post_Writer();
	}

	/**
	 * Applies a bundle.
	 *
	 * @param Bundle $bundle        Bundle.
	 * @param bool   $overwrite     Whether receiver changes since the last relay may be overwritten.
	 * @param string $approved_hash Hash of the plan the administrator approved.
	 * @return array<string, mixed> Result: session, objects written, created objects, integrity problems.
	 *
	 * @throws Apply_Exception When the plan changed, is blocked, or writing fails (everything is rolled back).
	 */
	public function apply( Bundle $bundle, bool $overwrite, string $approved_hash ): array {
		global $wpdb;

		$plan = ( new Planner( $this->context ) )->plan( $bundle, $overwrite );
		if ( $plan['hash'] !== $approved_hash ) {
			throw new Apply_Exception( 'This site changed after the plan was approved. Review the new plan and approve it again.' );
		}
		if ( array() !== $plan['blockers'] ) {
			throw new Apply_Exception( 'The plan has blockers and cannot be applied.' );
		}

		$session        = wp_generate_uuid4();
		$objects        = $bundle->objects();
		$this->warnings = array();
		$items          = array();
		$ids            = array();
		foreach ( $plan['items'] as $item ) {
			$items[ $item['key'] ] = $item;
			if ( null !== $item['id'] && in_array( $item['action'], self::EXISTING_ACTIONS, true ) ) {
				$ids[ $item['key'] ] = (int) $item['id'];
			}
		}

		$ids   += $bundle->absent_ids();
		$source = $bundle->manifest['source'];
		$urls   = new Url_Map( (string) $source['home_url'], $this->context->home_url, (string) $source['uploads_url'], $this->context->uploads_url );
		$media  = new Media_Writer( $this->context, $bundle, $this->writer, $session );

		// Step A — media, outside the transaction; undone by cleanup() on failure.
		try {
			foreach ( $items as $key => $item ) {
				if ( 'attachment' === $item['kind'] && in_array( $item['action'], self::NEW_FILE_ACTIONS, true ) ) {
					$ids[ $key ] = $media->create( $objects[ $key ], $urls );
				}
			}
		} catch ( \Throwable $e ) {
			$media->cleanup();
			throw new Apply_Exception( esc_html( 'Placing media failed: ' . $e->getMessage() ), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped; the previous exception is chained, not output.
		}

		// Step B — backup of everything that will be replaced (a superseded attachment only gains a marker).
		$replaced = array();
		foreach ( $items as $key => $item ) {
			if ( in_array( $item['action'], array( 'update', 'overwrite', 'adopt' ), true ) ) {
				$replaced[] = $ids[ $key ];
			} elseif ( 'replace-file' === $item['action'] ) {
				$replaced[] = (int) $item['id'];
			}
		}
		try {
			$backup_file = ( new Backup( $this->context ) )->snapshot( $session, $replaced );
		} catch ( \Throwable $e ) {
			$media->cleanup();
			throw new Apply_Exception( esc_html( 'Backup failed, nothing was changed: ' . $e->getMessage() ), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped; the previous exception is chained, not output.
		}

		$untouched_twins = $this->untouched_twins( $items, $ids );
		$written         = array();
		$expected        = array();
		$created_posts   = array();
		$superseded      = array();

		// Step C — content commit.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The content commit is atomic.
		$wpdb->query( 'START TRANSACTION' );
		try {
			// Posts are reserved first so ids in the meta of created terms can point at them.
			foreach ( $items as $key => $item ) {
				if ( 'post' === $item['kind'] && 'create' === $item['action'] ) {
					$ids[ $key ]     = $this->writer->reserve( (string) $objects[ $key ]['post_type'] );
					$created_posts[] = $ids[ $key ];
				}
			}

			$created_terms = $this->create_terms( $items, $objects, $ids );

			$rewriter = new Rewriter( $this->context, $urls, $ids );

			foreach ( $items as $key => $item ) {
				if ( 'owned' !== $item['role'] || ! in_array( $item['action'], self::WRITE_ACTIONS, true ) ) {
					continue;
				}
				$record           = $objects[ $key ];
				$post_id          = $ids[ $key ];
				$rewritten        = $rewriter->rewrite( $record );
				$expected[ $key ] = md5( $rewritten['content'] );
				$is_attachment    = 'attachment' === $record['kind'];

				$this->writer->update(
					$post_id,
					$record,
					$rewritten['fields'],
					$rewritten['content'],
					$this->author_id( $record, $ids ),
					$is_attachment ? $this->attachment_parent( $record, $item, $post_id, $ids ) : ( is_string( $record['parent'] ?? null ) ? $ids[ $record['parent'] ] : 0 ),
					$is_attachment ? $media->guid( $post_id ) : $this->guid( (string) $record['post_type'], $post_id )
				);
				$this->writer->replace_meta( $post_id, $rewritten['meta'], $is_attachment );
				if ( ! $is_attachment ) {
					$this->writer->replace_terms( $post_id, (string) $record['post_type'], $this->term_ids( $record, $ids ) );
				}

				if ( 'replace-file' === $item['action'] ) {
					$old_id = (int) $item['id'];
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The identity moves to the new attachment.
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN (%s, %s)", $old_id, Uuid::META_KEY, Sync_State::META_KEY ) );
					$this->writer->set_meta( $old_id, '_content_relay_superseded_by', (string) $post_id );
					$superseded[] = $old_id;
				}

				if ( is_string( $record['uuid'] ?? null ) ) {
					$this->writer->set_meta( $post_id, Uuid::META_KEY, $record['uuid'] );
				}

				$written[ $key ] = $post_id;
			}

			$this->link_languages( $written, $objects, $ids );

			foreach ( $written as $key => $post_id ) {
				clean_post_cache( $post_id );
				$this->writer->set_meta(
					$post_id,
					Sync_State::META_KEY,
					$this->context->sync->encode_with(
						$post_id,
						$bundle->source_home(),
						array(
							'incoming'  => $this->context->state->incoming( $objects[ $key ], $this->resolved( $objects[ $key ], $ids ) ),
							'local'     => (string) $this->context->state->post( $post_id ),
							'session'   => $session,
							'at'        => gmdate( 'c' ),
							'direction' => 'in',
						)
					)
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Clears the pending marker set in step A.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, Media_Writer::PENDING_META ) );
			}

			/**
			 * Fires inside the content transaction, just before it commits. Throwing rolls the whole relay back.
			 *
			 * @param string             $session Session id.
			 * @param array<string, int> $ids     Object key => receiver id.
			 */
			do_action( 'content_relay_before_commit', $session, $ids );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The content commit is atomic.
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The content commit is atomic.
			$wpdb->query( 'ROLLBACK' );
			$media->cleanup();
			( new Cache( $this->context ) )->after_write( array_values( $ids ) );
			throw new Apply_Exception( esc_html( 'Relay rolled back: ' . $e->getMessage() ), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped; the previous exception is chained, not output.
		}

		// Step D — caches, indexables, integrity, query dependencies, page caches.
		( new Cache( $this->context ) )->after_write( array_merge( array_values( $written ), $superseded ) );

		$described = $this->describe( $written, $items, $objects );
		$warnings  = array_merge( $this->warnings, $this->query_dependencies( $written, $items, $objects ) );
		$caches    = ( new Page_Cache() )->purge( $this->post_urls( $described, $objects ), array_values( $written ) );

		$result = array(
			'session'  => $session,
			'source'   => $bundle->source_home(),
			'backup'   => $backup_file,
			'written'  => $described,
			'created'  => array(
				'posts'       => $created_posts,
				'attachments' => $media->created_ids(),
				'files'       => $media->created_files(),
				'terms'       => $created_terms,
			),
			'problems' => $this->integrity( $written, $expected, $untouched_twins ),
			'warnings' => array_values( array_unique( array_merge( $warnings, $caches['problems'] ) ) ),
			'caches'   => $caches['purged'],
		);

		( new Backup( $this->context ) )->record_session(
			$session,
			array(
				'plan'   => $plan,
				'result' => $result,
			)
		);

		/**
		 * Fires after a relay is committed and verified.
		 *
		 * @param array<string, mixed> $result Apply result.
		 */
		do_action( 'content_relay_applied', $result );

		return $result;
	}

	/**
	 * Creates missing terms, parents before children.
	 *
	 * @param array<string, array<string, mixed>> $items   Plan items by key.
	 * @param array<string, array<string, mixed>> $objects Manifest objects.
	 * @param array<string, int>                  $ids     Id map, extended.
	 * @return int[] Created term ids.
	 *
	 * @throws Apply_Exception When a term cannot be created.
	 */
	private function create_terms( array $items, array $objects, array &$ids ): array {
		$pending = array();
		foreach ( $items as $key => $item ) {
			if ( 'term' === $item['kind'] && 'create' === $item['action'] ) {
				$pending[ $key ] = $objects[ $key ];
			}
		}

		$writer  = new Term_Writer( $this->context );
		$created = array();
		while ( array() !== $pending ) {
			$progress = false;
			foreach ( $pending as $key => $record ) {
				$parent = $record['parent'] ?? null;
				if ( is_string( $parent ) && ! isset( $ids[ $parent ] ) ) {
					continue;
				}
				$original_key = is_string( $record['source_language'] ?? null ) ? ( $record['translations'][ $record['source_language'] ] ?? null ) : null;
				$ids[ $key ]  = $writer->create( $record, is_string( $parent ) ? $ids[ $parent ] : 0, is_string( $original_key ) ? ( $ids[ $original_key ] ?? null ) : null, $ids );
				$created[]    = $ids[ $key ];
				unset( $pending[ $key ] );
				$progress = true;
			}
			if ( ! $progress ) {
				throw new Apply_Exception( esc_html( 'Term parents cannot be resolved: ' . implode( ', ', array_keys( $pending ) ) ) );
			}
		}

		$this->warnings = array_merge( $this->warnings, $writer->warnings() );

		return $created;
	}

	/**
	 * Links written posts and attachments to their translation groups, originals first.
	 *
	 * @param array<string, int>                  $written Written key => id.
	 * @param array<string, array<string, mixed>> $objects Manifest objects.
	 * @param array<string, int>                  $ids     Id map.
	 */
	private function link_languages( array $written, array $objects, array $ids ): void {
		$ordered = $written;
		uksort(
			$ordered,
			static function ( string $a, string $b ) use ( $objects ): int {
				return ( null === ( $objects[ $a ]['source_language'] ?? null ) ? 0 : 1 ) <=> ( null === ( $objects[ $b ]['source_language'] ?? null ) ? 0 : 1 );
			}
		);

		foreach ( $ordered as $key => $post_id ) {
			$record   = $objects[ $key ];
			$language = $record['language'] ?? null;
			if ( ! is_string( $language ) ) {
				continue;
			}

			$original_id = null;
			$source      = $record['source_language'] ?? null;
			if ( is_string( $source ) ) {
				$original_key = $record['translations'][ $source ] ?? null;
				$original_id  = is_string( $original_key ) ? ( $ids[ $original_key ] ?? null ) : null;
			}

			$this->context->language->link_post( $post_id, $language, $original_id );
		}
	}

	/**
	 * Receiver ids of the objects an owned object references, after this relay.
	 *
	 * @param array<string, mixed> $record Manifest object.
	 * @param array<string, int>   $ids    Id map.
	 * @return array<string, int|string>
	 */
	private function resolved( array $record, array $ids ): array {
		$resolved = array();
		foreach ( State_Fingerprint::ref_keys( $record ) as $key ) {
			$resolved[ $key ] = $ids[ $key ] ?? 'new';
		}

		return $resolved;
	}

	/**
	 * Receiver ids of translations that exist here but are not part of this relay, with their content hashes.
	 *
	 * @param array<string, array<string, mixed>> $items Plan items.
	 * @param array<string, int>                  $ids   Id map.
	 * @return array<int, string> Post id => md5 of content.
	 */
	private function untouched_twins( array $items, array $ids ): array {
		$relayed = array();
		foreach ( $items as $key => $item ) {
			if ( 'owned' === $item['role'] && isset( $ids[ $key ] ) ) {
				$relayed[ $ids[ $key ] ] = true;
			}
		}

		$twins = array();
		foreach ( array_keys( $relayed ) as $post_id ) {
			foreach ( $this->context->language->post_translations( $post_id ) as $twin_id ) {
				if ( ! isset( $relayed[ $twin_id ] ) ) {
					$twins[ $twin_id ] = md5( (string) get_post_field( 'post_content', $twin_id, 'raw' ) );
				}
			}
		}

		return $twins;
	}

	/**
	 * Verifies stored content and untouched translations.
	 *
	 * @param array<string, int>    $written  Written key => id.
	 * @param array<string, string> $expected Key => md5 of the content that should be stored.
	 * @param array<int, string>    $twins    Untouched twin id => md5 before the relay.
	 * @return string[] Problems.
	 */
	private function integrity( array $written, array $expected, array $twins ): array {
		global $wpdb;

		$problems = array();
		foreach ( $written as $key => $post_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifies the stored bytes.
			$stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
			if ( md5( $stored ) !== $expected[ $key ] ) {
				$problems[] = sprintf( 'Stored content of %1$s (post %2$d) differs from what was written.', $key, $post_id );
			}
		}

		foreach ( $twins as $twin_id => $hash ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifies the stored bytes.
			$stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $twin_id ) );
			if ( md5( $stored ) !== $hash ) {
				$problems[] = sprintf( 'Translation %d, which was not part of this relay, changed during it.', $twin_id );
			}
		}

		return $problems;
	}

	/**
	 * Receiver parent of an attachment ("Uploaded to"): the relayed parent when this relay knows it, otherwise the
	 * attachment's current parent here, or none for a new attachment.
	 *
	 * @param array<string, mixed> $record  Manifest attachment.
	 * @param array<string, mixed> $item    Plan item.
	 * @param int                  $post_id Receiver attachment id.
	 * @param array<string, int>   $ids     Id map.
	 */
	private function attachment_parent( array $record, array $item, int $post_id, array $ids ): int {
		$parent = $record['upload_parent'] ?? null;
		if ( is_string( $parent ) && isset( $ids[ $parent ] ) ) {
			return $ids[ $parent ];
		}

		return in_array( $item['action'], self::NEW_FILE_ACTIONS, true ) ? 0 : (int) get_post_field( 'post_parent', $post_id, 'raw' );
	}

	/**
	 * Runs the query-dependency checks a theme declares for each written post, in the post's language.
	 *
	 * @param array<string, int>                  $written Written key => id.
	 * @param array<string, array<string, mixed>> $items   Plan items.
	 * @param array<string, array<string, mixed>> $objects Manifest objects.
	 * @return string[] Warnings.
	 */
	private function query_dependencies( array $written, array $items, array $objects ): array {
		$warnings = array();
		foreach ( $written as $key => $post_id ) {
			$post = get_post( $post_id );
			if ( 'post' !== $objects[ $key ]['kind'] || ! $post instanceof \WP_Post ) {
				continue;
			}

			$language = $objects[ $key ]['language'] ?? null;
			if ( is_string( $language ) ) {
				$this->context->language->switch_to( $language );
			}
			try {
				$warnings = array_merge( $warnings, Query_Dependencies::check( $post, (string) $items[ $key ]['label'] ) );
			} finally {
				if ( is_string( $language ) ) {
					$this->context->language->restore();
				}
			}
		}

		return $warnings;
	}

	/**
	 * Public addresses of written posts (not attachments), for page-cache purging.
	 *
	 * @param array<int, array<string, mixed>>    $described Result rows.
	 * @param array<string, array<string, mixed>> $objects   Manifest objects.
	 * @return string[]
	 */
	private function post_urls( array $described, array $objects ): array {
		$urls = array();
		foreach ( $described as $row ) {
			if ( 'post' === ( $objects[ $row['key'] ]['kind'] ?? '' ) && '' !== $row['url'] ) {
				$urls[] = (string) $row['url'];
			}
		}

		return $urls;
	}

	/**
	 * Receiver author for an object.
	 *
	 * @param array<string, mixed> $record Manifest object.
	 * @param array<string, int>   $ids    Id map.
	 */
	private function author_id( array $record, array $ids ): int {
		$author = $record['author'] ?? null;

		return is_string( $author ) && isset( $ids[ $author ] ) ? $ids[ $author ] : 0;
	}

	/**
	 * Receiver term ids by taxonomy.
	 *
	 * @param array<string, mixed> $record Manifest object.
	 * @param array<string, int>   $ids    Id map.
	 * @return array<string, int[]>
	 */
	private function term_ids( array $record, array $ids ): array {
		$terms = array();
		foreach ( (array) ( $record['terms'] ?? array() ) as $taxonomy => $keys ) {
			foreach ( (array) $keys as $key ) {
				// Absent terms map to 0: they were dropped on the sender, so they are not assigned here.
				if ( isset( $ids[ $key ] ) && $ids[ $key ] > 0 ) {
					$terms[ (string) $taxonomy ][] = $ids[ $key ];
				}
			}
		}

		return $terms;
	}
}
