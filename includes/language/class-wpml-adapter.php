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
}
