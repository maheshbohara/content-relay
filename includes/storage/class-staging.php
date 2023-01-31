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

	/**
	 * Reads a session record.
	 *
	 * @param string $id Session id.
	 * @return array<string, mixed>|null Null when the session does not exist.
	 */
	public function read( string $id ): ?array {
		if ( ! Uuid::is_valid( $id ) ) {
			return null;
		}

		$data = $this->read_json( $this->dir( $id ) . '/session.json' );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Merges fields into a session record and bumps its activity time.
	 *
	 * @param string               $id      Session id.
	 * @param array<string, mixed> $changes Fields to add or replace.
	 * @return array<string, mixed> Updated record.
	 *
	 * @throws Staging_Exception When the session does not exist or cannot be written.
	 */
	public function update( string $id, array $changes ): array {
		$record = $this->read( $id );
		if ( null === $record ) {
			throw new Staging_Exception( 'Session does not exist.' );
		}

		$record = array_merge( $record, $changes );
		$this->write_record( $id, $record );

		return $record;
	}

	/**
	 * Manifest of a session.
	 *
	 * @param string $id Session id.
	 * @return array<string, mixed>|null Null once the session's files were removed.
	 */
	public function manifest( string $id ): ?array {
		if ( ! Uuid::is_valid( $id ) ) {
			return null;
		}

		$data = $this->read_json( $this->dir( $id ) . '/manifest.json' );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Directory holding a session's verified files.
	 *
	 * @param string $id Session id.
	 */
	public function files_dir( string $id ): string {
		return $this->dir( $id ) . '/files';
	}

	/**
	 * Bytes received so far for a file.
	 *
	 * @param string $id   Session id.
	 * @param string $sha1 File SHA-1.
	 */
	public function received( string $id, string $sha1 ): int {
		$path = $this->files_dir( $id ) . '/' . $sha1;
		clearstatcache( true, $path );
		clearstatcache( true, $path . '.part' );
		if ( file_exists( $path ) ) {
			return (int) filesize( $path );
		}

		return file_exists( $path . '.part' ) ? (int) filesize( $path . '.part' ) : 0;
	}

	/**
	 * Appends a chunk to a file.
	 *
	 * The chunk is accepted only at the end of what was already received, so a retried or duplicated chunk can never
	 * corrupt the file; the caller learns the real offset and continues from there.
	 *
	 * @param string $id       Session id.
	 * @param string $sha1     Expected SHA-1 of the whole file.
	 * @param int    $offset   Offset of this chunk.
	 * @param string $bytes    Chunk.
	 * @param int    $expected Size of the whole file in bytes.
	 * @return array{status: string, received: int} Status: `partial`, `complete`, `offset`, `too-large` or `mismatch`.
	 *
	 * @throws Staging_Exception When the file cannot be opened or written.
	 */
	public function write_chunk( string $id, string $sha1, int $offset, string $bytes, int $expected ): array {
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $sha1 ) || ! is_dir( $this->files_dir( $id ) ) ) {
			throw new Staging_Exception( 'Unknown session file.' );
		}

		$final = $this->files_dir( $id ) . '/' . $sha1;
		clearstatcache( true, $final );
		if ( file_exists( $final ) ) {
			return self::chunk_result( 'complete', (int) filesize( $final ) );
		}

		$part = $final . '.part';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Appending under an exclusive lock needs a handle; WP_Filesystem has no locking.
		$handle = fopen( $part, 'c+' );
		if ( false === $handle ) {
			throw new Staging_Exception( 'Cannot open the staging file.' );
		}

		try {
			flock( $handle, LOCK_EX );
			$stat = fstat( $handle );
			$size = false === $stat ? 0 : (int) $stat['size'];

			if ( $offset !== $size ) {
				return self::chunk_result( 'offset', $size );
			}
			if ( $size + strlen( $bytes ) > $expected ) {
				return self::chunk_result( 'too-large', $size );
			}

			fseek( $handle, $size );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- See fopen() above.
			if ( '' !== $bytes && strlen( $bytes ) !== fwrite( $handle, $bytes ) ) {
				throw new Staging_Exception( 'Cannot write the staging file.' );
			}
			fflush( $handle );
			$size += strlen( $bytes );

			if ( $size < $expected ) {
				return self::chunk_result( 'partial', $size );
			}

			if ( sha1_file( $part ) !== $sha1 ) {
				wp_delete_file( $part );
				return self::chunk_result( 'mismatch', 0 );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic move inside the protected staging directory.
			if ( ! rename( $part, $final ) ) {
				throw new Staging_Exception( 'Cannot complete the staging file.' );
			}

			return self::chunk_result( 'complete', $size );
		} finally {
			flock( $handle, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See fopen() above.
			fclose( $handle );
		}
	}

	/**
	 * Removes a session's manifest and files but keeps its record (applied sessions stay addressable for rollback).
	 *
	 * @param string $id Session id.
	 */
	public function remove_files( string $id ): void {
		if ( ! Uuid::is_valid( $id ) ) {
			return;
		}

		$this->remove_tree( $this->files_dir( $id ) );
		if ( file_exists( $this->dir( $id ) . '/manifest.json' ) ) {
			wp_delete_file( $this->dir( $id ) . '/manifest.json' );
		}
	}

	/**
	 * Deletes a session completely.
	 *
	 * @param string $id Session id.
	 */
	public function delete( string $id ): void {
		if ( Uuid::is_valid( $id ) ) {
			$this->remove_tree( $this->dir( $id ) );
		}
	}

	/**
	 * Removes expired sessions: unapplied ones entirely, applied ones down to their record.
	 *
	 * @param int $now Unix time.
	 * @param int $ttl Seconds of inactivity before a session expires.
	 * @return int Sessions touched.
	 */
	public function purge( int $now, int $ttl ): int {
		if ( ! is_dir( $this->root ) ) {
			return 0;
		}

		$count = 0;
		foreach ( (array) scandir( $this->root ) as $name ) {
			$name = (string) $name;
			if ( ! Uuid::is_valid( $name ) ) {
				continue;
			}

			$record  = $this->read( $name );
			$updated = (int) ( $record['updated'] ?? 0 );
			if ( null !== $record && $updated > $now - $ttl ) {
				continue;
			}

			$status = (string) ( $record['status'] ?? '' );
			if ( in_array( $status, array( self::STATUS_APPLIED, self::STATUS_ROLLED_BACK ), true ) ) {
				if ( is_dir( $this->files_dir( $name ) ) ) {
					$this->remove_files( $name );
					++$count;
				}
			} elseif ( self::STATUS_APPLYING !== $status ) {
				$this->delete( $name );
				++$count;
			}
		}

		return $count;
	}
}
