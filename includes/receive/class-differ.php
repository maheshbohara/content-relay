<?php
/**
 * What a relay would change on this site, per object, for the review screen.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Receive;

use ContentRelay\Apply\Apply_Exception;
use ContentRelay\Apply\Rewriter;
use ContentRelay\Apply\Url_Map;
use ContentRelay\Discovery\Exporter;
use ContentRelay\Support\Line_Diff;

defined( 'ABSPATH' ) || exit;

/**
 * Compares each object a plan would replace with what this site holds now. Reads only.
 *
 * Incoming values are rewritten with the receiver ids the plan already knows, so an unchanged reference does not show
 * up as a change. Objects the relay would create have no id yet; references to them show as `0`.
 */
final class Differ {

	/**
	 * Plan actions whose existing object is replaced, and therefore diffed.
	 */
	public const ACTIONS = array( 'update', 'overwrite', 'adopt', 'conflict' );

	/**
	 * Plan actions whose object keeps a known receiver id.
	 */
	private const KNOWN_ID_ACTIONS = array( 'reuse', 'map', 'update', 'overwrite', 'adopt', 'unchanged', 'conflict' );

	/**
	 * Post fields compared (the fields a relay writes).
	 */
	private const FIELDS = array( 'post_title', 'post_name', 'post_status', 'post_excerpt', 'post_date', 'post_date_gmt', 'post_password', 'menu_order', 'comment_status', 'ping_status', 'post_mime_type' );

	/**
	 * Attachment meta the receiver rebuilds from the file and never replaces.
	 */
	private const ATTACHMENT_META = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' );

	/**
	 * Most meta keys listed per group.
	 */
	private const MAX_META_KEYS = 100;

	/**
	 * Longest value shown, in bytes.
	 */
	private const MAX_VALUE_BYTES = 300;

	/**
	 * Receiver context.
	 *
	 * @var Receive_Context
	 */
	private Receive_Context $context;

	/**
	 * Constructor.
	 *
	 * @param Receive_Context $context Receiver context.
	 */
	public function __construct( Receive_Context $context ) {
		$this->context = $context;
	}

	/**
	 * Diffs every object of a plan that would be replaced.
	 *
	 * @param Bundle               $bundle Bundle.
	 * @param array<string, mixed> $plan   Planner result.
	 * @return array<string, array<string, mixed>> Object key => diff (fields, meta, content, approximate).
	 */
	public function diffs( Bundle $bundle, array $plan ): array {
		$ids = array();
		foreach ( (array) $plan['items'] as $item ) {
			$ids[ (string) $item['key'] ] = null !== $item['id'] && in_array( $item['action'], self::KNOWN_ID_ACTIONS, true ) ? (int) $item['id'] : 0;
		}

		$ids     += $bundle->absent_ids();
		$source   = (array) $bundle->manifest['source'];
		$rewriter = new Rewriter( $this->context, new Url_Map( (string) $source['home_url'], $this->context->home_url, (string) ( $source['uploads_url'] ?? '' ), $this->context->uploads_url ), $ids );
		$objects  = $bundle->objects();

		$diffs = array();
		foreach ( (array) $plan['items'] as $item ) {
			$key = (string) $item['key'];
			if ( 'owned' !== $item['role'] || null === $item['id'] || ! isset( $objects[ $key ] ) || ! in_array( $item['action'], self::ACTIONS, true ) || ! in_array( $item['kind'], array( 'post', 'attachment' ), true ) ) {
				continue;
			}
			$diffs[ $key ] = $this->diff_post( $objects[ $key ], (int) $item['id'], $rewriter, $ids );
		}

		return $diffs;
	}

	/**
	 * Diffs one post or attachment.
	 *
	 * @param array<string, mixed> $record   Manifest object.
	 * @param int                  $post_id  Receiver post id.
	 * @param Rewriter             $rewriter Rewriter with the plan's known ids.
	 * @param array<string, int>   $ids      Object key => receiver id (0 when it will be created).
	 * @return array<string, mixed>
	 */
	private function diff_post( array $record, int $post_id, Rewriter $rewriter, array $ids ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compares with the stored row, as the relay would replace it.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $post_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return array( 'missing' => true );
		}

		$approximate = false;
		try {
			$incoming = $rewriter->rewrite( $record );
		} catch ( Apply_Exception $e ) {
			$approximate = true;
			$incoming    = array(
				'content' => (string) ( $record['content'] ?? '' ),
				'fields'  => (array) ( $record['fields'] ?? array() ),
				'meta'    => (array) ( $record['meta'] ?? array() ),
			);
		}

		$fields = array();
		foreach ( self::FIELDS as $field ) {
			if ( array_key_exists( $field, $incoming['fields'] ) && (string) $row[ $field ] !== (string) $incoming['fields'][ $field ] ) {
				$fields[] = array(
					'field' => $field,
					'old'   => self::short( (string) $row[ $field ] ),
					'new'   => self::short( (string) $incoming['fields'][ $field ] ),
				);
			}
		}

		$parent = is_string( $record['parent'] ?? null ) ? (int) ( $ids[ $record['parent'] ] ?? 0 ) : 0;
		if ( (int) $row['post_parent'] !== $parent ) {
			$fields[] = array(
				'field' => 'post_parent',
				'old'   => (string) $row['post_parent'],
				'new'   => (string) $parent,
			);
		}

		return array(
			'fields'      => $fields,
			'meta'        => $this->diff_meta( $record, $post_id, (array) $incoming['meta'] ),
			'content'     => Line_Diff::diff( (string) $row['post_content'], (string) $incoming['content'] ),
			'approximate' => $approximate,
		);
	}

	/**
	 * Diffs meta the way Post_Writer::replace_meta() would replace it.
	 *
	 * @param array<string, mixed> $record   Manifest object.
	 * @param int                  $post_id  Receiver post id.
	 * @param array<mixed>         $incoming Rewritten meta: key => raw values.
	 * @return array{added: array<int, array<string, string>>, changed: array<int, array<string, string>>, removed: array<int, array<string, string>>, truncated: bool}
	 */
	private function diff_meta( array $record, int $post_id, array $incoming ): array {
		global $wpdb;

		$kept = Exporter::denied_meta();
		if ( 'attachment' === $record['kind'] ) {
			$kept = array_merge( $kept, self::ATTACHMENT_META );
		}

		$current = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compares with the stored rows, as the relay would replace them.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $post_id ), ARRAY_A );
		foreach ( (array) $rows as $meta_row ) {
			$key = (string) $meta_row['meta_key'];
			if ( ! self::is_kept( $key, $kept ) ) {
				$current[ $key ][] = (string) $meta_row['meta_value'];
			}
		}

		$new = array();
		foreach ( $incoming as $key => $values ) {
			if ( ! self::is_kept( (string) $key, $kept ) ) {
				$new[ (string) $key ] = array_map( 'strval', (array) $values );
			}
		}

		$meta = array(
			'added'     => array(),
			'changed'   => array(),
			'removed'   => array(),
			'truncated' => false,
		);
		foreach ( $new as $key => $values ) {
			if ( ! isset( $current[ $key ] ) ) {
				$meta['added'][] = array(
					'key' => $key,
					'new' => self::short( implode( ' | ', $values ) ),
				);
			} elseif ( $current[ $key ] !== $values ) {
				$meta['changed'][] = array(
					'key' => $key,
					'old' => self::short( implode( ' | ', $current[ $key ] ) ),
					'new' => self::short( implode( ' | ', $values ) ),
				);
			}
		}
		foreach ( array_diff_key( $current, $new ) as $key => $values ) {
			$meta['removed'][] = array(
				'key' => (string) $key,
				'old' => self::short( implode( ' | ', $values ) ),
			);
		}

		foreach ( array( 'added', 'changed', 'removed' ) as $group ) {
			if ( count( $meta[ $group ] ) > self::MAX_META_KEYS ) {
				$meta[ $group ]    = array_slice( $meta[ $group ], 0, self::MAX_META_KEYS );
				$meta['truncated'] = true;
			}
		}

		return $meta;
	}

	/**
	 * Whether a meta key is kept by the receiver and never replaced.
	 *
	 * @param string   $key  Meta key.
	 * @param string[] $kept Kept keys.
	 */
	private static function is_kept( string $key, array $kept ): bool {
		return in_array( $key, $kept, true ) || 0 === strpos( $key, '_content_relay_' ) || 0 === strpos( $key, '_wp_trash_meta_' );
	}

	/**
	 * Shortens a value for display.
	 *
	 * @param string $value Value.
	 */
	private static function short( string $value ): string {
		return strlen( $value ) > self::MAX_VALUE_BYTES ? substr( $value, 0, self::MAX_VALUE_BYTES ) . '…' : $value;
	}
}
