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
	 * Matches a user by login, then email, then falls back to the acting administrator.
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

		$fallback = get_current_user_id();
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
}
