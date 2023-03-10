<?php
/**
 * Creates terms a relay needs but this site lacks.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Apply;

use ContentRelay\Identity\Uuid;
use ContentRelay\Receive\Receive_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Term creation in the term's own language.
 *
 * Term meta is copied as stored, except ACF fields that hold ids (an image, a post, a term, a user): those ids are
 * mapped to this site's objects when the relay carries them, and removed with a warning when it does not, so a created
 * term never points at an unrelated object that happens to have the same id here.
 */
final class Term_Writer {

	/**
	 * Receiver context.
	 *
	 * @var Receive_Context
	 */
	private Receive_Context $context;

	/**
	 * Warnings from the terms created so far.
	 *
	 * @var string[]
	 */
	private array $warnings = array();

	/**
	 * Constructor.
	 *
	 * @param Receive_Context $context Receiver context.
	 */
	public function __construct( Receive_Context $context ) {
		$this->context = $context;
	}

	/**
	 * Creates a term.
	 *
	 * WPML assigns a new term the *current* language, so the language is switched to the term's own first.
	 *
	 * @param array<string, mixed> $record      Manifest term.
	 * @param int                  $parent_id   Receiver parent term id, or 0.
	 * @param int|null             $original_id Receiver id of the term this one translates, if any.
	 * @param array<string, int>   $ids         Object key => receiver id of everything the relay has resolved so far.
	 * @return int Term id.
	 *
	 * @throws Apply_Exception When the term cannot be created.
	 */
	public function create( array $record, int $parent_id, ?int $original_id, array $ids = array() ): int {
		$language = is_string( $record['language'] ?? null ) ? $record['language'] : null;

		if ( null !== $language ) {
			$this->context->language->switch_to( $language );
		}

		try {
			$result = wp_insert_term(
				(string) $record['name'],
				(string) $record['taxonomy'],
				array(
					'slug'        => (string) $record['slug'],
					'description' => (string) $record['description'],
					'parent'      => $parent_id,
				)
			);
		} finally {
			if ( null !== $language ) {
				$this->context->language->restore();
			}
		}

		if ( is_wp_error( $result ) ) {
			throw new Apply_Exception( esc_html( sprintf( 'Could not create term %1$s/%2$s: %3$s', $record['taxonomy'], $record['slug'], $result->get_error_message() ) ) );
		}

		$term_id = (int) $result['term_id'];
		$label   = sprintf( 'Term %1$s/%2$s', (string) $record['taxonomy'], (string) $record['slug'] );
		$meta    = $this->map_meta_ids( (array) ( $record['meta'] ?? array() ), (array) ( $record['meta_refs'] ?? array() ), $ids, $label );

		foreach ( $meta as $meta_key => $values ) {
			foreach ( (array) $values as $value ) {
				add_term_meta( $term_id, (string) $meta_key, maybe_unserialize( (string) $value ) );
			}
		}

		if ( is_string( $record['uuid'] ?? null ) ) {
			update_term_meta( $term_id, Uuid::META_KEY, $record['uuid'] );
		}

		if ( null !== $language ) {
			$this->context->language->link_term( $term_id, (string) $record['taxonomy'], $language, $original_id );
		}

		return $term_id;
	}

	/**
	 * Warnings from the terms created so far.
	 *
	 * @return string[]
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	/**
	 * Maps the ids held in a term's ACF id fields, removing ids this relay has no object for.
	 *
	 * @param array<string, array<int, string>> $meta  Raw meta: key => stored values.
	 * @param array<int, array<string, mixed>>  $refs  Values holding ids: key, index, kind.
	 * @param array<string, int>                $ids   Object key => receiver id.
	 * @param string                            $label Term label for warnings.
	 * @return array<string, array<int, string>>
	 */
	private function map_meta_ids( array $meta, array $refs, array $ids, string $label ): array {
		foreach ( $refs as $ref ) {
			$key   = (string) ( $ref['key'] ?? '' );
			$index = (int) ( $ref['index'] ?? 0 );
			$kind  = (string) ( $ref['kind'] ?? '' );
			if ( ! isset( $meta[ $key ][ $index ] ) || ! in_array( $kind, array( 'post', 'term', 'user' ), true ) ) {
				continue;
			}

			$raw     = (string) $meta[ $key ][ $index ];
			$missing = array();
			$mapped  = self::map_value( maybe_unserialize( $raw ), $kind, $ids, $missing );

			$meta[ $key ][ $index ] = is_serialized( $raw ) ? (string) maybe_serialize( $mapped ) : ( is_scalar( $mapped ) ? (string) $mapped : $raw );

			if ( array() !== $missing ) {
				$this->warnings[] = sprintf(
					'%1$s: field %2$s referred to %3$s %4$s, which this relay does not carry; the reference was removed.',
					$label,
					$key,
					$kind,
					implode( ', ', array_unique( $missing ) )
				);
			}
		}

		return $meta;
	}

	/**
	 * Maps ids in a value. An id without a mapping is removed from a list, or emptied when it is the whole value.
	 *
	 * @param mixed              $value   An id, a numeric string, or a list of them.
	 * @param string             $kind    `post`, `term` or `user`.
	 * @param array<string, int> $ids     Object key => receiver id.
	 * @param int[]              $missing Ids without a mapping; appended to.
	 * @return mixed
	 */
	private static function map_value( $value, string $kind, array $ids, array &$missing ) {
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			$id = (int) $value;
			if ( $id <= 0 ) {
				return $value;
			}
			if ( isset( $ids[ $kind . ':' . $id ] ) ) {
				return is_int( $value ) ? $ids[ $kind . ':' . $id ] : (string) $ids[ $kind . ':' . $id ];
			}
			$missing[] = $id;
			return '';
		}

		if ( is_array( $value ) ) {
			$is_list = array_values( $value ) === $value;
			$mapped  = array();
			foreach ( $value as $item_key => $item ) {
				$result = self::map_value( $item, $kind, $ids, $missing );
				if ( '' === $result && '' !== $item ) {
					continue;
				}
				$mapped[ $item_key ] = $result;
			}

			return $is_list ? array_values( $mapped ) : $mapped;
		}

		return $value;
	}
}
