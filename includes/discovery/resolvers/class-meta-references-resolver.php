<?php
/**
 * Ids held in well-known meta keys.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Discovery\Resolvers;

use ContentRelay\Discovery\Export_Context;
use ContentRelay\Discovery\Resolver;
use ContentRelay\Support\Value_Path;

defined( 'ABSPATH' ) || exit;

/**
 * Featured image, Yoast social images and Yoast primary terms.
 */
final class Meta_References_Resolver implements Resolver {

	/**
	 * Meta keys holding ids. Filterable via `content_relay_meta_reference_keys`.
	 *
	 * @return array<string, array{kind: string, role: string, on_missing: string}>
	 */
	public static function rules(): array {
		$attachment = array(
			'kind'       => 'post',
			'role'       => Export_Context::ROLE_OWNED,
			'on_missing' => 'create',
		);

		$rules = array(
			'_thumbnail_id'                   => $attachment,
			'_yoast_wpseo_opengraph-image-id' => $attachment,
			'_yoast_wpseo_twitter-image-id'   => $attachment,
		);

		$filtered = apply_filters( 'content_relay_meta_reference_keys', $rules );

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
		$rules = self::rules();

		foreach ( (array) ( $record['meta'] ?? array() ) as $meta_key => $rows ) {
			$meta_key = (string) $meta_key;
			$rule     = $rules[ $meta_key ] ?? null;

			if ( null === $rule && 1 === preg_match( '/^_yoast_wpseo_primary_(.+)$/', $meta_key, $matches ) && taxonomy_exists( $matches[1] ) ) {
				$rule = array(
					'kind'       => 'term',
					'role'       => Export_Context::ROLE_REFERENCED,
					'on_missing' => 'create',
				);
			}

			if ( null === $rule ) {
				continue;
			}

			foreach ( (array) $rows as $index => $raw ) {
				$refs = array();
				foreach ( Value_Path::ids( maybe_unserialize( (string) $raw ) ) as $id ) {
					$refs[] = $context->reference( $rule['kind'], $id, $rule['role'], $rule['on_missing'], 'meta:' . $meta_key );
				}
				if ( array() !== $refs ) {
					$record['locations'][] = array(
						'in'    => 'meta',
						'key'   => $meta_key,
						'index' => (int) $index,
						'path'  => array(),
						'kind'  => $rule['kind'],
						'refs'  => $refs,
					);
				}
			}
		}
	}
}
