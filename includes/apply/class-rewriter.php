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
}
