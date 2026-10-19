<?php
/**
 * Resolves manifest objects to objects on this site.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Receive;

defined( 'ABSPATH' ) || exit;

/**
 * Identity matching (spec §8.1). Always scoped by language; never guesses: more than one candidate is reported as
 * ambiguous and the planner blocks.
 */
final class Matcher {

	/**
	 * Post statuses that never count as an existing post.
	 */
	private const IGNORED_STATUSES = array( 'trash', 'auto-draft', 'inherit' );

	/**
	 * Receiver context.
	 *
	 * @var Receive_Context
	 */
	private Receive_Context $context;

	/**
	 * Results by object key.
	 *
	 * @var array<string, array{id: int|null, via: string, candidates: int[], path_conflict: bool}>
	 */
	private array $matches = array();

	/**
	 * Constructor.
	 *
	 * @param Receive_Context $context Receiver context.
	 */
	public function __construct( Receive_Context $context ) {
		$this->context = $context;
	}

	/**
	 * Matches every object in a manifest.
	 *
	 * Posts are matched in three passes so the translation pass can use UUID matches: UUID, then a translation of an
	 * already-matched language version, then path.
	 *
	 * @param array<string, array<string, mixed>> $objects Manifest objects.
	 * @return array<string, array{id: int|null, via: string, candidates: int[], path_conflict: bool}>
	 */
	public function match_all( array $objects ): array {
		$this->matches = array();
		$posts         = array();

		foreach ( $objects as $key => $record ) {
			switch ( $record['kind'] ) {
				case 'user':
					$this->matches[ $key ] = $this->match_user( $record );
					break;
				case 'term':
					$this->matches[ $key ] = $this->match_term( $record );
					break;
				case 'attachment':
					$this->matches[ $key ] = $this->match_attachment( $record );
					break;
				default:
					$posts[ $key ]         = $record;
					$this->matches[ $key ] = $this->by_uuid( $record );
			}
		}

		foreach ( $posts as $key => $record ) {
			if ( null === $this->matches[ $key ]['id'] && array() === $this->matches[ $key ]['candidates'] ) {
				$this->matches[ $key ] = $this->by_translation( $record, $objects );
			}
		}

		foreach ( $posts as $key => $record ) {
			if ( null === $this->matches[ $key ]['id'] && array() === $this->matches[ $key ]['candidates'] ) {
				$this->matches[ $key ] = $this->result( $this->posts_at_path( $record ), 'path' );
			}
		}

		return $this->matches;
	}

	/**
	 * Matches a user by login, then email, then falls back to the context's author, the current user or an administrator.
	 *
	 * @param array<string, mixed> $record Manifest user.
	 * @return array{id: int|null, via: string, candidates: int[], path_conflict: bool}
	 */
	private function match_user( array $record ): array {
		$user = '' === $record['login'] ? false : get_user_by( 'login', (string) $record['login'] );
		if ( $user instanceof \WP_User ) {
			return $this->result( array( (int) $user->ID ), 'login' );
		}

		$user = '' === $record['email'] ? false : get_user_by( 'email', (string) $record['email'] );
		if ( $user instanceof \WP_User ) {
			return $this->result( array( (int) $user->ID ), 'email' );
		}

		$fallback = 0 === $this->context->fallback_author ? get_current_user_id() : $this->context->fallback_author;
		if ( 0 === $fallback ) {
			$admins   = get_users(
				array(
					'role'    => 'administrator',
					'number'  => 1,
					'orderby' => 'ID',
					'fields'  => 'ID',
				)
			);
			$fallback = (int) ( $admins[0] ?? 0 );
		}

		return $this->result( 0 === $fallback ? array() : array( $fallback ), 'fallback' );
	}

	/**
	 * Matches a term by UUID, then taxonomy + slug, within its language.
	 *
	 * @param array<string, mixed> $record Manifest term.
	 * @return array{id: int|null, via: string, candidates: int[], path_conflict: bool}
	 */
	private function match_term( array $record ): array {
		global $wpdb;

		$taxonomy = (string) $record['taxonomy'];

		if ( is_string( $record['uuid'] ?? null ) ) {
			$ids = array_filter(
				$this->context->uuid->find_terms( $record['uuid'] ),
				function ( int $term_id ) use ( $record, $taxonomy ): bool {
					return $this->context->language->term_language( $term_id, $taxonomy ) === $record['language'];
				}
			);
			if ( array() !== $ids ) {
				return $this->result( array_values( $ids ), 'uuid' );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WPML filters term queries by the current language; matching must see every term.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT t.term_id FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s AND t.slug = %s", $taxonomy, (string) $record['slug'] ) );

		$ids = array_filter(
			array_map( 'intval', $ids ),
			function ( int $term_id ) use ( $record, $taxonomy ): bool {
				return $this->context->language->term_language( $term_id, $taxonomy ) === $record['language'];
			}
		);

		return $this->result( array_values( $ids ), 'slug' );
	}

	/**
	 * Matches an attachment by UUID, then by file (same path and same bytes) within its language.
	 *
	 * @param array<string, mixed> $record Manifest attachment.
	 * @return array{id: int|null, via: string, candidates: int[], path_conflict: bool}
	 */
	private function match_attachment( array $record ): array {
		global $wpdb;

		$by_uuid = $this->by_uuid( $record );
		if ( null !== $by_uuid['id'] || array() !== $by_uuid['candidates'] ) {
			return $by_uuid;
		}

		$file = $record['file'] ?? null;
		if ( ! is_array( $file ) ) {
			return $this->result( array(), 'none' );
		}

		$paths = array_values( array_unique( array_filter( array( (string) $file['relative'], (string) $file['source'] ) ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated for each path.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN (" . implode( ',', array_fill( 0, count( $paths ), '%s' ) ) . ')', $paths ) );

		$same_file     = array();
		$path_conflict = false;
		foreach ( array_map( 'intval', $ids ) as $attachment_id ) {
			if ( $this->context->language->post_language( $attachment_id ) !== $record['language'] ) {
				continue;
			}
			if ( $this->context->attachment_sha1( $attachment_id ) === $file['sha1'] ) {
				$same_file[] = $attachment_id;
			} else {
				$path_conflict = true;
			}
		}

		// Duplicate attachments of one file: on sites cloned from one database the sender's own id is the same
		// object here, so it settles the tie. Anything else stays ambiguous and blocks.
		$source_id = (int) substr( (string) ( $record['key'] ?? '' ), strlen( 'post:' ) );
		if ( count( array_unique( $same_file ) ) > 1 && in_array( $source_id, $same_file, true ) ) {
			$same_file = array( $source_id );
		}

		$result                  = $this->result( $same_file, 'file' );
		$result['path_conflict'] = $path_conflict || $this->path_taken_by_other_bytes( $file );

		return $result;
	}

	/**
	 * Matches a post or attachment by UUID within its post type and language.
	 *
	 * @param array<string, mixed> $record Manifest object.
	 * @return array{id: int|null, via: string, candidates: int[], path_conflict: bool}
	 */
	private function by_uuid( array $record ): array {
		if ( ! is_string( $record['uuid'] ?? null ) ) {
			return $this->result( array(), 'uuid' );
		}

		$ids = array_filter(
			$this->context->uuid->find_posts( $record['uuid'] ),
			function ( int $post_id ) use ( $record ): bool {
				$post = get_post( $post_id );
				return $post instanceof \WP_Post
					&& $post->post_type === $record['post_type']
					&& ( 'attachment' === $post->post_type || ! in_array( $post->post_status, self::IGNORED_STATUSES, true ) )
					&& $this->context->language->post_language( $post_id ) === $record['language'];
			}
		);

		return $this->result( array_values( $ids ), 'uuid' );
	}

	/**
	 * Matches a post through a language version that already matched.
	 *
	 * @param array<string, mixed>                $record  Manifest post.
	 * @param array<string, array<string, mixed>> $objects Manifest objects.
	 * @return array{id: int|null, via: string, candidates: int[], path_conflict: bool}
	 */
	private function by_translation( array $record, array $objects ): array {
		if ( null === $record['language'] ) {
			return $this->result( array(), 'translation' );
		}

		foreach ( (array) ( $record['translations'] ?? array() ) as $sibling_key ) {
			$sibling_id = $this->matches[ $sibling_key ]['id'] ?? null;
			if ( $sibling_key === $record['key'] || null === $sibling_id || ! isset( $objects[ $sibling_key ] ) ) {
				continue;
			}
			$translations = $this->context->language->post_translations( $sibling_id );
			if ( isset( $translations[ $record['language'] ] ) ) {
				return $this->result( array( (int) $translations[ $record['language'] ] ), 'translation' );
			}
		}

		return $this->result( array(), 'translation' );
	}

	/**
	 * Posts at a hierarchical path, in the object's post type and language.
	 *
	 * @param array<string, mixed> $record Manifest post.
	 * @return int[]
	 */
	private function posts_at_path( array $record ): array {
		global $wpdb;

		$segments = array_values(
			array_filter(
				explode( '/', trim( (string) $record['path'], '/' ) ),
				static function ( string $segment ): bool {
					return '' !== $segment;
				}
			)
		);
		if ( array() === $segments ) {
			return array();
		}

		$slug = (string) end( $segments );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- get_page_by_path() is not language-aware and returns the first of same-slug translations.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_parent, post_status FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s", (string) $record['post_type'], $slug ), ARRAY_A );

		$ids       = array();
		$any_lang  = array();
		$same_id   = false;
		$source_id = (int) substr( (string) ( $record['key'] ?? '' ), strlen( 'post:' ) );
		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row['ID'];
			if ( in_array( $row['post_status'], self::IGNORED_STATUSES, true ) ) {
				continue;
			}
			if ( ! $this->ancestors_match( (int) $row['post_parent'], array_slice( $segments, 0, -1 ) ) ) {
				continue;
			}
			$any_lang[] = $post_id;
			$language   = $this->context->language->post_language( $post_id );
			if ( $post_id === $source_id && ( null === $language || $language === $record['language'] ) ) {
				$same_id = true;
			}
			if ( $language !== $record['language'] ) {
				continue;
			}
			$ids[] = $post_id;
		}

		// Same address and the sender's own id, with no language or the same one: on sites cloned from one database
		// that is this object, even when another post of that language shares the slug (duplicates are common).
		if ( $same_id ) {
			return array( $source_id );
		}

		// A referenced post the sender has no language for (a stray copy outside WPML) can only be found by its
		// address. One candidate is taken; among several, the sender's own id settles it on sites cloned from one
		// database. Owned posts never take this path: adopting would change a translated post's language.
		if ( array() === $ids && null === $record['language'] && 'referenced' === ( $record['role'] ?? '' ) ) {
			if ( 1 === count( $any_lang ) ) {
				$ids = $any_lang;
			} elseif ( in_array( $source_id, $any_lang, true ) ) {
				$ids = array( $source_id );
			}
		}

		return $ids;
	}

	/**
	 * Whether a parent chain spells out the given ancestor slugs exactly.
	 *
	 * @param int      $parent_id Parent post id.
	 * @param string[] $segments  Ancestor slugs, root first.
	 */
	private function ancestors_match( int $parent_id, array $segments ): bool {
		global $wpdb;

		while ( array() !== $segments ) {
			if ( 0 === $parent_id ) {
				return false;
			}
			$slug = array_pop( $segments );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Walks the stored parent chain.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_name, post_parent FROM {$wpdb->posts} WHERE ID = %d", $parent_id ), ARRAY_A );
			if ( ! is_array( $row ) || $row['post_name'] !== $slug ) {
				return false;
			}
			$parent_id = (int) $row['post_parent'];
		}

		return 0 === $parent_id;
	}

	/**
	 * Whether the file's path exists on disk with different bytes (an orphan or another site's upload).
	 *
	 * @param array<string, mixed> $file Manifest file.
	 */
	private function path_taken_by_other_bytes( array $file ): bool {
		$path = $this->context->uploads_dir . '/' . $file['source'];

		return is_readable( $path ) && sha1_file( $path ) !== $file['sha1'];
	}

	/**
	 * Builds a result from candidate ids.
	 *
	 * @param int[]  $ids Candidate ids.
	 * @param string $via How they were found.
	 * @return array{id: int|null, via: string, candidates: int[], path_conflict: bool}
	 */
	private function result( array $ids, string $via ): array {
		$ids = array_values( array_unique( $ids ) );

		return array(
			'id'            => 1 === count( $ids ) ? $ids[0] : null,
			'via'           => array() === $ids ? 'none' : $via,
			'candidates'    => $ids,
			'path_conflict' => false,
		);
	}
}
