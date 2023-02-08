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

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function term_language( int $term_id, string $taxonomy ): ?string {
		$tt_id = $this->term_taxonomy_id( $term_id, $taxonomy );
		$row   = null === $tt_id ? null : $this->row( $tt_id, 'tax_' . $taxonomy );

		return null === $row ? null : $row['language_code'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function post_translations( int $post_id ): array {
		return $this->group( $post_id, $this->post_element_type( $post_id ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function term_translations( int $term_id, string $taxonomy ): array {
		$tt_id = $this->term_taxonomy_id( $term_id, $taxonomy );
		if ( null === $tt_id ) {
			return array();
		}

		$terms = array();
		foreach ( $this->group( $tt_id, 'tax_' . $taxonomy ) as $language => $translated_tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', $translated_tt_id, $taxonomy );
			if ( $term instanceof \WP_Term ) {
				$terms[ $language ] = (int) $term->term_id;
			}
		}

		return $terms;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function post_source_language( int $post_id ): ?string {
		$row = $this->row( $post_id, $this->post_element_type( $post_id ) );

		return null === $row ? null : $row['source_language_code'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function term_source_language( int $term_id, string $taxonomy ): ?string {
		$tt_id = $this->term_taxonomy_id( $term_id, $taxonomy );
		$row   = null === $tt_id ? null : $this->row( $tt_id, 'tax_' . $taxonomy );

		return null === $row ? null : $row['source_language_code'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * An original (no `$original_post_id`) that already belongs to a translation group in the same language keeps that
	 * group. Passing `trid => null` to WPML starts a new group, which would split an updated original from translations
	 * this relay left untouched (scenario 10: EN `update`, FR `unchanged`).
	 *
	 * @param int      $post_id          Post id.
	 * @param string   $language         Language code.
	 * @param int|null $original_post_id Original post id.
	 */
	public function link_post( int $post_id, string $language, ?int $original_post_id ): void {
		$type = $this->post_element_type( $post_id );
		if ( '' === $type ) {
			return;
		}

		$group = null === $original_post_id ? $this->row( $post_id, $type ) : $this->row( $original_post_id, $type );
		if ( null === $original_post_id && null !== $group && $group['language_code'] !== $language ) {
			$group = null;
		}

		$this->set_details( $post_id, $type, $language, $group );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int      $term_id          Term id.
	 * @param string   $taxonomy         Taxonomy.
	 * @param string   $language         Language code.
	 * @param int|null $original_term_id Original term id.
	 */
	public function link_term( int $term_id, string $taxonomy, string $language, ?int $original_term_id ): void {
		$tt_id = $this->term_taxonomy_id( $term_id, $taxonomy );
		if ( null === $tt_id ) {
			return;
		}

		$original_tt_id = null === $original_term_id ? null : $this->term_taxonomy_id( $original_term_id, $taxonomy );
		$original_row   = null === $original_tt_id ? null : $this->row( $original_tt_id, 'tax_' . $taxonomy );

		$this->set_details( $tt_id, 'tax_' . $taxonomy, $language, $original_row );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string|null $language Language code, or null for all languages.
	 */
	public function switch_to( ?string $language ): void {
		$current                = apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API hook.
		$this->language_stack[] = is_string( $current ) ? $current : null;

		do_action( 'wpml_switch_language', null === $language ? 'all' : $language ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API hook.
	}

	/**
	 * {@inheritDoc}
	 */
	public function restore(): void {
		if ( array() === $this->language_stack ) {
			return;
		}

		do_action( 'wpml_switch_language', array_pop( $this->language_stack ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API hook.
	}

	/**
	 * {@inheritDoc}
	 */
	public function flush_caches(): void {
		do_action( 'wpml_cache_clear' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API hook.
		if ( function_exists( 'icl_cache_clear' ) ) {
			icl_cache_clear();
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function export_post_state( int $post_id ): ?array {
		return $this->row( $post_id, $this->post_element_type( $post_id ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                       $post_id Post id.
	 * @param array<string, mixed>|null $state   State.
	 */
	public function import_post_state( int $post_id, ?array $state ): void {
		global $wpdb;

		$type = $this->post_element_type( $post_id );
		if ( '' === $type ) {
			return;
		}
		if ( null === $state ) {
			$this->unlink_post( $post_id );
			return;
		}

		$data = array(
			'trid'                 => (int) $state['trid'],
			'language_code'        => (string) $state['language_code'],
			'source_language_code' => $state['source_language_code'],
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restores a backed-up icl_translations row exactly.
		if ( null === $this->row( $post_id, $type ) ) {
			$wpdb->insert(
				$wpdb->prefix . 'icl_translations',
				$data + array(
					'element_id'   => $post_id,
					'element_type' => $type,
				)
			);
		} else {
			$wpdb->update(
				$wpdb->prefix . 'icl_translations',
				$data,
				array(
					'element_id'   => $post_id,
					'element_type' => $type,
				)
			);
		}
		// phpcs:enable
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post id.
	 */
	public function unlink_post( int $post_id ): void {
		global $wpdb;

		$type = $this->post_element_type( $post_id );
		if ( '' === $type ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes the row of a post the relay created and is deleting.
		$wpdb->delete(
			$wpdb->prefix . 'icl_translations',
			array(
				'element_id'   => $post_id,
				'element_type' => $type,
			)
		);
	}

	/**
	 * WPML element type of a post (`post_page`), or '' when the post does not exist.
	 *
	 * @param int $post_id Post id.
	 */
	private function post_element_type( int $post_id ): string {
		$post_type = get_post_type( $post_id );

		return false === $post_type ? '' : 'post_' . $post_type;
	}

	/**
	 * Term taxonomy id of a term.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @return int|null
	 */
	private function term_taxonomy_id( int $term_id, string $taxonomy ): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read after direct writes; WPML's term filters must not scope this lookup to the current language.
		$tt_id = $wpdb->get_var( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s", $term_id, $taxonomy ) );

		return null === $tt_id ? null : (int) $tt_id;
	}
}
