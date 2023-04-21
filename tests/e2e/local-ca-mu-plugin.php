<?php
/**
 * E2E: makes WordPress trust the local mkcert CA for Content Relay's own requests to *.test hosts (TLS verification
 * stays on). It does not touch the send/receive switches, so those stay under Settings → Content Relay.
 *
 * Install: copy into <docroot>/wp-content/mu-plugins/content-relay-e2e-ca.php on both local sites while testing; remove
 * it afterwards. Never install on a real site: real receivers have publicly trusted certificates.
 *
 * WordPress verifies TLS against wp-includes/certificates/ca-bundle.crt, which lacks the mkcert CA, so a handshake
 * between the local sites fails with "cURL error 60 … unable to get local issuer certificate". This builds a bundle of
 * that file plus the mkcert root in the system temp directory and hands it to Content Relay requests only.
 *
 * @package ContentRelay
 */

// phpcs:ignoreFile -- Test harness, not plugin code.

add_filter(
	'content_relay_http_args',
	static function ( $args, $url ) {
		if ( 1 !== preg_match( '#^https://[a-z0-9.-]+\.test(?::\d+)?/#i', (string) $url ) ) {
			return $args;
		}

		$root   = '/Users/mahesh/Library/Application Support/mkcert/rootCA.pem';
		$core   = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
		$bundle = rtrim( sys_get_temp_dir(), '/' ) . '/content-relay-e2e-ca-bundle-' . md5( $root . filemtime( $core ) ) . '.pem';
		if ( ! is_readable( $bundle ) && is_readable( $root ) ) {
			file_put_contents( $bundle . '.tmp', file_get_contents( $core ) . "\n" . file_get_contents( $root ) );
			rename( $bundle . '.tmp', $bundle );
		}
		if ( is_readable( $bundle ) ) {
			$args['sslcertificates'] = $bundle;
			$args['sslverify']       = true;
		}

		return $args;
	},
	20,
	2
);
