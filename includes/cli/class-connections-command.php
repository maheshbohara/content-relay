<?php
/**
 * WP-CLI: receivers this site relays to.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Cli;

use ContentRelay\Config;
use ContentRelay\Connection\Client;
use ContentRelay\Connection\Connection_Exception;
use ContentRelay\Connection\Connections;
use ContentRelay\Connection\Key_Codec;
use ContentRelay\Connection\Vault;

defined( 'ABSPATH' ) || exit;

/**
 * Adds, tests, lists and removes connections to receiving sites.
 */
final class Connections_Command {

	/**
	 * Adds a connection from a key issued by the receiver (`wp content-relay keys generate` there).
	 *
	 * The key is checked with a handshake before it is stored; its secret half is stored encrypted.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The `contentrelay1.…` key.
	 *
	 * --label=<label>
	 * : Name used with `--to`, e.g. `prod`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp content-relay connections add contentrelay1.eyJ2Ijox… --label=prod
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function add( array $args, array $assoc_args ): void {
		$store = self::store();
		$label = (string) ( $assoc_args['label'] ?? '' );
		if ( ! Connections::is_valid_label( $label ) ) {
			\WP_CLI::error( __( '--label is required: lowercase letters, digits, "-" and "_".', 'content-relay' ) );
		}

		$key = ( new Key_Codec() )->decode( (string) $args[0] );
		if ( null === $key ) {
			\WP_CLI::error( __( 'That is not a valid Content Relay key.', 'content-relay' ) );
			return;
		}
		if ( ! Config::is_allowed_receiver_url( $key['url'] ) ) {
			\WP_CLI::error( __( 'The receiver must use HTTPS (CONTENT_RELAY_ALLOW_HTTP allows http:// for local testing).', 'content-relay' ) );
		}

		$environment = self::handshake(
			new Client(
				array(
					'url'        => $key['url'],
					'kid'        => $key['kid'],
					'secret_key' => $key['secret_key'],
				)
			)
		);

		try {
			$store->add( $label, $key, get_current_user_id() );
		} catch ( Connection_Exception $e ) {
			\WP_CLI::error( $e->getMessage() );
		}

		/* translators: 1: connection label, 2: URL of the receiving site, 3: WordPress version, 4: theme stylesheet, 5: theme version. */
		\WP_CLI::success( sprintf( __( 'Connection "%1$s" added: %2$s (WordPress %3$s, theme %4$s %5$s).', 'content-relay' ), $label, $environment['home_url'] ?? $key['url'], $environment['wp'] ?? '?', $environment['theme']['stylesheet'] ?? '?', $environment['theme']['version'] ?? '?' ) );
	}

	/**
	 * Lists connections.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function list_( array $args, array $assoc_args ): void {
		unset( $args );

		$option = get_option( Connections::OPTION, array() );
		$rows   = array();
		foreach ( is_array( $option ) ? $option : array() as $label => $record ) {
			$user   = get_userdata( (int) ( $record['added_by'] ?? 0 ) );
			$rows[] = array(
				'label'    => $label,
				'url'      => $record['url'] ?? '',
				'kid'      => $record['kid'] ?? '',
				'added_by' => $user instanceof \WP_User ? $user->user_login : '',
				'added_at' => $record['added_at'] ?? '',
			);
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'label', 'url', 'kid', 'added_by', 'added_at' ) );
	}

	/**
	 * Runs a handshake with a receiver and prints its environment.
	 *
	 * ## OPTIONS
	 *
	 * <label>
	 * : Connection label.
	 *
	 * @param array<int, string> $args Positional arguments.
	 */
	public function test( array $args ): void {
		$environment = self::handshake( self::client( (string) $args[0] ) );

		$skew  = (int) ( $environment['server_time'] ?? 0 ) - time();
		$theme = wp_get_theme();
		$rows  = array(
			array(
				'field' => 'home_url',
				'value' => $environment['home_url'] ?? '',
			),
			array(
				'field' => 'plugin / schema',
				'value' => sprintf( '%1$s / %2$s', $environment['plugin'] ?? '?', $environment['schema'] ?? '?' ),
			),
			array(
				'field' => 'php / wp',
				'value' => sprintf( '%1$s / %2$s', $environment['php'] ?? '?', $environment['wp'] ?? '?' ),
			),
			array(
				'field' => 'theme',
				'value' => sprintf( '%1$s %2$s', $environment['theme']['stylesheet'] ?? '?', $environment['theme']['version'] ?? '?' ),
			),
			array(
				'field' => 'language',
				'value' => sprintf( '%1$s (default %2$s; %3$s)', $environment['language']['adapter'] ?? '?', $environment['language']['default'] ?? '-', implode( ', ', (array) ( $environment['language']['active'] ?? array() ) ) ),
			),
			array(
				'field' => 'plugins',
				'value' => (string) wp_json_encode( $environment['plugins'] ?? array() ),
			),
			array(
				'field' => 'max chunk',
				'value' => size_format( (int) ( $environment['limits']['max_chunk_bytes'] ?? 0 ) ),
			),
			array(
				'field' => 'clock difference',
				'value' => sprintf( '%+d s', $skew ),
			),
		);
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );

		if ( abs( $skew ) > 60 ) {
			\WP_CLI::warning( __( 'The clocks of the two sites differ by more than a minute; requests are refused beyond 300 s.', 'content-relay' ) );
		}
		if ( ( $environment['theme']['stylesheet'] ?? '' ) !== get_stylesheet() || (string) ( $environment['theme']['version'] ?? '' ) !== (string) $theme->get( 'Version' ) ) {
			\WP_CLI::warning( __( 'The receiver runs a different theme or theme version; relays will be blocked until it is deployed.', 'content-relay' ) );
		}

		\WP_CLI::success( __( 'Handshake succeeded.', 'content-relay' ) );
	}
}
