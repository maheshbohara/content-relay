<?php
/**
 * Integration suite configuration for wp-env (`npm run test:integration`), read from the container's environment.
 *
 * ⚠ Every table in DB_NAME is dropped at the start of each run, so the suite has a database of its own.
 *
 * @package ContentRelay
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- WordPress's own configuration constants and globals.

/* WordPress core to test against: the site this plugin is installed in (plugin → plugins → wp-content → root). */
define( 'ABSPATH', dirname( __DIR__, 5 ) . '/' );

define( 'DB_NAME', 'content_relay_tests' );
define( 'DB_USER', (string) getenv( 'WORDPRESS_DB_USER' ) );
define( 'DB_PASSWORD', (string) getenv( 'WORDPRESS_DB_PASSWORD' ) );
define( 'DB_HOST', false === getenv( 'WORDPRESS_DB_HOST' ) ? 'mysql' : (string) getenv( 'WORDPRESS_DB_HOST' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This file is the configuration WordPress reads it from.

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Content Relay Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );
