<?php
/**
 * Plugin bootstrap: wires the components that this site's send/receive switches enable.
 *
 * @package ContentRelay
 */

namespace ContentRelay;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point called from content-relay.php.
 */
final class Plugin {

	/**
	 * Meta keys Content Relay owns. They are never copied by duplication or translation plugins.
	 */
	public const OWN_META_KEYS = array(
		'_content_relay_uuid',
		'_content_relay_sync',
		'_content_relay_pending',
		'_content_relay_superseded_by',
	);

	/**
	 * Runs on plugins_loaded.
	 */
	public static function boot(): void {
		add_filter( 'duplicate_post_excludelist_filter', array( __CLASS__, 'exclude_own_meta' ) );

		Storage\Log_Table::maybe_upgrade();

		if ( Config::can_receive() ) {
			Connection\Rest_Controller::register();
		}
		if ( Config::can_send() ) {
			Admin\Rest_Controller::register();
		}
		if ( is_admin() ) {
			Admin\Admin::register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'content-relay', Cli\Command::class );
			\WP_CLI::add_command( 'content-relay plan', array( Cli\Receive_Command::class, 'plan' ) );
			\WP_CLI::add_command( 'content-relay apply', array( Cli\Receive_Command::class, 'apply' ) );
			\WP_CLI::add_command( 'content-relay rollback', array( Cli\Receive_Command::class, 'rollback' ) );
			\WP_CLI::add_command( 'content-relay keys', Cli\Keys_Command::class );
			\WP_CLI::add_command( 'content-relay connections', Cli\Connections_Command::class );
			\WP_CLI::add_command( 'content-relay push', Cli\Push_Command::class );
			\WP_CLI::add_command( 'content-relay push-many', Cli\Push_Many_Command::class );
			\WP_CLI::add_command( 'content-relay history', Cli\History_Command::class );
			\WP_CLI::add_command( 'content-relay verify', Cli\Verify_Command::class );
		}
	}

	/**
	 * Keeps Duplicate Post from copying a relay identity onto a duplicate.
	 *
	 * @param mixed $keys Meta keys Duplicate Post skips.
	 * @return array<int, string>
	 */
	public static function exclude_own_meta( $keys ): array {
		return array_values( array_unique( array_merge( is_array( $keys ) ? $keys : array(), self::OWN_META_KEYS ) ) );
	}

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void {
		Storage\Log_Table::install();
	}
}
