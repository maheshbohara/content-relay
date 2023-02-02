<?php
/**
 * Chooses the language adapter for this site.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter selection.
 */
final class Adapter_Factory {

	/**
	 * Returns the adapter for the active multilingual plugin, filterable via `content_relay_language_adapter`.
	 */
	public static function create(): Adapter {
		$adapter  = Wpml_Adapter::is_available() ? new Wpml_Adapter() : new None_Adapter();
		$filtered = apply_filters( 'content_relay_language_adapter', $adapter );

		return $filtered instanceof Adapter ? $filtered : $adapter;
	}
}
