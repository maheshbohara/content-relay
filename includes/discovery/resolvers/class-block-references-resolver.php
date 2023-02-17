<?php
/**
 * Ids held in core block attributes.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Discovery\Resolvers;

use ContentRelay\Discovery\Export_Context;
use ContentRelay\Discovery\Resolver;
use ContentRelay\Support\Value_Path;

defined( 'ABSPATH' ) || exit;

/**
 * Records block requirements and the ids core blocks store in their attributes.
 */
final class Block_References_Resolver implements Resolver {

	/**
	 * Attribute paths holding ids, per block. Filterable via `content_relay_block_references`.
	 *
	 * @return array<string, array<int, array{path: array<int, string>, kind: string, role: string, on_missing: string}>>
	 */
	public static function rules(): array {
		$media  = static function ( string $attribute ): array {
			return array(
				'path'       => array( $attribute ),
				'kind'       => 'post',
				'role'       => Export_Context::ROLE_OWNED,
				'on_missing' => 'create',
			);
		};
		$entity = static function ( string $attribute ): array {
			return array(
				'path'       => array( $attribute ),
				'kind'       => 'post',
				'role'       => Export_Context::ROLE_REFERENCED,
				'on_missing' => 'block',
			);
		};

		$rules = array(
			'core/image'      => array( $media( 'id' ) ),
			'core/cover'      => array( $media( 'id' ) ),
			'core/media-text' => array( $media( 'mediaId' ) ),
			'core/file'       => array( $media( 'id' ) ),
			'core/video'      => array( $media( 'id' ) ),
			'core/audio'      => array( $media( 'id' ) ),
			'core/gallery'    => array( $media( 'ids' ) ),
			'core/block'      => array( $entity( 'ref' ) ),
			'core/navigation' => array( $entity( 'ref' ) ),
		);

		$filtered = apply_filters( 'content_relay_block_references', $rules );

		return is_array( $filtered ) ? $filtered : $rules;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WP_Post             $post    Sender post.
	 * @param array<string, mixed> $record  Exported object.
	 * @param Export_Context       $context Export context.
	 */
	public function collect( \WP_Post $post, array &$record, Export_Context $context ): void {
		$content = (string) ( $record['content'] ?? '' );
		if ( '' === $content ) {
			return;
		}

		$rules = self::rules();

		foreach ( $context->scanner->scan( $content ) as $token ) {
			$context->require( 'blocks', $token->name );

			if ( ! $token->has_attrs() ) {
				continue;
			}

			$attrs = $context->codec->decode( $context->scanner->attrs_json( $content, $token ) );
			if ( null === $attrs ) {
				$context->warn( sprintf( 'Block %1$d (%2$s) in %3$s has attributes that are not valid JSON. It is relayed byte-for-byte, but it renders without its data on both sites.', $token->index, $token->name, $record['key'] ) );
				continue;
			}

			foreach ( $rules[ $token->name ] ?? array() as $rule ) {
				$this->add( $record, $context, $token->index, $rule['path'], Value_Path::get( $attrs, $rule['path'] ), $rule['kind'], $rule['role'], $rule['on_missing'], $token->name );
			}

			if ( 'core/query' === $token->name ) {
				$tax_query = Value_Path::get( $attrs, array( 'query', 'taxQuery' ) );
				if ( $tax_query instanceof \stdClass ) {
					foreach ( get_object_vars( $tax_query ) as $taxonomy => $ids ) {
						$this->add( $record, $context, $token->index, array( 'query', 'taxQuery', (string) $taxonomy ), $ids, 'term', Export_Context::ROLE_REFERENCED, 'create', 'core/query' );
					}
				}
			}
		}
	}

	/**
	 * Adds a location for the ids found at a path.
	 *
	 * @param array<string, mixed>   $record     Exported object.
	 * @param Export_Context         $context    Export context.
	 * @param int                    $block      Block index.
	 * @param array<int, string|int> $path       Attribute path.
	 * @param mixed                  $value      Value at the path.
	 * @param string                 $kind       Reference kind.
	 * @param string                 $role       Role.
	 * @param string                 $on_missing Missing policy.
	 * @param string                 $relation   Relation label.
	 */
	private function add( array &$record, Export_Context $context, int $block, array $path, $value, string $kind, string $role, string $on_missing, string $relation ): void {
		$refs = array();
		foreach ( Value_Path::ids( $value ) as $id ) {
			$refs[] = $context->reference( $kind, $id, $role, $on_missing, $relation );
		}

		if ( array() !== $refs ) {
			$record['locations'][] = array(
				'in'    => 'attrs',
				'block' => $block,
				'path'  => $path,
				'kind'  => $kind,
				'refs'  => $refs,
			);
		}
	}
}
