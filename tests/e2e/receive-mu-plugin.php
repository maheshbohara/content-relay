<?php
/**
 * E2E: turns receiving on for a local test site while this file sits in its wp-content/mu-plugins/.
 *
 * Copied in by the e2e scripts (as content-relay-e2e-receive.php) and removed afterwards; removing it is also
 * scenario 11's "CONTENT_RELAY_RECEIVE off". Never install it on a real site: there, receiving is
 * switched under Settings → Content Relay. The constant it defines overrides that switch and locks it on.
 *
 * @package ContentRelay
 */

// phpcs:ignoreFile -- Test harness, not plugin code.

$content_relay_host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
if ( ! defined( 'CONTENT_RELAY_RECEIVE' ) && ( '' === $content_relay_host || '.test' === substr( $content_relay_host, -5 ) ) ) {
	define( 'CONTENT_RELAY_RECEIVE', true );
}
