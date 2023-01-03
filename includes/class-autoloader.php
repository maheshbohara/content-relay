<?php
/**
 * PSR-4-style autoloader mapped onto WordPress file naming.
 *
 * `ContentRelay\Content\Block_Scanner` → `includes/content/class-block-scanner.php`.
 * Interfaces and traits use the `interface-` / `trait-` file prefix.
 *
 * @package ContentRelay
 */

namespace ContentRelay;

defined( 'ABSPATH' ) || exit;

/**
 * Loads plugin classes on demand.
 */
final class Autoloader {

	/**
	 * Registers the autoloader with SPL.
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Loads the file for a class in the ContentRelay namespace.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$name  = self::to_file_part( (string) array_pop( $parts ) );
		$dir   = __DIR__ . '/';
		foreach ( $parts as $part ) {
			$dir .= self::to_file_part( $part ) . '/';
		}

		foreach ( array( 'class', 'interface', 'trait' ) as $kind ) {
			$file = $dir . $kind . '-' . $name . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
				return;
			}
		}
	}

	/**
	 * Converts a namespace segment or class name into a file-name segment.
	 *
	 * @param string $segment `Block_Scanner`, `Content`.
	 * @return string `block-scanner`, `content`.
	 */
	private static function to_file_part( string $segment ): string {
		return strtolower( str_replace( '_', '-', $segment ) );
	}
}
