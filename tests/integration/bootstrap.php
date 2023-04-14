<?php
/**
 * PHPUnit bootstrap for the integration suite.
 *
 * Runs a real WordPress — this site's core — against the throwaway database named in wp-tests-config.php, with only
 * Content Relay loaded: no WPML, ACF or theme, so the suite also covers a plain single-language site. Content, uploads and
 * a stand-in theme live in the system temp directory, never in this site's wp-content.
 *
 * The content commit and rollback run their own transactions, which also commit the test framework's per-test
 * transaction, so what one test writes can outlive it. Tests use unique fixtures and never assume an empty site; the
 * framework reinstalls the database at the start of every run.
 *
 * @package ContentRelay
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress's own configuration constants.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Prepares a temporary directory before WordPress loads.

$content_relay_plugin = dirname( __DIR__, 2 );
$content_relay_config = __DIR__ . '/wp-tests-config.php';

// Inside wp-env the database comes from the container's environment, so no local configuration is needed.
if ( false !== getenv( 'WORDPRESS_DB_USER' ) ) {
	$content_relay_config = __DIR__ . '/wp-tests-config-wp-env.php';
}

if ( ! is_readable( $content_relay_config ) ) {
	fwrite( STDERR, "Missing tests/integration/wp-tests-config.php: copy wp-tests-config-sample.php and fill in a database of its own.\n" );
	exit( 1 );
}

$content_relay_content = rtrim( sys_get_temp_dir(), '/' ) . '/content-relay-tests-content';
$content_relay_theme   = $content_relay_content . '/themes/content-relay-test';
if ( ! is_dir( $content_relay_theme ) ) {
	mkdir( $content_relay_theme, 0777, true );
}
file_put_contents( $content_relay_theme . '/style.css', "/*\nTheme Name: Content Relay Test\nVersion: 1.0.0\n*/\n" );
file_put_contents( $content_relay_theme . '/index.php', "<?php\n" );

define( 'WP_TESTS_CONFIG_FILE_PATH', $content_relay_config );
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $content_relay_plugin . '/vendor/yoast/phpunit-polyfills' );
define( 'WP_CONTENT_DIR', $content_relay_content );
define( 'WP_CONTENT_URL', 'http://example.org/wp-content' );
define( 'WP_DEFAULT_THEME', 'content-relay-test' );
define( 'CONTENT_RELAY_ALLOW_HTTP', true );
define( 'CONTENT_RELAY_ENCRYPTION_KEY', 'content-relay-integration-tests-only-0123456789abcdef' );

require_once $content_relay_plugin . '/vendor/autoload.php';
require_once $content_relay_plugin . '/vendor/wp-phpunit/wp-phpunit/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $content_relay_plugin ): void {
		require $content_relay_plugin . '/content-relay.php';
	}
);

require $content_relay_plugin . '/vendor/wp-phpunit/wp-phpunit/includes/bootstrap.php';
require_once __DIR__ . '/class-test-case.php';
