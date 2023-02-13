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
}
