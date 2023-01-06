<?php
/**
 * Hash of a theme's source files.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Detects a theme deploy that is incomplete or edited without a version bump: two sites can report the same theme
 * version while their block templates differ. Build output is excluded because development and production builds
 * legitimately differ.
 */
final class Theme_Fingerprint {

	/**
	 * Directories that never count as theme source.
	 */
	private const SKIP_DIRECTORIES = array( 'node_modules', 'vendor', 'build', 'dist', 'tests' );

	/**
	 * File extensions that make up the rendering source.
	 */
	private const EXTENSIONS = array( 'php', 'json' );

	/**
	 * Hashes the theme source files under a directory.
	 *
	 * @param string $directory Theme directory.
	 * @return array{hash: string, files: int}
	 */
	public static function of( string $directory ): array {
		$directory = untrailingslashit( $directory );
		$entries   = array();

		if ( is_dir( $directory ) ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ),
					static function ( \SplFileInfo $file ): bool {
						if ( $file->isDir() ) {
							return '.' !== substr( $file->getFilename(), 0, 1 ) && ! in_array( $file->getFilename(), self::SKIP_DIRECTORIES, true );
						}
						return in_array( strtolower( $file->getExtension() ), self::EXTENSIONS, true );
					}
				)
			);

			foreach ( $iterator as $file ) {
				if ( $file instanceof \SplFileInfo && $file->isFile() ) {
					$entries[ substr( $file->getPathname(), strlen( $directory ) + 1 ) ] = (string) sha1_file( $file->getPathname() );
				}
			}
		}

		ksort( $entries, SORT_STRING );

		return array(
			'hash'  => hash( 'sha256', (string) wp_json_encode( $entries ) ),
			'files' => count( $entries ),
		);
	}
}
