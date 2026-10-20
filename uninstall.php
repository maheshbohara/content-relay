<?php
/**
 * Removes everything Content Relay stored, when the plugin is deleted.
 *
 * Deactivating keeps all data. Deleting the plugin removes, on every site of a network: the relay log table, the
 * plugin's options and transients, the relay identity meta on posts and terms, and `uploads/content-relay/` (staging,
 * outgoing relay state and the rollback backups). Relayed pages, media and terms stay.
 *
 * @package ContentRelay
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes Content Relay's data from the current site.
 */
function content_relay_uninstall_site(): void {
	global $wpdb, $wp_filesystem;

	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'content_relay_nonce_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall removes the plugin's own rows.

	foreach ( array( 'content_relay_receiver_keys', 'content_relay_connections', 'content_relay_switches', 'content_relay_post_types', 'content_relay_db_version' ) as $option ) {
		delete_option( $option );
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall removes the plugin's own rows and table.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_content_relay_' ) . '%', $wpdb->esc_like( '_transient_timeout_content_relay_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->postmeta, $wpdb->esc_like( '_content_relay_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->termmeta, $wpdb->esc_like( '_content_relay_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'content_relay_log' ) );
	// phpcs:enable

	$uploads = wp_get_upload_dir();
	$dir     = trailingslashit( (string) $uploads['basedir'] ) . 'content-relay';
	if ( is_dir( $dir ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() && $wp_filesystem instanceof WP_Filesystem_Base ) {
			$wp_filesystem->delete( $dir, true );
		}
	}

	wp_cache_flush();
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $content_relay_site_id ) {
		switch_to_blog( (int) $content_relay_site_id );
		content_relay_uninstall_site();
		restore_current_blog();
	}
} else {
	content_relay_uninstall_site();
}
