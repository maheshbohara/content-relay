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
}
