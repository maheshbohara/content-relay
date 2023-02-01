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
}
