<?php
/**
 * Resolver contract.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * Finds references in one owned object and records where they live.
 *
 * A resolver appends to `$record['locations']` and calls Export_Context::reference() for every object it finds.
 * Locations are interpreted on the receiver by kind, so resolvers have no receiver-side code:
 *
 * - `{in: field, field, kind, refs}`             a post field (`post_parent`, `post_author`, `post_excerpt`)
 * - `{in: meta, key, index, path, kind, refs}`   a meta row; `path` points into the unserialized value
 * - `{in: attrs, block, path, kind, refs}`       a block attribute
 * - `{in: html, block, kind: html, refs}`        a block's own HTML (block -1 = HTML outside blocks)
 *
 * Kinds: `post`, `term`, `user` (id values), `text` (site URLs inside a string), `html` (ids and URLs in HTML).
 */
interface Resolver {

	/**
	 * Collects references from an owned object.
	 *
	 * @param \WP_Post             $post    Sender post (attachments included).
	 * @param array<string, mixed> $record  Exported object, modified in place.
	 * @param Export_Context       $context Export context.
	 */
	public function collect( \WP_Post $post, array &$record, Export_Context $context ): void;
}
