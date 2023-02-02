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
}
