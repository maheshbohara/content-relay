<?php
/**
 * E2E: turns sending on for one WP-CLI run and trusts the local self-signed *.test certificates.
 *
 * Usage: wp --require=wp-content/plugins/content-relay/tests/e2e/local-sender.php content-relay push …
 * Loaded before WordPress, so it never touches wp-config.php and never ships: the TLS override is limited to
 * Content Relay's own requests to *.test hosts.
 *
 * @package ContentRelay
 */

// phpcs:ignoreFile -- Test harness, not plugin code.

if ( ! defined( 'CONTENT_RELAY_SEND' ) ) {
	define( 'CONTENT_RELAY_SEND', true );
}

WP_CLI::add_wp_hook(
	'content_relay_http_args',
	static function ( $args, $url ) {
		if ( 1 === preg_match( '#^https://[a-z0-9.-]+\.test(?::\d+)?/#i', (string) $url ) ) {
			$args['sslverify'] = false;
		}
		return $args;
	},
	10,
	2
);
