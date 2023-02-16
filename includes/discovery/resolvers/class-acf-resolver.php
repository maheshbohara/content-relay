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

	/**
	 * The field key stored beside a value, or null when the value is not an ACF field.
	 *
	 * @param array<string, mixed> $values Block data.
	 * @param string               $name   Value name.
	 */
	private function field_key( array $values, string $name ): ?string {
		if ( '' === $name || '_' === $name[0] ) {
			return null;
		}

		$key = $values[ '_' . $name ] ?? null;

		return is_string( $key ) && 0 === strpos( $key, 'field_' ) ? $key : null;
	}

	/**
	 * Records references for one field value.
	 *
	 * @param array<string, mixed> $record    Exported object.
	 * @param Export_Context       $context   Export context.
	 * @param string               $field_key ACF field key.
	 * @param mixed                $value     Field value.
	 * @param array<string, mixed> $where     Location without kind and refs.
	 */
	private function field( array &$record, Export_Context $context, string $field_key, $value, array $where ): void {
		$field = acf_get_field( $field_key );
		if ( ! is_array( $field ) ) {
			$context->warn( sprintf( 'ACF field %1$s used in %2$s is not registered on this site; its value is relayed unchanged.', $field_key, $record['key'] ) );
			return;
		}

		$type = (string) $field['type'];
		$context->require( 'acf_fields', $field_key, $type );

		switch ( $type ) {
			case 'image':
			case 'file':
			case 'gallery':
				$this->ids( $record, $context, $where, $value, 'post', Export_Context::ROLE_OWNED, 'create', 'acf:' . $type );
				break;

			case 'post_object':
			case 'relationship':
			case 'page_link':
				$this->ids( $record, $context, $where, $value, 'post', Export_Context::ROLE_REFERENCED, 'block', 'acf:' . $type );
				break;

			case 'taxonomy':
				$this->ids( $record, $context, $where, $value, 'term', Export_Context::ROLE_REFERENCED, 'create', 'acf:taxonomy' );
				break;

			case 'user':
				$this->ids( $record, $context, $where, $value, 'user', Export_Context::ROLE_MAPPED, 'fallback', 'acf:user' );
				break;

			case 'wysiwyg':
				if ( is_string( $value ) ) {
					$refs = array();
					foreach ( Html_References::attachment_ids( $value ) as $id ) {
						$refs[] = $context->reference( 'post', $id, Export_Context::ROLE_OWNED, 'create', 'acf:wysiwyg' );
					}
					if ( array() !== $refs ) {
						$record['locations'][] = $where + array(
							'kind' => 'html',
							'refs' => $refs,
						);
					}
				}
				break;
		}
	}
}
