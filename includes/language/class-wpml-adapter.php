<?php
/**
 * Language adapter for WPML.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Reads translation data straight from `icl_translations` (so a relay sees the database as it is, not a cached
 * view) and writes it through WPML's own `wpml_set_element_language_details` action.
 *
 * WPML addresses terms by term_taxonomy_id; this adapter takes and returns term ids.
 */
final class Wpml_Adapter implements Adapter {

	/**
	 * Languages active before each switch_to() call.
	 *
	 * @var array<int, string|null>
	 */
	private array $language_stack = array();

	/**
	 * Whether WPML is loaded.
	 */
	public static function is_available(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'wpml';
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_language(): ?string {
		$language = apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API hook.

		return is_string( $language ) && '' !== $language ? $language : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function active_languages(): array {
		$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API hook.

		return is_array( $languages ) ? array_map( 'strval', array_keys( $languages ) ) : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type.
	 */
	public function is_translatable_post_type( string $post_type ): bool {
		return (bool) apply_filters( 'wpml_is_translated_post_type', null, $post_type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API hook.
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	public function is_translatable_taxonomy( string $taxonomy ): bool {
		return (bool) apply_filters( 'wpml_is_translated_taxonomy', null, $taxonomy ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API hook.
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function post_language( int $post_id ): ?string {
		$row = $this->row( $post_id, $this->post_element_type( $post_id ) );

		return null === $row ? null : $row['language_code'];
	}
}
