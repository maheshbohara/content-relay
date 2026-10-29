<?php
/**
 * Screenshots only: copied into the sending wp-env site as a must-use plugin by seed.sh.
 *
 * The receiving site's address, localhost:8910, is another container, so requests to it are sent through the Docker
 * host and the final URL is reported as the original one.
 *
 * @package ContentRelay
 */

// phpcs:ignoreFile -- Test harness, not plugin code.

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$from = 'http://localhost:8910';
		$via  = 'http://host.docker.internal:8910';
		if ( 0 !== strpos( $url, $from ) || ! empty( $args['content_relay_screens'] ) ) {
			return $pre;
		}
		$args['content_relay_screens'] = true;
		$args['headers']['Host']       = 'localhost:8910';
		$response                      = wp_remote_request( $via . substr( $url, strlen( $from ) ), $args );
		if ( is_array( $response ) && isset( $response['http_response'] ) ) {
			$object      = $response['http_response']->get_response_object();
			$object->url = str_replace( $via, $from, $object->url );
		}
		return $response;
	},
	10,
	3
);
