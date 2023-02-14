<?php
/**
 * Builds the manifest for relaying one page.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Discovery;

use ContentRelay\Config;

use ContentRelay\Discovery\Resolvers\Acf_Resolver;
use ContentRelay\Discovery\Resolvers\Block_References_Resolver;
use ContentRelay\Discovery\Resolvers\Html_Resolver;
use ContentRelay\Discovery\Resolvers\Meta_References_Resolver;
use ContentRelay\Discovery\Resolvers\Url_Resolver;
use ContentRelay\Support\Theme_Fingerprint;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the object graph from a root page and returns a manifest (spec §7).
 */
final class Exporter {

	/**
	 * Meta never relayed: the receiver keeps its own values.
	 */
	public const DENIED_META = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_encloseme',
		'_pingme',
		'_wpml_word_count',
		'_wpml_location_migration_done',
		'_yoast_indexnow_last_ping',
	);

	/**
	 * Attachment meta rebuilt by the receiver from the file itself.
	 */
	private const DENIED_ATTACHMENT_META = array(
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_attachment_backup_sizes',
	);

	/**
	 * Post fields relayed for posts and pages.
	 */
	private const POST_FIELDS = array(
		'post_title',
		'post_name',
		'post_status',
		'post_excerpt',
		'post_date',
		'post_date_gmt',
		'post_password',
		'menu_order',
		'comment_status',
		'ping_status',
		'post_mime_type',
	);

	/**
	 * ACF field types whose stored value is an id, with the kind of object the id names.
	 */
	private const ACF_ID_TYPES = array(
		'image'        => 'post',
		'file'         => 'post',
		'gallery'      => 'post',
		'post_object'  => 'post',
		'relationship' => 'post',
		'page_link'    => 'post',
		'taxonomy'     => 'term',
		'user'         => 'user',
	);

	/**
	 * Export context.
	 *
	 * @var Export_Context
	 */
	private Export_Context $context;

	/**
	 * Resolvers.
	 *
	 * @var Resolver[]
	 */
	private array $resolvers;

	/**
	 * Constructor.
	 *
	 * @param Export_Context  $context   Export context.
	 * @param Resolver[]|null $resolvers Resolvers; defaults to default_resolvers().
	 */
	public function __construct( Export_Context $context, ?array $resolvers = null ) {
		$this->context   = $context;
		$this->resolvers = null === $resolvers ? self::default_resolvers() : $resolvers;
	}

	/**
	 * Default resolvers, filterable via `content_relay_resolvers`.
	 *
	 * @return Resolver[]
	 */
	public static function default_resolvers(): array {
		$resolvers = array(
			new Block_References_Resolver(),
			new Acf_Resolver(),
			new Meta_References_Resolver(),
			new Html_Resolver(),
			new Url_Resolver(),
		);

		$filtered = apply_filters( 'content_relay_resolvers', $resolvers );
		if ( ! is_array( $filtered ) ) {
			return $resolvers;
		}

		return array_values(
			array_filter(
				$filtered,
				static function ( $resolver ): bool {
					return $resolver instanceof Resolver;
				}
			)
		);
	}
}
