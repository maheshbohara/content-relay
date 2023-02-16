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
}
