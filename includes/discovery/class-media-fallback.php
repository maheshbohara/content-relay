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
}
