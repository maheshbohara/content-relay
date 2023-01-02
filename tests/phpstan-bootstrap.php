<?php
/**
 * Constants PHPStan needs that WordPress or content-relay.php would define at runtime.
 *
 * @package ContentRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Stands in for WordPress core's own constant during static analysis.
}
define( 'CONTENT_RELAY_VERSION', '0.1.0' );
define( 'CONTENT_RELAY_SCHEMA', 1 );
define( 'CONTENT_RELAY_FILE', __DIR__ . '/../content-relay.php' );
define( 'CONTENT_RELAY_DIR', dirname( __DIR__ ) . '/' );
define( 'CONTENT_RELAY_URL', 'https://example.test/wp-content/plugins/content-relay/' );
