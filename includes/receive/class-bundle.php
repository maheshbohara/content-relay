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

	/**
	 * Loads a bundle directory.
	 *
	 * @param string $dir Bundle directory.
	 * @return self
	 *
	 * @throws Receive_Exception When the manifest is missing or invalid.
	 */
	public static function from_directory( string $dir ): self {
		$dir  = rtrim( $dir, '/' );
		$file = $dir . '/manifest.json';
		if ( ! is_readable( $file ) ) {
			throw new Receive_Exception( sprintf( 'No manifest.json in %s.', esc_html( $dir ) ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundle file, not a remote URL.
		$manifest = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $manifest ) ) {
			throw new Receive_Exception( 'manifest.json is not valid JSON.' );
		}

		self::validate( $manifest );

		return new self( $manifest, $dir . '/files' );
	}

	/**
	 * Validates the manifest structure the receiver relies on.
	 *
	 * @param array<string, mixed> $manifest Manifest.
	 *
	 * @throws Receive_Exception On the first structural problem.
	 */
	public static function validate( array $manifest ): void {
		if ( CONTENT_RELAY_SCHEMA !== ( $manifest['schema'] ?? null ) ) {
			throw new Receive_Exception( sprintf( 'Manifest schema %1$s is not supported (this site reads schema %2$d).', esc_html( (string) wp_json_encode( $manifest['schema'] ?? null ) ), (int) CONTENT_RELAY_SCHEMA ) );
		}

		foreach ( array( 'source', 'requirements', 'objects' ) as $section ) {
			if ( ! isset( $manifest[ $section ] ) || ! is_array( $manifest[ $section ] ) ) {
				throw new Receive_Exception( sprintf( 'Manifest section "%s" is missing.', esc_html( $section ) ) );
			}
		}

		if ( ! is_string( $manifest['source']['home_url'] ?? null ) || ! is_string( $manifest['root'] ?? null ) || ! is_array( $manifest['roots'] ?? null ) ) {
			throw new Receive_Exception( 'Manifest source, root or roots are missing.' );
		}

		foreach ( $manifest['objects'] as $key => $record ) {
			if ( ! is_array( $record ) || ( $record['key'] ?? null ) !== $key || ! in_array( $record['kind'] ?? null, self::KINDS, true ) || ! is_string( $record['role'] ?? null ) ) {
				throw new Receive_Exception( sprintf( 'Manifest object %s is malformed.', esc_html( (string) $key ) ) );
			}

			if ( 'attachment' === $record['kind'] && 'owned' === $record['role'] && isset( $record['file']['sha1'] ) && 1 !== preg_match( '/^[a-f0-9]{40}$/', (string) $record['file']['sha1'] ) ) {
				throw new Receive_Exception( sprintf( 'Manifest object %s has an invalid file hash.', esc_html( (string) $key ) ) );
			}
		}

		foreach ( $manifest['roots'] as $root ) {
			if ( ! isset( $manifest['objects'][ $root ] ) ) {
				throw new Receive_Exception( sprintf( 'Root %s is not in the manifest.', esc_html( (string) $root ) ) );
			}
		}
	}

	/**
	 * Manifest objects.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function objects(): array {
		return $this->manifest['objects'];
	}

	/**
	 * References the sender itself could not resolve, each mapped to 0.
	 *
	 * Such a reference already points at nothing on the sender. Mapping it to 0 keeps the receiver the same, where
	 * keeping the id could land on an unrelated object that happens to have that id here.
	 *
	 * @return array<string, int>
	 */
	public function absent_ids(): array {
		$ids = array();
		foreach ( (array) ( $this->manifest['absent'] ?? array() ) as $key ) {
			if ( is_string( $key ) && 1 === preg_match( '/^(post|term|user):\d+$/', $key ) && ! isset( $this->manifest['objects'][ $key ] ) ) {
				$ids[ $key ] = 0;
			}
		}

		return $ids;
	}

	/**
	 * Sender home URL.
	 */
	public function source_home(): string {
		return (string) $this->manifest['source']['home_url'];
	}

	/**
	 * Path of a bundled file.
	 *
	 * @param string $sha1 File SHA-1.
	 * @return string|null Null when the bundle does not contain the file.
	 */
	public function file_path( string $sha1 ): ?string {
		if ( 1 !== preg_match( '/^[a-f0-9]{40}$/', $sha1 ) ) {
			return null;
		}

		$path = $this->files_dir . '/' . $sha1;

		return is_readable( $path ) ? $path : null;
	}
}
