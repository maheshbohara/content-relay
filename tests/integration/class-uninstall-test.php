<?php
/**
 * Deleting the plugin.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Tests\Integration;

use ContentRelay\Storage\Log_Table;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Test fixture files.

/**
 * Deleting the plugin removes Content Relay's data and nothing else.
 */
final class Uninstall_Test extends Test_Case {

	/**
	 * Puts the log table back for any test that runs after this one.
	 */
	public function tear_down(): void {
		Log_Table::install();
		parent::tear_down();
	}

	/**
	 * Options, transients, identity meta, the log table and uploads/content-relay go; pages and other meta stay.
	 */
	public function test_removes_plugin_data_but_not_content(): void {
		global $wpdb;

		$page = $this->page();
		update_post_meta( $page, '_content_relay_uuid', wp_generate_uuid4() );
		update_post_meta( $page, 'unrelated_meta', 'kept' );
		$term = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		update_term_meta( $term, '_content_relay_uuid', wp_generate_uuid4() );
		update_option( 'content_relay_connections', array( 'x' => array() ), false );
		set_transient( 'content_relay_nonce_test', 1, 60 );
		( new Log_Table() )->insert(
			array(
				'session'   => wp_generate_uuid4(),
				'direction' => Log_Table::OUT,
				'status'    => Log_Table::STATUS_APPLIED,
			)
		);
		$uploads = (string) wp_get_upload_dir()['basedir'];
		wp_mkdir_p( $uploads . '/content-relay/backups' );
		file_put_contents( $uploads . '/content-relay/backups/test.json.gz', 'x' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'content-relay/content-relay.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core's uninstall guard.
		}

		// The test framework turns DROP TABLE into DROP TEMPORARY TABLE; let uninstall drop the real table.
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );

		include CONTENT_RELAY_DIR . 'uninstall.php';

		$this->assertFalse( get_option( 'content_relay_connections' ) );
		$this->assertFalse( get_option( 'content_relay_switches' ) );
		$this->assertFalse( get_transient( 'content_relay_nonce_test' ) );
		$this->assertSame( '', get_post_meta( $page, '_content_relay_uuid', true ) );
		$this->assertSame( '', get_term_meta( $term, '_content_relay_uuid', true ) );
		$this->assertSame( 'kept', get_post_meta( $page, 'unrelated_meta', true ) );
		$this->assertInstanceOf( \WP_Post::class, get_post( $page ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Log_Table::table() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Checks the table is gone.
		$this->assertDirectoryDoesNotExist( $uploads . '/content-relay' );
	}
}
