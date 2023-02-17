<?php
/**
 * Attachment ids and site URLs in block HTML.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Discovery\Resolvers;

use ContentRelay\Content\Content_Editor;
use ContentRelay\Discovery\Export_Context;
use ContentRelay\Discovery\Resolver;
use ContentRelay\Support\Html_References;

defined( 'ABSPATH' ) || exit;

/**
 * Scans each block's own HTML (and HTML outside blocks) for `wp-image-N`, gallery shortcodes and site URLs.
 */
final class Html_Resolver implements Resolver {

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

		$tokens  = $context->scanner->scan( $content );
		$editor  = new Content_Editor( $context->scanner, $context->codec );
		$length  = strlen( $content );
		$indexes = array_merge( array( Content_Editor::FREEFORM ), array_keys( $tokens ) );

		foreach ( $indexes as $index ) {
			$refs    = array();
			$has_url = false;

			foreach ( $editor->own_html_segments( $tokens, (int) $index, $length ) as $segment ) {
				$html = substr( $content, $segment[0], $segment[1] - $segment[0] );

				foreach ( Html_References::attachment_ids( $html ) as $id ) {
					$refs[] = $context->reference( 'post', $id, Export_Context::ROLE_OWNED, 'create', 'html:wp-image' );
				}

				foreach ( $context->urls_in( $html ) as $url ) {
					$has_url = true;
					$context->reference_url( $url, 'html:link' );
				}
			}

			if ( array() !== $refs || $has_url ) {
				$record['locations'][] = array(
					'in'    => 'html',
					'block' => (int) $index,
					'kind'  => 'html',
					'refs'  => array_values( array_unique( $refs ) ),
				);
			}
		}
	}
}
