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
}
