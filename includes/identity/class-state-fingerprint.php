<?php
/**
 * Fingerprints of what a site currently holds, and of what a manifest carries.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Identity;

use ContentRelay\Discovery\Exporter;
use ContentRelay\Language\Adapter;

defined( 'ABSPATH' ) || exit;

/**
 * Site-local state hashes.
 *
 * A receiver compares its own current fingerprint with the one it stored at the last relay, so the hash never needs
 * to be comparable across sites and can include local ids.
 */
final class State_Fingerprint {

	/**
	 * Manifest keys that describe how an object was reached, not what it contains. An attachment's `upload_parent` is
	 * bookkeeping too: changing only where an image was uploaded does not make it a changed image.
	 */
	private const NON_CONTENT_KEYS = array( 'relations', 'role', 'on_missing', 'upload_parent' );

	/**
	 * Hasher.
	 *
	 * @var Fingerprint
	 */
	private Fingerprint $fingerprint;

	/**
	 * Language adapter.
	 *
	 * @var Adapter
	 */
	private Adapter $language;
}
