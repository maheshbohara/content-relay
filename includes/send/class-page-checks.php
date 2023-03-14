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

	/**
	 * `<link rel="alternate" hreflang="…" href="…">` pairs.
	 *
	 * @param string $html Page HTML.
	 * @return array<int, array{hreflang: string, href: string}>
	 */
	public static function hreflang_links( string $html ): array {
		$links = array();
		if ( ! preg_match_all( '/<link\b[^>]*>/i', $html, $tags ) ) {
			return $links;
		}

		foreach ( $tags[0] as $tag ) {
			$attributes = self::attributes( $tag );
			$rel        = preg_split( '/\s+/', strtolower( $attributes['rel'] ?? '' ) );
			if ( ! isset( $attributes['hreflang'], $attributes['href'] ) || ! is_array( $rel ) || ! in_array( 'alternate', $rel, true ) ) {
				continue;
			}
			$links[] = array(
				'hreflang' => $attributes['hreflang'],
				'href'     => $attributes['href'],
			);
		}

		return $links;
	}

	/**
	 * Whether a URL is among the hreflang alternates.
	 *
	 * @param array<int, array{hreflang: string, href: string}> $links Alternates.
	 * @param string                                            $url   URL.
	 */
	public static function has_alternate( array $links, string $url ): bool {
		foreach ( $links as $link ) {
			if ( self::same_url( $link['href'], $url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Places where the sender's host still appears as a URL (`//host`, JSON-escaped `\/\/host`, or `%2F%2Fhost`).
	 *
	 * Only URL-shaped occurrences count, so an e-mail address or a mention of the domain in copy is not reported, and a
	 * receiver on a subdomain of the sender (`dev.example.com` receiving from `example.com`) is not mistaken for it.
	 *
	 * @param string $html  Page HTML.
	 * @param string $host  Sender host name.
	 * @param int    $limit Snippets to return.
	 * @return array{count: int, snippets: string[]}
	 */
	public static function sender_urls( string $html, string $host, int $limit = 5 ): array {
		$host   = strtolower( trim( $host ) );
		$result = array(
			'count'    => 0,
			'snippets' => array(),
		);
		if ( '' === $host ) {
			return $result;
		}

		$pattern = '~(?://|\\\\/\\\\/|%2F%2F)(?:www\.)?' . preg_quote( $host, '~' ) . '(?![a-z0-9-]|\.[a-z0-9])~i';
		if ( ! preg_match_all( $pattern, $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $result;
		}

		$result['count'] = count( $matches[0] );
		foreach ( array_slice( $matches[0], 0, max( 0, $limit ) ) as $match ) {
			$start                = max( 0, (int) $match[1] - 40 );
			$result['snippets'][] = (string) preg_replace( '/\s+/', ' ', substr( $html, $start, strlen( (string) $match[0] ) + 80 ) );
		}

		return $result;
	}

	/**
	 * Image URLs (`src`, `srcset`, lazy-load `data-` variants) of `<img>` and `<source>` under an uploads URL.
	 *
	 * @param string $html        Page HTML.
	 * @param string $uploads_url Receiver uploads URL.
	 * @param int    $limit       Most URLs returned.
	 * @return string[] Unique URLs in page order.
	 */
	public static function upload_assets( string $html, string $uploads_url, int $limit = 50 ): array {
		$base = strtolower( self::strip_scheme( rtrim( $uploads_url, '/' ) ) ) . '/';
		$urls = array();
		if ( '/' === $base || ! preg_match_all( '/<(?:img|source)\b[^>]*>/i', $html, $tags ) ) {
			return $urls;
		}

		foreach ( $tags[0] as $tag ) {
			$attributes = self::attributes( $tag );
			$candidates = array();
			foreach ( array( 'src', 'data-src' ) as $name ) {
				if ( isset( $attributes[ $name ] ) ) {
					$candidates[] = $attributes[ $name ];
				}
			}
			foreach ( array( 'srcset', 'data-srcset' ) as $name ) {
				foreach ( isset( $attributes[ $name ] ) ? explode( ',', $attributes[ $name ] ) : array() as $entry ) {
					$parts = preg_split( '/\s+/', trim( $entry ) );
					if ( is_array( $parts ) && '' !== (string) ( $parts[0] ?? '' ) ) {
						$candidates[] = (string) $parts[0];
					}
				}
			}

			foreach ( $candidates as $url ) {
				$url = trim( $url );
				if ( 0 !== strpos( strtolower( self::strip_scheme( $url ) ), $base ) || in_array( $url, $urls, true ) ) {
					continue;
				}
				$urls[] = $url;
				if ( count( $urls ) >= $limit ) {
					return $urls;
				}
			}
		}

		return $urls;
	}

	/**
	 * Whether two URLs name the same page: scheme, host case, a trailing slash and percent-encoding are ignored.
	 *
	 * @param string $a URL.
	 * @param string $b URL.
	 */
	public static function same_url( string $a, string $b ): bool {
		return self::normalize_url( $a ) === self::normalize_url( $b );
	}

	/**
	 * Attributes of one tag: names lowercased, first occurrence wins, values entity-decoded.
	 *
	 * @param string $tag Tag source, e.g. `<link rel="alternate" …>`.
	 * @return array<string, string>
	 */
	public static function attributes( string $tag ): array {
		$attributes = array();
		if ( ! preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/', $tag, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL ) ) {
			return $attributes;
		}

		foreach ( $matches as $match ) {
			$name = strtolower( (string) $match[1] );
			if ( ! isset( $attributes[ $name ] ) ) {
				$attributes[ $name ] = html_entity_decode( (string) ( $match[2] ?? $match[3] ?? $match[4] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}

		return $attributes;
	}

	/**
	 * Primary language subtag, lowercased.
	 *
	 * @param string $code Language code.
	 */
	private static function primary_subtag( string $code ): string {
		$parts = preg_split( '/[-_]/', strtolower( trim( $code ) ) );

		return is_array( $parts ) ? (string) $parts[0] : '';
	}

	/**
	 * A URL without its scheme (`https://host/x` → `//host/x`).
	 *
	 * @param string $url URL.
	 */
	private static function strip_scheme( string $url ): string {
		return (string) preg_replace( '#^[a-z][a-z0-9+.-]*:#i', '', $url );
	}

	/**
	 * Comparable form of a URL.
	 *
	 * @param string $url URL.
	 */
	private static function normalize_url( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return rtrim( trim( $url ), '/' );
		}

		$path = isset( $parts['path'] ) ? rawurldecode( (string) $parts['path'] ) : '';

		return strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . '/' . trim( $path, '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}
}
