<?php
/**
 * WP-CLI commands.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Cli;

use ContentRelay\Discovery\Export_Context;
use ContentRelay\Discovery\Export_Exception;
use ContentRelay\Discovery\Exporter;
use ContentRelay\Language\Adapter_Factory;

defined( 'ABSPATH' ) || exit;

/**
 * Relays a page — every language version, its media and the content it needs — to another site.
 */
final class Command {

	/**
	 * Shows what a relay of a page would carry.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : Id of the page (any language version).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp content-relay discover 4356
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function discover( array $args, array $assoc_args ): void {
		$manifest = $this->export( (int) $args[0] );

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		$rows = array();
		foreach ( $manifest['objects'] as $key => $record ) {
			$rows[] = array(
				'key'       => $key,
				'kind'      => $record['kind'],
				'role'      => $record['role'],
				'language'  => $record['language'] ?? '',
				'what'      => $this->describe( $record ),
				'locations' => isset( $record['locations'] ) ? count( $record['locations'] ) : '',
				'via'       => implode( ', ', $record['relations'] ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'kind', 'role', 'language', 'what', 'locations', 'via' ) );

		foreach ( $manifest['requirements'] as $type => $items ) {
			/* translators: 1: requirement type, 2: number of required items. */
			\WP_CLI::log( sprintf( __( 'Requires %1$s: %2$d', 'content-relay' ), $type, count( $items ) ) );
		}
		foreach ( $manifest['warnings'] as $warning ) {
			\WP_CLI::warning( $warning );
		}
	}

	/**
	 * Writes a relay bundle — manifest.json and files/<sha1> — to a directory.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : Id of the page (any language version).
	 *
	 * --dir=<dir>
	 * : Directory to write the bundle to. Created when missing; must be empty.
	 *
	 * ## EXAMPLES
	 *
	 *     wp content-relay bundle 4356 --dir=/tmp/vietnam
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function bundle( array $args, array $assoc_args ): void {
		$dir = rtrim( (string) ( $assoc_args['dir'] ?? '' ), '/' );
		if ( '' === $dir ) {
			\WP_CLI::error( __( '--dir is required.', 'content-relay' ) );
		}
		if ( is_dir( $dir ) && array() !== array_diff( (array) scandir( $dir ), array( '.', '..' ) ) ) {
			/* translators: %s: directory path. */
			\WP_CLI::error( sprintf( __( '%s is not empty.', 'content-relay' ), $dir ) );
		}
		if ( ! wp_mkdir_p( $dir . '/files' ) ) {
			/* translators: %s: directory path. */
			\WP_CLI::error( sprintf( __( 'Cannot create %s.', 'content-relay' ), $dir ) );
		}

		$manifest = $this->export( (int) $args[0] );
		$uploads  = wp_get_upload_dir();
		$copied   = 0;

		foreach ( $manifest['objects'] as $record ) {
			if ( empty( $record['file'] ) ) {
				continue;
			}
			$target = $dir . '/files/' . $record['file']['sha1'];
			if ( ! file_exists( $target ) ) {
				if ( ! copy( $uploads['basedir'] . '/' . $record['file']['source'], $target ) ) {
					/* translators: %s: file path relative to the uploads directory. */
					\WP_CLI::error( sprintf( __( 'Cannot copy %s.', 'content-relay' ), $record['file']['source'] ) );
				}
				++$copied;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP-CLI writes to a local directory the operator named; WP_Filesystem credentials do not apply.
		file_put_contents( $dir . '/manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		/* translators: 1: directory path, 2: number of objects, 3: number of files copied, 4: number of warnings. */
		\WP_CLI::success( sprintf( __( 'Bundle written to %1$s: %2$d objects, %3$d files, %4$d warnings.', 'content-relay' ), $dir, count( $manifest['objects'] ), $copied, count( $manifest['warnings'] ) ) );
	}

	/**
	 * Exports a manifest or stops with an error.
	 *
	 * @param int $post_id Root post id.
	 * @return array<string, mixed>
	 */
	private function export( int $post_id ): array {
		try {
			return ( new Exporter( new Export_Context( Adapter_Factory::create() ) ) )->export( $post_id );
		} catch ( Export_Exception $e ) {
			\WP_CLI::error( $e->getMessage() );
		}

		return array();
	}

	/**
	 * One-line description of an object.
	 *
	 * @param array<string, mixed> $record Object.
	 */
	private function describe( array $record ): string {
		switch ( $record['kind'] ) {
			case 'attachment':
				return isset( $record['file']['relative'] ) ? (string) $record['file']['relative'] : '(no file)';
			case 'term':
				return $record['taxonomy'] . '/' . $record['slug'];
			case 'user':
				return (string) $record['login'];
			default:
				return $record['post_type'] . ' /' . $record['path'] . '/';
		}
	}
}
