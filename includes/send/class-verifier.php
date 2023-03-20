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

	/**
	 * Checks one page.
	 *
	 * @param array<string, mixed>  $target      Page.
	 * @param array<string, string> $urls        Key => address of every relayed page.
	 * @param string                $sender_host Sender host name.
	 * @param string                $uploads_url Receiver uploads URL.
	 * @param int                   $budget      Image requests left; decremented.
	 * @param array<string, bool>   $assets      Image URL => whether it loads, shared across pages.
	 * @return array{key: string, url: string, language: string|null, skipped: bool, note: string, checks: array<int, array{check: string, ok: bool, message: string}>}
	 */
	private function page( array $target, array $urls, string $sender_host, string $uploads_url, int &$budget, array &$assets ): array {
		$url      = (string) ( $target['url'] ?? '' );
		$language = is_string( $target['language'] ?? null ) && '' !== $target['language'] ? $target['language'] : null;
		$status   = (string) ( $target['status'] ?? '' );
		$page     = array(
			'key'      => (string) ( $target['key'] ?? '' ),
			'url'      => $url,
			'language' => $language,
			'skipped'  => false,
			'note'     => '',
			'checks'   => array(),
		);

		if ( 'publish' !== $status ) {
			$page['skipped'] = true;
			$page['note']    = sprintf( 'Not public (%s): checked in the receiving site\'s database only.', '' === $status ? 'status unknown' : $status );
			return $page;
		}
		if ( '' === $url ) {
			$page['checks'][] = self::check( 'reachable', false, 'The receiving site reported no address for this page.' );
			return $page;
		}

		$response = $this->request( 'GET', $url, self::PAGE_TIMEOUT, 1 );
		if ( is_wp_error( $response ) ) {
			$page['checks'][] = self::check( 'reachable', false, sprintf( 'Could not be fetched: %s', $response->get_error_message() ) );
			return $page;
		}

		$code       = (int) wp_remote_retrieve_response_code( $response );
		$final      = self::final_url( $response, $url );
		$at_address = Page_Checks::same_url( $final, $url );

		$page['checks'][] = self::check( 'status', 200 === $code, sprintf( 'Answered HTTP %d.', $code ) );
		$page['checks'][] = self::check( 'address', $at_address, $at_address ? 'Served at the expected address.' : sprintf( 'Ended at %s instead of the expected address.', $final ) );
		if ( 200 !== $code ) {
			return $page;
		}

		$html = (string) wp_remote_retrieve_body( $response );

		if ( null !== $language ) {
			$lang             = Page_Checks::html_lang( $html );
			$page['checks'][] = self::check(
				'language',
				Page_Checks::same_language( $lang, $language ),
				null === $lang ? sprintf( 'The page has no <html lang>; expected %s.', $language ) : sprintf( '<html lang="%1$s">, expected %2$s.', $lang, $language )
			);
		}

		$expected = array();
		foreach ( (array) ( $target['translations'] ?? array() ) as $key ) {
			$key = (string) $key;
			if ( $key !== $page['key'] && '' !== ( $urls[ $key ] ?? '' ) ) {
				$expected[] = $urls[ $key ];
			}
		}
		if ( array() !== $expected ) {
			$links = Page_Checks::hreflang_links( $html );
			if ( array() === $links ) {
				// A site that outputs no hreflang at all is configured that way; it is not a fault of the relay.
				$page['checks'][] = self::check( 'hreflang', true, 'The page outputs no hreflang links, so its language versions could not be checked there.' );
			} else {
				$missing = array();
				foreach ( $expected as $alternate ) {
					if ( ! Page_Checks::has_alternate( $links, $alternate ) ) {
						$missing[] = $alternate;
					}
				}
				$page['checks'][] = self::check(
					'hreflang',
					array() === $missing,
					array() === $missing ? sprintf( 'hreflang lists the %d other language version(s).', count( $expected ) ) : sprintf( 'hreflang does not list %s.', implode( ', ', $missing ) )
				);
			}
		}

		if ( '' !== $sender_host ) {
			$leftovers        = Page_Checks::sender_urls( $html, $sender_host, 1 );
			$page['checks'][] = self::check(
				'sender_urls',
				0 === $leftovers['count'],
				0 === $leftovers['count'] ? sprintf( 'No links to %s.', $sender_host ) : sprintf( '%1$d link(s) still point to %2$s, for example: …%3$s…', $leftovers['count'], $sender_host, $leftovers['snippets'][0] ?? '' )
			);
		}

		$broken    = array();
		$checked   = 0;
		$unchecked = 0;
		foreach ( Page_Checks::upload_assets( $html, $uploads_url, self::MAX_ASSET_URLS ) as $asset ) {
			$absolute = 0 === strpos( $asset, '//' ) ? (string) wp_parse_url( $url, PHP_URL_SCHEME ) . ':' . $asset : $asset;
			if ( ! isset( $assets[ $absolute ] ) ) {
				if ( $budget <= 0 ) {
					++$unchecked;
					continue;
				}
				--$budget;
				$assets[ $absolute ] = $this->asset_loads( $absolute );
			}
			++$checked;
			if ( ! $assets[ $absolute ] ) {
				$broken[] = $absolute;
			}
		}
		if ( $checked > 0 || $unchecked > 0 ) {
			$note             = $unchecked > 0 ? sprintf( ' %1$d more not checked (at most %2$d image requests per relay).', $unchecked, self::MAX_ASSETS ) : '';
			$page['checks'][] = self::check(
				'images',
				array() === $broken,
				( array() === $broken ? sprintf( '%d upload image(s) load.', $checked ) : sprintf( '%1$d of %2$d upload image(s) do not load: %3$s', count( $broken ), $checked, implode( ', ', array_slice( $broken, 0, 5 ) ) ) ) . $note
			);
		}

		return $page;
	}
}
