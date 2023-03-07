<?php
/**
 * Applies a manifest object's locations using receiver ids and URLs.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Apply;

use ContentRelay\Content\Content_Exception;
use ContentRelay\Receive\Receive_Context;
use ContentRelay\Support\Html_References;
use ContentRelay\Support\Value_Path;

defined( 'ABSPATH' ) || exit;

/**
 * Interprets locations by kind (see Discovery\Resolver). Content is edited in place, never re-serialized.
 */
final class Rewriter {

	/**
	 * Fields the post writer sets from `author` and `parent` instead of from locations.
	 */
	private const WRITER_FIELDS = array( 'post_author', 'post_parent' );

	/**
	 * Receiver context.
	 *
	 * @var Receive_Context
	 */
	private Receive_Context $context;

	/**
	 * URL map.
	 *
	 * @var Url_Map
	 */
	private Url_Map $urls;

	/**
	 * Object key => receiver id.
	 *
	 * @var array<string, int>
	 */
	private array $ids;

	/**
	 * Constructor.
	 *
	 * @param Receive_Context    $context Receiver context.
	 * @param Url_Map            $urls    URL map.
	 * @param array<string, int> $ids     Object key => receiver id.
	 */
	public function __construct( Receive_Context $context, Url_Map $urls, array $ids ) {
		$this->context = $context;
		$this->urls    = $urls;
		$this->ids     = $ids;
	}

	/**
	 * Returns the object's content, fields and meta with every location rewritten.
	 *
	 * @param array<string, mixed> $record Manifest object.
	 * @return array{content: string, fields: array<string, mixed>, meta: array<string, string[]>}
	 *
	 * @throws Apply_Exception When a reference has no receiver object or content cannot be edited safely.
	 */
	public function rewrite( array $record ): array {
		$content   = (string) ( $record['content'] ?? '' );
		$fields    = (array) ( $record['fields'] ?? array() );
		$meta      = (array) ( $record['meta'] ?? array() );
		$per_block = array();
		$html      = array();

		foreach ( (array) ( $record['locations'] ?? array() ) as $location ) {
			$this->assert_refs( $location, (string) $record['key'] );

			switch ( $location['in'] ) {
				case 'attrs':
					$per_block[ (int) $location['block'] ][] = $location;
					break;

				case 'html':
					$html[ (int) $location['block'] ] = function ( string $segment ): string {
						return $this->html( $segment );
					};
					break;

				case 'meta':
					$key   = (string) $location['key'];
					$index = (int) $location['index'];
					if ( isset( $meta[ $key ][ $index ] ) ) {
						$meta[ $key ][ $index ] = $this->meta_value( (string) $meta[ $key ][ $index ], $location );
					}
					break;

				case 'field':
					$field = (string) $location['field'];
					if ( ! in_array( $field, self::WRITER_FIELDS, true ) && array_key_exists( $field, $fields ) ) {
						$fields[ $field ] = $this->transform( $fields[ $field ], $location );
					}
					break;
			}
		}

		$attribute_edits = array();
		foreach ( $per_block as $block => $locations ) {
			$attribute_edits[ $block ] = function ( \stdClass $attrs ) use ( $locations ): \stdClass {
				foreach ( $locations as $location ) {
					$value = Value_Path::get( $attrs, $location['path'] );
					Value_Path::set( $attrs, $location['path'], $this->transform( $value, $location ) );
				}
				return $attrs;
			};
		}

		if ( '' !== $content && ( array() !== $attribute_edits || array() !== $html ) ) {
			try {
				$content = $this->context->editor->edit( $content, $attribute_edits, $html );
			} catch ( Content_Exception $e ) {
				throw new Apply_Exception( esc_html( sprintf( '%1$s: %2$s', $record['key'], $e->getMessage() ) ), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped; the previous exception is chained, not output.
			}
		}

		return array(
			'content' => $content,
			'fields'  => $fields,
			'meta'    => $meta,
		);
	}
}
