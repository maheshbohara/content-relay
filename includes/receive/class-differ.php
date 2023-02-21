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
}
