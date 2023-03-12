<?php
/**
 * Rewrites sender URLs to receiver URLs.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Apply;

defined( 'ABSPATH' ) || exit;

/**
 * Sender → receiver URL rewriting, including uploads that had to be renamed on the receiver.
 */
final class Url_Map {

	/**
	 * Sender host, lowercase.
	 *
	 * @var string
	 */
	private string $source_host;

	/**
	 * Receiver home URL without trailing slash.
	 *
	 * @var string
	 */
	private string $target_home;

	/**
	 * Sender uploads URL without trailing slash.
	 *
	 * @var string
	 */
	private string $source_uploads;

	/**
	 * Receiver uploads URL without trailing slash.
	 *
	 * @var string
	 */
	private string $target_uploads;

	/**
	 * Renamed uploads: sender relative path without extension => receiver relative path without extension.
	 *
	 * @var array<string, string>
	 */
	private array $renames = array();

	/**
	 * Constructor.
	 *
	 * @param string $source_home    Sender home URL.
	 * @param string $target_home    Receiver home URL.
	 * @param string $source_uploads Sender uploads URL.
	 * @param string $target_uploads Receiver uploads URL.
	 */
	public function __construct( string $source_home, string $target_home, string $source_uploads, string $target_uploads ) {
		$this->source_host    = strtolower( (string) wp_parse_url( $source_home, PHP_URL_HOST ) );
		$this->target_home    = untrailingslashit( $target_home );
		$this->source_uploads = untrailingslashit( $source_uploads );
		$this->target_uploads = untrailingslashit( $target_uploads );
	}

	/**
	 * Records that an upload was placed under a different name.
	 *
	 * @param string $source_relative Sender relative path, e.g. `2026/09/hero.jpg`.
	 * @param string $target_relative Receiver relative path, e.g. `2026/09/hero-1.jpg`.
	 */
	public function add_rename( string $source_relative, string $target_relative ): void {
		$from = self::stem( $source_relative );
		$to   = self::stem( $target_relative );
		if ( $from !== $to ) {
			$this->renames[ $from ] = $to;
		}
	}

	/**
	 * Rewrites every sender URL in a string.
	 *
	 * @param string $text Text or HTML.
	 * @return string
	 */
	public function rewrite( string $text ): string {
		if ( '' === $this->source_host || false === stripos( $text, $this->source_host ) ) {
			return $text;
		}

		foreach ( $this->renames as $from => $to ) {
			// `hero` must not match `hero-banner.jpg`; only the file itself, its sub-sizes and its scaled copy.
			$pattern = '#' . preg_quote( $this->source_uploads . '/' . $from, '#' ) . '(?=(?:-\d+x\d+|-scaled)*\.[A-Za-z0-9]+)#';
			$text    = (string) preg_replace( $pattern, $this->target_uploads . '/' . $to, $text );
		}

		$text = str_replace( $this->source_uploads . '/', $this->target_uploads . '/', $text );

		return (string) preg_replace(
			'#https?://' . preg_quote( $this->source_host, '#' ) . '(?::\d+)?(?=[/"\'\s<>?\#)]|$)#i',
			$this->target_home,
			$text
		);
	}

	/**
	 * A relative path without its extension.
	 *
	 * @param string $relative Relative path.
	 */
	private static function stem( string $relative ): string {
		return (string) preg_replace( '/\.[A-Za-z0-9]+$/', '', $relative );
	}
}
