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

	/**
	 * Language the post was translated from, or null when it is the original.
	 *
	 * @param int $post_id Post id.
	 */
	public function post_source_language( int $post_id ): ?string;

	/**
	 * Language the term was translated from, or null when it is the original.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function term_source_language( int $term_id, string $taxonomy ): ?string;

	/**
	 * Assigns a post's language and links it to the translation group of an original.
	 *
	 * @param int      $post_id          Post id.
	 * @param string   $language         Language code.
	 * @param int|null $original_post_id Original post on this site, or null when the post is the original.
	 */
	public function link_post( int $post_id, string $language, ?int $original_post_id ): void;

	/**
	 * Assigns a term's language and links it to the translation group of an original.
	 *
	 * @param int      $term_id          Term id.
	 * @param string   $taxonomy         Taxonomy.
	 * @param string   $language         Language code.
	 * @param int|null $original_term_id Original term on this site, or null when the term is the original.
	 */
	public function link_term( int $term_id, string $taxonomy, string $language, ?int $original_term_id ): void;

	/**
	 * Switches the current language (permalinks, queries, term inserts). Null switches to all languages.
	 *
	 * @param string|null $language Language code.
	 */
	public function switch_to( ?string $language ): void;
}
