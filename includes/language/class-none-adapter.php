<?php
/**
 * Language adapter for single-language sites.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Every object has no language and no translations.
 */
final class None_Adapter implements Adapter {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'none';
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_language(): ?string {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function active_languages(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type.
	 */
	public function is_translatable_post_type( string $post_type ): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	public function is_translatable_taxonomy( string $taxonomy ): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function post_language( int $post_id ): ?string {
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function term_language( int $term_id, string $taxonomy ): ?string {
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function post_translations( int $post_id ): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function term_translations( int $term_id, string $taxonomy ): array {
		return array();
	}
}
