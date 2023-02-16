<?php
/**
 * Ids held in ACF fields, in block data and in post meta.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Discovery\Resolvers;

use ContentRelay\Discovery\Export_Context;
use ContentRelay\Discovery\Resolver;
use ContentRelay\Support\Html_References;
use ContentRelay\Support\Value_Path;

defined( 'ABSPATH' ) || exit;

/**
 * Type-driven ACF resolver.
 *
 * ACF stores every value beside its field key (`hero_image` + `_hero_image` => `field_…`), including flattened
 * repeater rows (`rows_0_image`). The field's type decides whether the value is a reference, so no per-block code
 * is needed.
 */
final class Acf_Resolver implements Resolver {

	/**
	 * {@inheritDoc}
	 *
	 * @param \WP_Post             $post    Sender post.
	 * @param array<string, mixed> $record  Exported object.
	 * @param Export_Context       $context Export context.
	 */
	public function collect( \WP_Post $post, array &$record, Export_Context $context ): void {
		if ( ! function_exists( 'acf_get_field' ) ) {
			return;
		}

		$content = (string) ( $record['content'] ?? '' );
		if ( '' !== $content ) {
			foreach ( $context->scanner->scan( $content ) as $token ) {
				if ( ! $token->has_attrs() ) {
					continue;
				}
				$attrs = $context->codec->decode( $context->scanner->attrs_json( $content, $token ) );
				$data  = null === $attrs ? null : ( $attrs->data ?? null );
				if ( ! $data instanceof \stdClass ) {
					continue;
				}

				$values = get_object_vars( $data );
				foreach ( $values as $name => $value ) {
					$field_key = $this->field_key( $values, (string) $name );
					if ( null !== $field_key ) {
						$this->field(
							$record,
							$context,
							$field_key,
							$value,
							array(
								'in'    => 'attrs',
								'block' => $token->index,
								'path'  => array( 'data', (string) $name ),
							)
						);
					}
				}
			}
		}

		$meta = (array) ( $record['meta'] ?? array() );
		foreach ( $meta as $meta_key => $rows ) {
			$sibling   = $meta[ '_' . $meta_key ][0] ?? null;
			$field_key = is_string( $sibling ) && 0 === strpos( $sibling, 'field_' ) && '_' !== substr( (string) $meta_key, 0, 1 ) ? $sibling : null;
			if ( null === $field_key ) {
				continue;
			}
			foreach ( (array) $rows as $index => $raw ) {
				$this->field(
					$record,
					$context,
					$field_key,
					maybe_unserialize( (string) $raw ),
					array(
						'in'    => 'meta',
						'key'   => (string) $meta_key,
						'index' => (int) $index,
						'path'  => array(),
					)
				);
			}
		}
	}
}
