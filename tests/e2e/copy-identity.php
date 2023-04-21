<?php
/**
 * E2E scenario 14: copies of a relayed page must not inherit its relay identity.
 *
 * Run on the RECEIVER (or any site holding a relayed page): wp eval-file …/tests/e2e/copy-identity.php <page-id>
 *
 * 1. Duplicate Post copy (with Duplicate Post's own meta copying loaded, as in wp-admin).
 * 2. A WPML duplicate of that copy into French (WPML copies custom fields unless wpml-config.xml says ignore).
 * Each copy is checked for _content_relay_uuid / _content_relay_sync, with a control meta key that must be copied (proof the
 * copy actually carried meta). Both copies are deleted afterwards.
 *
 * @package ContentRelay
 */

// phpcs:ignoreFile -- Test harness, not plugin code.

global $sitepress;

$source = get_post( (int) ( $args[0] ?? 0 ) );
if ( ! $source instanceof WP_Post || '' === (string) get_post_meta( $source->ID, '_content_relay_uuid', true ) ) {
	WP_CLI::error( 'Pass the id of a relayed page (it must carry _content_relay_uuid).' );
}

$control_keys = array_values(
	array_filter(
		array_keys( get_post_meta( $source->ID ) ),
		static function ( $key ) {
			return 0 !== strpos( $key, '_content_relay_' ) && ! in_array( $key, array( '_edit_lock', '_edit_last', '_wp_old_slug' ), true );
		}
	)
);
WP_CLI::log( sprintf( 'Source %d: %d meta keys besides Content Relay\'s (e.g. %s)', $source->ID, count( $control_keys ), implode( ', ', array_slice( $control_keys, 0, 3 ) ) ) );

$report = static function ( string $what, int $id ) use ( $control_keys ): bool {
	$uuid    = get_post_meta( $id, '_content_relay_uuid', true );
	$sync    = get_post_meta( $id, '_content_relay_sync', true );
	$copied  = count( array_intersect( $control_keys, array_keys( get_post_meta( $id ) ) ) );
	$pass    = '' === $uuid && '' === $sync && $copied > 0;
	WP_CLI::log( sprintf( '%-4s %s (post %d): _content_relay_uuid=%s _content_relay_sync=%s, other meta copied=%d', $pass ? 'PASS' : 'FAIL', $what, $id, '' === $uuid ? 'absent' : 'PRESENT', '' === $sync ? 'absent' : 'PRESENT', $copied ) );
	return $pass;
};

$created = array();

$admin_functions = WP_PLUGIN_DIR . '/duplicate-post/admin-functions.php';
if ( ! function_exists( 'duplicate_post_create_duplicate' ) && is_readable( $admin_functions ) ) {
	require_once $admin_functions;
	if ( function_exists( 'duplicate_post_admin_init' ) ) {
		duplicate_post_admin_init();
	}
}
if ( ! function_exists( 'duplicate_post_create_duplicate' ) ) {
	WP_CLI::error( 'Duplicate Post is not available.' );
}

$dup_id = (int) duplicate_post_create_duplicate( $source, 'draft' );
if ( $dup_id <= 0 ) {
	WP_CLI::error( 'Duplicate Post did not create a copy.' );
}
$created[] = $dup_id;
$report( 'Duplicate Post copy', $dup_id );

if ( $sitepress && method_exists( $sitepress, 'make_duplicate' ) ) {
	$languages = array_diff( array_keys( (array) apply_filters( 'wpml_active_languages', array(), array( 'skip_missing' => 0 ) ) ), array( apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $dup_id, 'element_type' => 'post_' . $source->post_type ) ) ) );
	$language  = reset( $languages );
	$fr_id     = (int) $sitepress->make_duplicate( $dup_id, $language );
	if ( $fr_id > 0 ) {
		$created[] = $fr_id;
		$report( sprintf( 'WPML duplicate into %s', $language ), $fr_id );
	} else {
		WP_CLI::warning( 'WPML did not create a duplicate.' );
	}
}

foreach ( array_reverse( $created ) as $id ) {
	wp_delete_post( $id, true );
}
WP_CLI::log( sprintf( 'Deleted copies: %s', implode( ', ', $created ) ) );
