<?php
/**
 * Language adapter contract: everything Content Relay needs from a multilingual plugin.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Multilingual plugin adapter. Terms are always addressed by term id; adapters translate to whatever id their
 * plugin stores internally.
 */
interface Adapter {

	/**
	 * Adapter id: `none`, `wpml`.
	 */
	public function id(): string;

	/**
	 * Default language code, or null on a single-language site.
	 */
	public function default_language(): ?string;

	/**
	 * Active language codes.
	 *
	 * @return string[]
	 */
	public function active_languages(): array;

	/**
	 * Whether posts of a type have languages.
	 *
	 * @param string $post_type Post type.
	 */
	public function is_translatable_post_type( string $post_type ): bool;

	/**
	 * Whether terms of a taxonomy have languages.
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	public function is_translatable_taxonomy( string $taxonomy ): bool;

	/**
	 * Language of a post, or null when it has none.
	 *
	 * @param int $post_id Post id (attachments included).
	 */
	public function post_language( int $post_id ): ?string;

	/**
	 * Language of a term, or null when it has none.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function term_language( int $term_id, string $taxonomy ): ?string;

	/**
	 * Every language version of a post, including the post itself.
	 *
	 * @param int $post_id Post id.
	 * @return array<string, int> Language code => post id. Empty when the post has no language.
	 */
	public function post_translations( int $post_id ): array;

	/**
	 * Every language version of a term, including the term itself.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @return array<string, int> Language code => term id.
	 */
	public function term_translations( int $term_id, string $taxonomy ): array;
}
