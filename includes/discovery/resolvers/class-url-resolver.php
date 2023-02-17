<?php
/**
 * Site URLs inside block attributes, meta and post fields.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Discovery\Resolvers;

use ContentRelay\Discovery\Export_Context;
use ContentRelay\Discovery\Resolver;
use ContentRelay\Support\Value_Path;

defined( 'ABSPATH' ) || exit;

/**
 * Marks every string that contains an absolute URL on this site so the receiver rewrites it to its own URL, and
 * references what the URL points to (uploads → owned attachment, pages → soft link).
 */
final class Url_Resolver implements Resolver {

	/**
	 * Post fields that can contain URLs.
	 */
	private const FIELDS = array( 'post_title', 'post_excerpt' );

	/**
	 * {@inheritDoc}
	 *
	 * @param \WP_Post             $post    Sender post.
	 * @param array<string, mixed> $record  Exported object.
	 * @param Export_Context       $context Export context.
	 */
	public function collect( \WP_Post $post, array &$record, Export_Context $context ): void {
		$content = (string) ( $record['content'] ?? '' );
		if ( '' !== $content ) {
			foreach ( $context->scanner->scan( $content ) as $token ) {
				if ( ! $token->has_attrs() ) {
					continue;
				}
				$attrs = $context->codec->decode( $context->scanner->attrs_json( $content, $token ) );
				if ( null === $attrs ) {
					continue;
				}
				foreach ( Value_Path::string_leaves( $attrs ) as $leaf ) {
					$this->text(
						$record,
						$context,
						$leaf['value'],
						array(
							'in'    => 'attrs',
							'block' => $token->index,
							'path'  => $leaf['path'],
						)
					);
				}
			}
		}

		foreach ( (array) ( $record['meta'] ?? array() ) as $meta_key => $rows ) {
			foreach ( (array) $rows as $index => $raw ) {
				foreach ( Value_Path::string_leaves( maybe_unserialize( (string) $raw ) ) as $leaf ) {
					$this->text(
						$record,
						$context,
						$leaf['value'],
						array(
							'in'    => 'meta',
							'key'   => (string) $meta_key,
							'index' => (int) $index,
							'path'  => $leaf['path'],
						)
					);
				}
			}
		}

		foreach ( self::FIELDS as $field ) {
			$value = $record['fields'][ $field ] ?? '';
			if ( is_string( $value ) ) {
				$this->text(
					$record,
					$context,
					$value,
					array(
						'in'    => 'field',
						'field' => $field,
					)
				);
			}
		}
	}
}
