<?php
/**
 * E2E (M2): switches Content Relay sending AND receiving on for a local .test site, for web requests and WP-CLI, and
 * makes WordPress trust the local mkcert CA for Content Relay's own requests (TLS verification stays on).
 *
 * Install: copy into <docroot>/wp-content/mu-plugins/content-relay-e2e-gates.php on both local sites while testing the
 * admin UI; remove it afterwards. Never install on a real site: the gates are switched under Settings → Content Relay there. The constants
 * this defines override those switches and lock them on, so to use the switches themselves, install
 * local-ca-mu-plugin.php (CA trust only) instead of this file.
 *
 * WordPress verifies TLS against wp-includes/certificates/ca-bundle.crt, which lacks the mkcert CA. This builds a
 * bundle of that file plus the mkcert root in the system temp directory and hands it to Content Relay requests only.
 *
 * @package ContentRelay
 */

// phpcs:ignoreFile -- Test harness, not plugin code.

$content_relay_e2e_host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
if ( '' !== $content_relay_e2e_host && '.test' !== substr( $content_relay_e2e_host, -5 ) ) {
	return;
}

if ( ! defined( 'CONTENT_RELAY_SEND' ) ) {
	define( 'CONTENT_RELAY_SEND', true );
}
if ( ! defined( 'CONTENT_RELAY_RECEIVE' ) ) {
	define( 'CONTENT_RELAY_RECEIVE', true );
}

add_filter(
	'content_relay_http_args',
	static function ( $args, $url ) {
		if ( 1 !== preg_match( '#^https://[a-z0-9.-]+\.test(?::\d+)?/#i', (string) $url ) ) {
			return $args;
		}

		$root   = '/Users/mahesh/Library/Application Support/mkcert/rootCA.pem';
		$bundle = rtrim( sys_get_temp_dir(), '/' ) . '/content-relay-e2e-ca-bundle-' . md5( $root . filemtime( ABSPATH . WPINC . '/certificates/ca-bundle.crt' ) ) . '.pem';
		if ( ! is_readable( $bundle ) && is_readable( $root ) ) {
			file_put_contents( $bundle . '.tmp', file_get_contents( ABSPATH . WPINC . '/certificates/ca-bundle.crt' ) . "\n" . file_get_contents( $root ) );
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
