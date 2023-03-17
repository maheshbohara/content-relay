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
}
