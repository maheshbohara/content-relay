<?php
/**
 * Attachment ids embedded in HTML.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Finds and rewrites `wp-image-N` classes and `[gallery ids="…"]` shortcodes.
 */
final class Html_References {

	/**
	 * `wp-image-N` class pattern.
	 */
	private const WP_IMAGE = '/\bwp-image-(\d+)\b/';

	/**
	 * `[gallery … ids="1,2"]` pattern; group 2 is the id list.
	 */
	private const GALLERY = '/(\[gallery\b[^\]]*?\bids=["\']?)([0-9][0-9,\s]*)/';

	/**
	 * Returns the attachment ids referenced in HTML.
	 *
	 * @param string $html HTML.
	 * @return int[]
	 */
	public static function attachment_ids( string $html ): array {
		$ids = array();

		if ( preg_match_all( self::WP_IMAGE, $html, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$ids[] = (int) $id;
			}
		}

		if ( preg_match_all( self::GALLERY, $html, $matches ) ) {
			foreach ( $matches[2] as $list ) {
				foreach ( explode( ',', $list ) as $id ) {
					$ids[] = (int) trim( $id );
				}
			}
		}

		return array_values(
			array_unique(
				array_filter(
					$ids,
					static function ( int $id ): bool {
						return $id > 0;
					}
				)
			)
		);
	}
}
