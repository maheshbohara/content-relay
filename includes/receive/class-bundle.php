<?php
/**
 * A relay bundle on disk: manifest.json plus files/<sha1>.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Receive;

defined( 'ABSPATH' ) || exit;

/**
 * Loads and validates a bundle. The same shape is produced by `wp content-relay bundle` and by the REST transport's
 * staging area, so planning and applying never depend on how the bundle arrived.
 */
final class Bundle {

	/**
	 * Object kinds a manifest may contain.
	 */
	private const KINDS = array( 'post', 'attachment', 'term', 'user' );

	/**
	 * Decoded manifest.
	 *
	 * @var array<string, mixed>
	 */
	public array $manifest;

	/**
	 * Directory holding the bundle's files.
	 *
	 * @var string
	 */
	public string $files_dir;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $manifest  Validated manifest.
	 * @param string               $files_dir Directory holding files named by SHA-1.
	 */
	public function __construct( array $manifest, string $files_dir ) {
		$this->manifest  = $manifest;
		$this->files_dir = rtrim( $files_dir, '/' );
	}
}
