<?php
/**
 * Fetches upload files this site is missing from another copy of the site.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * Restores a missing upload from a fallback origin at relay time.
 *
 * A staging copy of a site often has the database but not every upload. When a fallback base URL is set (filter
 * `content_relay_media_fallback_url`, e.g. `https://example.com/wp-content/uploads`), a file missing on disk is
 * downloaded from `<base>/<relative path>` into this site's uploads before it is relayed.
 */
final class Media_Fallback {

	/**
	 * Largest file fetched, in bytes.
	 */
	private const MAX_BYTES = 104857600;

	/**
	 * The fallback base URL, or '' when none is set.
	 */
	public static function base_url(): string {
		$base = apply_filters( 'content_relay_media_fallback_url', '' );
		if ( ! is_string( $base ) || '' === $base || 0 !== strpos( $base, 'https://' ) ) {
			return '';
		}

		return rtrim( $base, '/' );
	}

	/**
	 * Whether media that is missing here (and from the fallback) is dropped instead of blocking the relay.
	 */
	public static function skip_missing(): bool {
		return true === apply_filters( 'content_relay_skip_missing_media', false );
	}

	/**
	 * Downloads one missing upload into place.
	 *
	 * @param string $uploads_dir Uploads base directory, without a trailing slash.
	 * @param string $relative    Path relative to the uploads directory, e.g. `2026/09/photo.webp`.
	 * @return string '' on success, otherwise why the file could not be fetched.
	 */
	public static function fetch( string $uploads_dir, string $relative ): string {
		$base = self::base_url();
		if ( '' === $base ) {
			return 'no media fallback URL is set';
		}
		if ( 1 !== preg_match( '#^[A-Za-z0-9._/@() +-]+$#', $relative ) || false !== strpos( $relative, '..' ) || 0 === strpos( $relative, '/' ) ) {
			return 'unsafe file path';
		}
		$type = wp_check_filetype( $relative );
		if ( false === $type['type'] ) {
			return 'file type not allowed';
		}

		$url = $base . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) );
		$tmp = wp_tempnam( basename( $relative ) );

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 60,
				'redirection'         => 0,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => self::MAX_BYTES,
			)
		);

		$error = '';
		$bytes = is_readable( $tmp ) ? (int) filesize( $tmp ) : 0;
		$check = wp_check_filetype_and_ext( $tmp, basename( $relative ) );
		if ( is_wp_error( $response ) ) {
			$error = $response->get_error_message();
		} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$error = 'HTTP ' . (int) wp_remote_retrieve_response_code( $response ) . ' from ' . $url;
		} elseif ( 0 === $bytes ) {
			$error = 'empty download from ' . $url;
		} elseif ( $bytes >= self::MAX_BYTES ) {
			$error = 'larger than the ' . size_format( self::MAX_BYTES ) . ' limit: ' . $url;
		} elseif ( '' !== (string) wp_remote_retrieve_header( $response, 'content-length' ) && (int) wp_remote_retrieve_header( $response, 'content-length' ) !== $bytes ) {
			$error = 'incomplete download from ' . $url;
		} elseif ( false === $check['type'] ) {
			$error = 'the downloaded bytes are not a ' . $type['ext'] . ' file: ' . $url;
		}

		$path = $uploads_dir . '/' . $relative;
		if ( '' === $error && ! wp_mkdir_p( dirname( $path ) ) ) {
			$error = 'cannot create ' . dirname( $relative );
		}
		// rename() fails across filesystems (system temp dir to uploads), so fall back to a copy.
		if ( '' === $error && ! @rename( $tmp, $path ) && ! copy( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- The copy fallback reports the failure.
			$error = 'cannot write ' . $relative;
		}
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		return $error;
	}
}
