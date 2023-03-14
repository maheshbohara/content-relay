<?php
/**
 * HTML checks behind the verification that follows a relay.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Send;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions over a page fetched from the receiver (spec §12): its language, its hreflang alternates, sender URLs
 * left in it, and the upload assets it loads.
 */
final class Page_Checks {

	/**
	 * The `lang` attribute of the `<html>` element.
	 *
	 * @param string $html Page HTML.
	 */
	public static function html_lang( string $html ): ?string {
		if ( ! preg_match( '/<html\b[^>]*>/i', $html, $tag ) ) {
			return null;
		}

		$attributes = self::attributes( $tag[0] );

		return isset( $attributes['lang'] ) && '' !== $attributes['lang'] ? $attributes['lang'] : null;
	}

	/**
	 * Whether a `lang` value is the expected language (compared on the primary subtag: `fr-FR` matches `fr`).
	 *
	 * @param string|null $lang     Value found.
	 * @param string      $expected Language code expected.
	 */
	public static function same_language( ?string $lang, string $expected ): bool {
		if ( null === $lang || '' === trim( $expected ) ) {
			return false;
		}

		return self::primary_subtag( $lang ) === self::primary_subtag( $expected );
	}
}
