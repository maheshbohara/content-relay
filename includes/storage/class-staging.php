<?php
/**
 * The receiver's staging area for sessions arriving over REST.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Storage;

use ContentRelay\Identity\Uuid;

defined( 'ABSPATH' ) || exit;

/**
 * One directory per session: `session.json` (state), `manifest.json` and `files/<sha1>` — the same bundle layout the
 * planner and applier read from `wp content-relay bundle` output. Files arrive in chunks as `files/<sha1>.part` and are
 * renamed only once their SHA-1 verifies, so the planner never sees a partial file.
 */
final class Staging {

	/**
	 * Seconds after the last activity when an unapplied session is removed.
	 */
	public const TTL_SECONDS = 86400;

	/**
	 * Session states.
	 */
	public const STATUS_PLANNED     = 'planned';
	public const STATUS_APPLYING    = 'applying';
	public const STATUS_APPLIED     = 'applied';
	public const STATUS_ROLLED_BACK = 'rolled-back';

	/**
	 * Staging root directory without trailing slash.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Constructor.
	 *
	 * @param string $root Staging root, e.g. `…/uploads/content-relay/staging`.
	 */
	public function __construct( string $root ) {
		$this->root = rtrim( $root, '/' );
	}

	/**
	 * Staging area of this site.
	 */
	public static function for_site(): self {
		$uploads = wp_get_upload_dir();

		return new self( untrailingslashit( (string) $uploads['basedir'] ) . '/content-relay/staging' );
	}

	/**
	 * Creates a session directory.
	 *
	 * @param string               $id       Session id (UUID).
	 * @param array<string, mixed> $record   Session record.
	 * @param array<string, mixed> $manifest Validated manifest.
	 *
	 * @throws Staging_Exception When the directory or its files cannot be written.
	 */
	public function create( string $id, array $record, array $manifest ): void {
		$dir = $this->dir( $id );
		if ( file_exists( $dir ) ) {
			throw new Staging_Exception( 'Session already exists.' );
		}
		if ( ! wp_mkdir_p( $dir . '/files' ) ) {
			throw new Staging_Exception( 'Cannot create the staging directory.' );
		}

		$parent = dirname( $this->root );
		$guards = array(
			$parent . '/index.php'     => "<?php\n// Silence is golden.\n",
			$parent . '/.htaccess'     => "Require all denied\n",
			$this->root . '/index.php' => "<?php\n// Silence is golden.\n",
		);
		foreach ( $guards as $guard => $contents ) {
			if ( ! file_exists( $guard ) ) {
				$this->put( $guard, $contents );
			}
		}

		$this->put( $dir . '/manifest.json', (string) wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$this->write_record( $id, $record );
	}
}
