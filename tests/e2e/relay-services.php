<?php
/**
 * E2E scenario 15: lets `services` posts be relayed for one WP-CLI run (posts 36/37 hold block JSON that does not parse).
 *
 * Usage: wp --require=…/local-sender.php --require=…/relay-services.php content-relay push 36 --to=clone
 *
 * @package ContentRelay
 */

// phpcs:ignoreFile -- Test harness, not plugin code.

WP_CLI::add_wp_hook(
	'content_relay_post_types',
	static function ( $types ) {
		return array_values( array_unique( array_merge( (array) $types, array( 'services' ) ) ) );
	}
);
