<?php
/**
 * HTTP verification of a relay, run by the sender.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Send;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches each relayed page's public address on the receiver and checks it (spec §12): the HTTP status and final
 * address, `<html lang>`, the hreflang alternates, that no link to the sender is left, and that its upload images load.
 *
 * Draft and private pages are skipped; the receiver checks those in its database. A failed check never rolls anything
 * back: it is reported next to the Roll back option.
 */
final class Verifier {

	/**
	 * Most image requests in one verification.
	 */
	private const MAX_ASSETS = 50;

	/**
	 * Most upload image addresses read from one page; only MAX_ASSETS of them are requested.
	 */
	private const MAX_ASSET_URLS = 500;

	/**
	 * Seconds a page request may take.
	 */
	private const PAGE_TIMEOUT = 20;

	/**
	 * Seconds an image request may take.
	 */
	private const ASSET_TIMEOUT = 10;

	/**
	 * Request function with the signature of wp_remote_request().
	 *
	 * @var callable
	 */
	private $transport;

	/**
	 * Constructor.
	 *
	 * @param callable|null $transport Request function; defaults to wp_remote_request().
	 */
	public function __construct( ?callable $transport = null ) {
		$this->transport = null === $transport ? 'wp_remote_request' : $transport;
	}

	/**
	 * Verifies relayed pages.
	 *
	 * @param array<int, array<string, mixed>> $targets     Pages: key, url, language, status and translations (keys).
	 * @param string                           $sender_home Sender home URL.
	 * @param string                           $uploads_url Receiver uploads URL.
	 * @return array{checked_at: string, ok: bool, pages: array<int, array<string, mixed>>, problems: string[]}
	 */
	public function verify( array $targets, string $sender_home, string $uploads_url ): array {
		$urls = array();
		foreach ( $targets as $target ) {
			$urls[ (string) ( $target['key'] ?? '' ) ] = (string) ( $target['url'] ?? '' );
		}

		$sender_host = strtolower( (string) wp_parse_url( $sender_home, PHP_URL_HOST ) );
		$budget      = self::MAX_ASSETS;
		$assets      = array();
		$pages       = array();
		$problems    = array();

		foreach ( $targets as $target ) {
			$page = $this->page( $target, $urls, $sender_host, $uploads_url, $budget, $assets );
			foreach ( $page['checks'] as $check ) {
				if ( ! $check['ok'] ) {
					$problems[] = sprintf( '%1$s: %2$s', '' === $page['url'] ? $page['key'] : $page['url'], $check['message'] );
				}
			}
			$pages[] = $page;
		}

		return array(
			'checked_at' => gmdate( 'c' ),
			'ok'         => array() === $problems,
			'pages'      => $pages,
			'problems'   => $problems,
		);
	}
}
